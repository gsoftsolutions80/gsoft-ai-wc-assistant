<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Support\Carbon;

class EventIngestor
{
    public function __construct(private IdentityResolver $identity) {}

    /**
     * @param  list<array<string, mixed>>  $events  eventi gia' validati
     * @return array{accepted: int, duplicates: int}
     */
    public function ingest(Site $site, array $events): array
    {
        $now = now();
        $visitors = [];
        $customers = [];
        $rows = [];

        foreach ($events as $event) {
            $visitor = null;
            $customerId = null;

            if (! empty($event['anon_id'])) {
                $visitor = $visitors[$event['anon_id']] ??= $this->identity->findOrCreateVisitor($site, $event['anon_id']);
                $customerId = $visitor->customer_id;
            }

            // Un'identita' esplicita (es. storico ordini) ha la precedenza sul cookie.
            if (! empty($event['identity'])) {
                $wcUserId = $event['identity']['wc_user_id'] ?? null;
                $emailHash = ! empty($event['identity']['email_sha256'])
                    ? $this->identity->hashEmail($event['identity']['email_sha256'])
                    : null;
                $key = ($wcUserId ?? '').'|'.($emailHash ?? '');

                if (! array_key_exists($key, $customers)) {
                    $customers[$key] = $this->identity->resolveCustomer($site, $wcUserId, $emailHash)?->id;
                }
                $customerId = $customers[$key] ?? $customerId;
            }

            $payload = $event['payload'] ?? [];

            $rows[] = [
                'site_id' => $site->id,
                'event_id' => $event['event_id'],
                'visitor_id' => $visitor?->id,
                'customer_id' => $customerId,
                'type' => $event['type'],
                'wc_product_id' => isset($payload['product_id']) ? (int) $payload['product_id'] : null,
                'occurred_at' => Carbon::createFromTimestampUTC(min((int) $event['occurred_at'], $now->timestamp))->toDateTimeString(),
                'payload' => $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_at' => $now->toDateTimeString(),
            ];
        }

        $accepted = $rows === [] ? 0 : Event::insertOrIgnore($rows);

        if ($visitors !== []) {
            Visitor::where('site_id', $site->id)
                ->whereIn('id', array_map(fn (Visitor $v) => $v->id, $visitors))
                ->update(['last_seen_at' => $now]);
        }

        return ['accepted' => $accepted, 'duplicates' => count($rows) - $accepted];
    }
}
