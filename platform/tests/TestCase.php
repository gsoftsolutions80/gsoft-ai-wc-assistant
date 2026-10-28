<?php

namespace Tests;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function makeSite(string $name = 'Shop', string $domain = 'shop.test'): Site
    {
        return Site::register($name, $domain);
    }

    /** Esegue una richiesta firmata come farebbe il plugin. */
    protected function signedJson(Site $site, string $method, string $uri, array $data = [], array $override = [])
    {
        $body = $data === [] ? '' : json_encode($data);
        $timestamp = $override['timestamp'] ?? (string) time();
        $nonce = $override['nonce'] ?? bin2hex(random_bytes(8));
        $signature = $override['signature']
            ?? hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $site->site_secret);

        return $this->call(
            $method,
            $uri,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GSAI_KEY' => $override['key'] ?? $site->site_key,
                'HTTP_X_GSAI_TIMESTAMP' => $timestamp,
                'HTTP_X_GSAI_NONCE' => $nonce,
                'HTTP_X_GSAI_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
