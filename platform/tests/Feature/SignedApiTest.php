<?php

namespace Tests\Feature;

use Tests\TestCase;

class SignedApiTest extends TestCase
{
    public function test_valid_signature_is_accepted(): void
    {
        $site = $this->makeSite('Negozio', 'negozio.test');

        $this->signedJson($site, 'GET', '/api/v1/ping')
            ->assertOk()
            ->assertJson(['ok' => true, 'site' => 'Negozio', 'domain' => 'negozio.test']);
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertStatus(401)
            ->assertJson(['error' => 'missing_credentials']);
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'GET', '/api/v1/ping', [], ['signature' => str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_credentials']);
    }

    public function test_unknown_key_is_rejected(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'GET', '/api/v1/ping', [], ['key' => 'gsk_unknown'])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_credentials']);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'GET', '/api/v1/ping', [], ['timestamp' => (string) (time() - 3600)])
            ->assertStatus(401)
            ->assertJson(['error' => 'stale_timestamp']);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $site = $this->makeSite();

        $this->signedJson($site, 'GET', '/api/v1/ping', [], ['nonce' => 'same-nonce'])->assertOk();
        $this->signedJson($site, 'GET', '/api/v1/ping', [], ['nonce' => 'same-nonce'])
            ->assertStatus(401)
            ->assertJson(['error' => 'replayed_request']);
    }

    public function test_suspended_site_is_rejected(): void
    {
        $site = $this->makeSite();
        $site->update(['status' => 'suspended']);

        $this->signedJson($site, 'GET', '/api/v1/ping')->assertStatus(401);
    }

    public function test_secret_is_stored_encrypted(): void
    {
        $site = $this->makeSite();

        $raw = \DB::table('sites')->where('id', $site->id)->value('site_secret');

        $this->assertNotSame($site->site_secret, $raw);
    }
}
