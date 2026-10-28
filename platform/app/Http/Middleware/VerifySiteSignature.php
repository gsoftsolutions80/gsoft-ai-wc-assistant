<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica le richieste del plugin con HMAC-SHA256.
 *
 * Headers: X-GSAI-Key (site_key), X-GSAI-Timestamp (unix), X-GSAI-Nonce (univoco),
 * X-GSAI-Signature = hex(hmac_sha256(site_secret, "{timestamp}.{nonce}.{body}")).
 */
class VerifySiteSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('X-GSAI-Key');
        $timestamp = (string) $request->header('X-GSAI-Timestamp');
        $nonce = (string) $request->header('X-GSAI-Nonce');
        $signature = strtolower((string) $request->header('X-GSAI-Signature'));

        if ($key === '' || $nonce === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->deny('missing_credentials');
        }

        $tolerance = config('gsai.signature_tolerance');

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->deny('stale_timestamp');
        }

        $site = Site::where('site_key', $key)->where('status', Site::STATUS_ACTIVE)->first();

        if (! $site) {
            return $this->deny('invalid_credentials');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->getContent(), $site->site_secret);

        if (! hash_equals($expected, $signature)) {
            return $this->deny('invalid_credentials');
        }

        // Il nonce e' consumabile una sola volta per sito entro la finestra di tolleranza.
        if (! Cache::add("gsai:nonce:{$site->id}:{$nonce}", 1, $tolerance * 2)) {
            return $this->deny('replayed_request');
        }

        app(CurrentSite::class)->set($site);
        $request->attributes->set('site', $site);

        return $next($request);
    }

    /** Il tenant vale solo per la richiesta corrente (evita perdite di contesto con worker persistenti e nei test). */
    public function terminate(Request $request, Response $response): void
    {
        app(CurrentSite::class)->set(null);
    }

    private function deny(string $reason): JsonResponse
    {
        return response()->json(['error' => $reason], 401);
    }
}
