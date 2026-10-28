<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Event;
use App\Models\GlobalIdentity;
use App\Models\IdentityLink;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Risolve e unifica le identita': cookie anonimo -> cliente -> identita' globale (cross-sito, solo con consenso).
 * Tutti i metodi ricevono il Site esplicitamente e filtrano per site_id, quindi funzionano anche
 * fuori da una richiesta (job/comandi) dove il tenant scope non e' attivo.
 */
class IdentityResolver
{
    private const CONFIDENCE = ['login' => 100, 'checkout' => 90, 'newsletter' => 70, 'cookie' => 30];

    /** Il plugin invia sha256(email normalizzata); qui si applica un HMAC con sale di piattaforma. */
    public function hashEmail(string $emailSha256): string
    {
        $salt = config('gsai.email_salt');

        if (! is_string($salt) || $salt === '') {
            throw new RuntimeException('GSAI_EMAIL_SALT non configurato.');
        }

        return hash_hmac('sha256', strtolower($emailSha256), $salt);
    }

    public function findOrCreateVisitor(Site $site, string $anonId): Visitor
    {
        $anonId = strtolower($anonId);
        $find = fn () => Visitor::where('site_id', $site->id)->where('anon_id', $anonId)->first();

        if ($visitor = $find()) {
            return $visitor;
        }

        try {
            return Visitor::create([
                'site_id' => $site->id,
                'anon_id' => $anonId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /** Collega il visitatore a un cliente (creandolo o unendo i duplicati) e riattribuisce i suoi eventi anonimi. */
    public function identify(
        Site $site,
        string $anonId,
        ?int $wcUserId,
        ?string $emailSha256,
        string $source,
        ?bool $consentTracking = null,
        ?bool $consentCrossSite = null,
    ): Customer {
        $visitor = $this->findOrCreateVisitor($site, $anonId);
        $emailHash = $emailSha256 ? $this->hashEmail($emailSha256) : null;

        return DB::transaction(function () use ($site, $visitor, $wcUserId, $emailHash, $source, $consentTracking, $consentCrossSite) {
            $customer = $this->resolveCustomer($site, $wcUserId, $emailHash)
                ?? throw new InvalidArgumentException('Serve almeno wc_user_id o email.');

            $this->applyConsent($customer, $consentTracking, $consentCrossSite);
            $this->link($site, $visitor, $customer, $source);

            return $customer->refresh();
        });
    }

    /**
     * Trova o crea il cliente per (wc_user_id, email_hash).
     * - un utente registrato adotta il cliente "ospite" con la stessa email;
     * - i clienti ospiti duplicati con la stessa email vengono uniti nel sopravvissuto;
     * - due utenti registrati diversi con la stessa email NON vengono mai uniti.
     */
    public function resolveCustomer(Site $site, ?int $wcUserId, ?string $emailHash): ?Customer
    {
        if (! $wcUserId && ! $emailHash) {
            return null;
        }

        return DB::transaction(function () use ($site, $wcUserId, $emailHash) {
            $active = fn () => Customer::where('site_id', $site->id)->whereNull('merged_into_id');
            $byEmail = $emailHash
                ? $active()->where('email_hash', $emailHash)->orderBy('id')->get()
                : collect();

            if ($wcUserId) {
                $customer = $active()->where('wc_user_id', $wcUserId)->first();

                if (! $customer) {
                    $customer = $byEmail->first(fn (Customer $c) => $c->wc_user_id === null);

                    if ($customer) {
                        $customer->wc_user_id = $wcUserId;
                    } else {
                        $customer = new Customer(['site_id' => $site->id, 'wc_user_id' => $wcUserId, 'first_seen_at' => now()]);
                    }
                }
            } else {
                $customer = $byEmail->first(fn (Customer $c) => $c->wc_user_id !== null)
                    ?? $byEmail->first()
                    ?? new Customer(['site_id' => $site->id, 'first_seen_at' => now()]);
            }

            if ($emailHash) {
                $customer->email_hash = $emailHash;
            }
            $customer->last_seen_at = now();
            $customer->save();

            foreach ($byEmail as $other) {
                if ($other->id !== $customer->id && $other->wc_user_id === null) {
                    $this->merge($other, $customer);
                }
            }

            return $customer;
        });
    }

    /** Sposta eventi, visitatori e link di $from su $to; $from resta come tombstone (merged_into_id). */
    public function merge(Customer $from, Customer $to): void
    {
        DB::transaction(function () use ($from, $to) {
            Event::where('site_id', $to->site_id)->where('customer_id', $from->id)->update(['customer_id' => $to->id]);
            Visitor::where('site_id', $to->site_id)->where('customer_id', $from->id)->update(['customer_id' => $to->id]);

            IdentityLink::where('site_id', $to->site_id)->where('customer_id', $from->id)->get()
                ->each(function (IdentityLink $link) use ($to) {
                    $exists = IdentityLink::where('visitor_id', $link->visitor_id)->where('customer_id', $to->id)->exists();
                    $exists ? $link->delete() : $link->update(['customer_id' => $to->id]);
                });

            if ($from->first_seen_at && (! $to->first_seen_at || $from->first_seen_at->lt($to->first_seen_at))) {
                $to->first_seen_at = $from->first_seen_at;
                $to->save();
            }

            $from->forceFill(['merged_into_id' => $to->id, 'email_hash' => null, 'global_identity_id' => null])->save();
        });
    }

    private function applyConsent(Customer $customer, ?bool $tracking, ?bool $crossSite): void
    {
        if ($tracking !== null) {
            $customer->consent_tracking = $tracking;
        }
        if ($crossSite !== null) {
            $customer->consent_cross_site = $crossSite;
        }

        // L'identita' globale esiste solo con consenso cross-sito esplicito + tracking attivo.
        $allowed = $customer->consent_cross_site && $customer->consent_tracking && $customer->email_hash;
        $customer->global_identity_id = $allowed
            ? GlobalIdentity::firstOrCreate(['email_hash' => $customer->email_hash])->id
            : null;

        $customer->save();
    }

    private function link(Site $site, Visitor $visitor, Customer $customer, string $source): void
    {
        $confidence = self::CONFIDENCE[$source] ?? 30;
        $link = IdentityLink::firstOrNew(['visitor_id' => $visitor->id, 'customer_id' => $customer->id]);

        if (! $link->exists || $confidence > $link->confidence) {
            $link->site_id = $site->id;
            $link->source = $source;
            $link->confidence = $confidence;
            $link->save();
        }

        $visitor->forceFill(['customer_id' => $customer->id, 'last_seen_at' => now()])->save();

        Event::where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
    }
}
