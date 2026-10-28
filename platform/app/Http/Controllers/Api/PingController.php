<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $site = $request->attributes->get('site');

        return response()->json([
            'ok' => true,
            'site' => $site->name,
            'domain' => $site->domain,
            'plan' => $site->plan,
        ]);
    }
}
