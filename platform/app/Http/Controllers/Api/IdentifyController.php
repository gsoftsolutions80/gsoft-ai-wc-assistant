<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdentifyController extends Controller
{
    public function __invoke(Request $request, IdentityResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'anon_id' => ['required', 'uuid'],
            'wc_user_id' => ['required_without:email_sha256', 'nullable', 'integer', 'min:1'],
            'email_sha256' => ['required_without:wc_user_id', 'nullable', 'regex:/^[a-fA-F0-9]{64}$/'],
            'source' => ['required', Rule::in(['login', 'checkout', 'newsletter', 'cookie'])],
            'consent_tracking' => ['nullable', 'boolean'],
            'consent_cross_site' => ['nullable', 'boolean'],
        ]);

        $customer = $resolver->identify(
            $request->attributes->get('site'),
            $data['anon_id'],
            $data['wc_user_id'] ?? null,
            $data['email_sha256'] ?? null,
            $data['source'],
            $data['consent_tracking'] ?? null,
            $data['consent_cross_site'] ?? null,
        );

        return response()->json(['customer_id' => $customer->id]);
    }
}
