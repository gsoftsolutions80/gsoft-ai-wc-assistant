<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventIngestor;
use App\Support\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function store(Request $request, EventIngestor $ingestor): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_id' => ['required', 'string', 'max:64'],
            'events.*.type' => ['required', Rule::in(EventType::all())],
            'events.*.occurred_at' => ['required', 'integer', 'min:0'],
            'events.*.anon_id' => ['required_without:events.*.identity', 'nullable', 'uuid'],
            'events.*.identity' => ['nullable', 'array'],
            'events.*.identity.wc_user_id' => ['nullable', 'integer', 'min:1'],
            'events.*.identity.email_sha256' => ['nullable', 'regex:/^[a-fA-F0-9]{64}$/'],
            'events.*.payload' => ['nullable', 'array'],
        ]);

        return response()->json($ingestor->ingest($request->attributes->get('site'), $data['events']));
    }
}
