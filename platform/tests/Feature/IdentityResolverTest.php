<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\GlobalIdentity;
use App\Models\IdentityLink;
use App\Models\Visitor;
use App\Services\EventIngestor;
use App\Services\IdentityResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityResolverTest extends TestCase
{
    private IdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(IdentityResolver::class);
    }

    private function anon(): string
    {
        return (string) Str::uuid();
    }

    private function email(string $email = 'mario@example.com'): string
    {
        return hash('sha256', $email);
    }

    public function test_anonymous_events_are_attributed_after_login(): void
    {
        $site = $this->makeSite();
        $anon = $this->anon();

        app(EventIngestor::class)->ingest($site, [[
            'event_id' => 'e1', 'type' => 'product_view', 'anon_id' => $anon,
            'occurred_at' => time(), 'payload' => ['product_id' => 10],
        ]]);
        $this->assertNull(Event::first()->customer_id);

        $customer = $this->resolver->identify($site, $anon, 7, null, 'login', true, false);

        $this->assertSame($customer->id, Event::first()->customer_id);
        $this->assertSame($customer->id, Visitor::first()->customer_id);
    }

    public function test_second_device_resolves_to_same_customer(): void
    {
        $site = $this->makeSite();

        $a = $this->resolver->identify($site, $this->anon(), 7, $this->email(), 'login', true);
        $b = $this->resolver->identify($site, $this->anon(), 7, $this->email(), 'login', true);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Visitor::count());
    }

    public function test_identify_is_idempotent(): void
    {
        $site = $this->makeSite();
        $anon = $this->anon();

        $this->resolver->identify($site, $anon, 7, null, 'login', true);
        $this->resolver->identify($site, $anon, 7, null, 'login', true);

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Visitor::count());
        $this->assertSame(1, IdentityLink::count());
    }

    public function test_guest_customer_is_merged_into_registered_user(): void
    {
        $site = $this->makeSite();
        $guestAnon = $this->anon();

        app(EventIngestor::class)->ingest($site, [[
            'event_id' => 'g1', 'type' => 'add_to_cart', 'anon_id' => $guestAnon, 'occurred_at' => time(),
        ]]);
        $guest = $this->resolver->identify($site, $guestAnon, null, $this->email(), 'checkout', true);
        $this->assertNull($guest->wc_user_id);

        $registered = $this->resolver->identify($site, $this->anon(), 9, $this->email(), 'login', true);

        $this->assertSame($guest->id, $registered->id, 'Il cliente ospite viene adottato dall\'utente registrato');
        $this->assertSame(9, $registered->fresh()->wc_user_id);
        $this->assertSame(1, Customer::whereNull('merged_into_id')->count());
        $this->assertSame($registered->id, Event::first()->customer_id);
    }

    public function test_separate_guests_with_same_email_are_merged_on_registration(): void
    {
        $site = $this->makeSite();
        $first = Customer::create(['site_id' => $site->id, 'email_hash' => $this->resolver->hashEmail($this->email())]);
        $second = Customer::create(['site_id' => $site->id, 'email_hash' => $this->resolver->hashEmail($this->email())]);
        Event::create([
            'site_id' => $site->id, 'event_id' => 'x', 'customer_id' => $second->id,
            'type' => 'product_view', 'occurred_at' => now(),
        ]);

        $survivor = $this->resolver->identify($site, $this->anon(), 3, $this->email(), 'login', true);

        $this->assertSame($first->id, $survivor->id);
        $this->assertSame($first->id, $second->fresh()->merged_into_id);
        $this->assertSame($first->id, Event::first()->customer_id);
        $this->assertSame(1, Customer::whereNull('merged_into_id')->count());
    }

    public function test_different_registered_users_with_same_email_are_not_merged(): void
    {
        $site = $this->makeSite();

        $a = $this->resolver->identify($site, $this->anon(), 1, $this->email(), 'login', true);
        $b = $this->resolver->identify($site, $this->anon(), 2, $this->email(), 'login', true);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Customer::whereNull('merged_into_id')->count());
    }

    public function test_cross_site_identity_requires_consent_and_can_be_revoked(): void
    {
        $siteA = $this->makeSite('A', 'a.test');
        $siteB = $this->makeSite('B', 'b.test');

        $a = $this->resolver->identify($siteA, $this->anon(), 1, $this->email(), 'login', true, true);
        $b = $this->resolver->identify($siteB, $this->anon(), 5, $this->email(), 'login', true, true);

        $this->assertNotNull($a->global_identity_id);
        $this->assertSame($a->global_identity_id, $b->global_identity_id);
        $this->assertSame(1, GlobalIdentity::count());

        $revoked = $this->resolver->identify($siteA, $this->anon(), 1, $this->email(), 'login', true, false);
        $this->assertNull($revoked->global_identity_id);
        $this->assertNotNull($b->fresh()->global_identity_id);
    }

    public function test_no_global_identity_without_consent(): void
    {
        $site = $this->makeSite();

        $customer = $this->resolver->identify($site, $this->anon(), 1, $this->email(), 'login', true, false);

        $this->assertNull($customer->global_identity_id);
        $this->assertSame(0, GlobalIdentity::count());
    }

    public function test_same_wc_user_id_on_different_sites_stays_separate(): void
    {
        $a = $this->resolver->identify($this->makeSite('A', 'a.test'), $this->anon(), 1, null, 'login', true);
        $b = $this->resolver->identify($this->makeSite('B', 'b.test'), $this->anon(), 1, null, 'login', true);

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_email_is_stored_only_as_salted_hash(): void
    {
        $site = $this->makeSite();

        $customer = $this->resolver->identify($site, $this->anon(), 1, $this->email(), 'login', true);

        $this->assertNotSame($this->email(), $customer->email_hash);
        $this->assertSame($this->resolver->hashEmail($this->email()), $customer->email_hash);
    }
}
