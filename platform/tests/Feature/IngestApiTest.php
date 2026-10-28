<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    private function event(string $id, array $overrides = []): array
    {
        return array_merge([
            'event_id' => $id,
            'type' => 'product_view',
            'anon_id' => (string) Str::uuid(),
            'occurred_at' => time(),
            'payload' => ['product_id' => 42],
        ], $overrides);
    }

    public function test_events_are_stored_and_resending_is_idempotent(): void
    {
        $site = $this->makeSite();
        $batch = ['events' => [$this->event('a'), $this->event('b')]];

        $this->signedJson($site, 'POST', '/api/v1/events', $batch)
            ->assertOk()->assertJson(['accepted' => 2, 'duplicates' => 0]);

        $this->signedJson($site, 'POST', '/api/v1/events', $batch)
            ->assertOk()->assertJson(['accepted' => 0, 'duplicates' => 2]);

        $this->assertSame(2, Event::count());
        $this->assertSame(42, Event::first()->wc_product_id);
    }

    public function test_same_event_id_is_allowed_on_different_sites(): void
    {
        $a = $this->makeSite('A', 'a.test');
        $b = $this->makeSite('B', 'b.test');

        $this->signedJson($a, 'POST', '/api/v1/events', ['events' => [$this->event('same')]])->assertOk();
        $this->signedJson($b, 'POST', '/api/v1/events', ['events' => [$this->event('same')]])->assertOk();

        $this->assertSame(2, Event::count());
    }

    public function test_event_without_anon_id_or_identity_is_rejected(): void
    {
        $site = $this->makeSite();
        $event = $this->event('a');
        unset($event['anon_id']);

        $this->signedJson($site, 'POST', '/api/v1/events', ['events' => [$event]])->assertStatus(422);
    }

    public function test_unknown_event_type_is_rejected(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'POST', '/api/v1/events', ['events' => [$this->event('a', ['type' => 'hack'])]])
            ->assertStatus(422);
    }

    public function test_batch_over_limit_is_rejected(): void
    {
        $site = $this->makeSite();
        $events = array_map(fn ($i) => $this->event("e{$i}"), range(1, 101));

        $this->signedJson($site, 'POST', '/api/v1/events', ['events' => $events])->assertStatus(422);
    }

    public function test_identity_only_event_creates_customer_for_backfill(): void
    {
        $site = $this->makeSite();
        $event = $this->event('bf-1', [
            'type' => 'order_placed',
            'identity' => ['wc_user_id' => 12, 'email_sha256' => hash('sha256', 'a@b.it')],
            'payload' => ['order_id' => 5, 'historical' => true],
        ]);
        unset($event['anon_id']);

        $this->signedJson($site, 'POST', '/api/v1/events', ['events' => [$event]])->assertOk();

        $customer = Customer::first();
        $this->assertSame(12, $customer->wc_user_id);
        $this->assertSame($customer->id, Event::first()->customer_id);
        $this->assertNull(Event::first()->visitor_id);
    }

    public function test_identify_attributes_previous_anonymous_events(): void
    {
        $site = $this->makeSite();
        $anon = (string) Str::uuid();

        $this->signedJson($site, 'POST', '/api/v1/events', ['events' => [$this->event('a', ['anon_id' => $anon])]])->assertOk();
        $response = $this->signedJson($site, 'POST', '/api/v1/identify', [
            'anon_id' => $anon, 'wc_user_id' => 3, 'source' => 'login', 'consent_tracking' => true,
        ])->assertOk();

        $this->assertSame($response->json('customer_id'), Event::first()->customer_id);
    }

    public function test_identify_requires_user_or_email(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'POST', '/api/v1/identify', ['anon_id' => (string) Str::uuid(), 'source' => 'login'])
            ->assertStatus(422);
    }

    public function test_catalog_sync_upserts_and_deletes(): void
    {
        $site = $this->makeSite();
        $product = [
            'wc_product_id' => 1, 'name' => 'Scarpa', 'price' => 19.9, 'currency' => 'EUR',
            'categories' => [['id' => 3, 'name' => 'Scarpe', 'slug' => 'scarpe']],
        ];

        $this->signedJson($site, 'POST', '/api/v1/catalog/sync', [
            'products' => [$product, ['wc_product_id' => 2, 'name' => 'Giacca']],
        ])->assertOk()->assertJson(['upserted' => 2, 'deleted' => 0]);
        $this->assertSame(2, Product::count());

        $this->signedJson($site, 'POST', '/api/v1/catalog/sync', [
            'products' => [array_merge($product, ['price' => 24.5])],
        ])->assertOk();
        $this->assertSame(2, Product::count());
        $this->assertSame('24.50', Product::where('wc_product_id', 1)->first()->price);
        $this->assertSame('Scarpe', Product::where('wc_product_id', 1)->first()->categories[0]['name']);

        $this->signedJson($site, 'POST', '/api/v1/catalog/sync', ['deleted' => [2]])
            ->assertOk()->assertJson(['deleted' => 1]);
        $this->assertSame(1, Product::count());
    }

    public function test_catalog_is_isolated_between_sites(): void
    {
        $a = $this->makeSite('A', 'a.test');
        $b = $this->makeSite('B', 'b.test');

        $this->signedJson($a, 'POST', '/api/v1/catalog/sync', ['products' => [['wc_product_id' => 1, 'name' => 'X']]])->assertOk();
        $this->signedJson($b, 'POST', '/api/v1/catalog/sync', ['products' => [['wc_product_id' => 1, 'name' => 'Y']]])->assertOk();
        $this->signedJson($b, 'POST', '/api/v1/catalog/sync', ['deleted' => [1]])->assertJson(['deleted' => 1]);

        $this->assertSame(1, Product::withoutGlobalScopes()->where('site_id', $a->id)->count());
    }
}
