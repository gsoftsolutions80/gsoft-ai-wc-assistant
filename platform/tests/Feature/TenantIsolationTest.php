<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GlobalIdentity;
use App\Support\CurrentSite;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_queries_are_scoped_to_current_site(): void
    {
        $a = $this->makeSite('A', 'a.test');
        $b = $this->makeSite('B', 'b.test');

        Customer::create(['site_id' => $a->id, 'wc_user_id' => 1]);
        Customer::create(['site_id' => $b->id, 'wc_user_id' => 1]);
        Customer::create(['site_id' => $b->id, 'wc_user_id' => 2]);

        app(CurrentSite::class)->set($a);
        $this->assertSame(1, Customer::count());

        app(CurrentSite::class)->set($b);
        $this->assertSame(2, Customer::count());

        app(CurrentSite::class)->set(null);
        $this->assertSame(3, Customer::count());
    }

    public function test_site_id_is_assigned_automatically_on_create(): void
    {
        $a = $this->makeSite('A', 'a.test');
        app(CurrentSite::class)->set($a);

        $customer = Customer::create(['wc_user_id' => 10]);

        $this->assertSame($a->id, $customer->site_id);
    }

    public function test_same_wc_user_id_is_allowed_on_different_sites(): void
    {
        $a = $this->makeSite('A', 'a.test');
        $b = $this->makeSite('B', 'b.test');

        Customer::create(['site_id' => $a->id, 'wc_user_id' => 5]);
        Customer::create(['site_id' => $b->id, 'wc_user_id' => 5]);

        $this->assertSame(2, Customer::withoutGlobalScopes()->where('wc_user_id', 5)->count());
    }

    public function test_global_identity_links_customers_across_sites(): void
    {
        $a = $this->makeSite('A', 'a.test');
        $b = $this->makeSite('B', 'b.test');
        $identity = GlobalIdentity::create(['email_hash' => str_repeat('f', 64)]);

        Customer::create(['site_id' => $a->id, 'global_identity_id' => $identity->id, 'consent_cross_site' => true]);
        Customer::create(['site_id' => $b->id, 'global_identity_id' => $identity->id, 'consent_cross_site' => true]);

        app(CurrentSite::class)->set($a);

        $this->assertSame(2, $identity->customers()->count());
    }
}
