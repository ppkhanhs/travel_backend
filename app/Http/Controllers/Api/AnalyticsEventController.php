<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'events' => 'required|array|min:1|max:50',
            'events.*.event_name' => 'required|string|max:100',
            'events.*.entity_type' => 'nullable|string|max:50',
            'events.*.entity_id' => 'nullable|uuid',
            'events.*.metadata' => 'nullable|array',
            'events.*.context' => 'nullable|array',
            'events.*.occurred_at' => 'nullable|date',
            'events.*.device_id' => 'nullable|string|max:100',
            'events.*.session_id' => 'nullable|string|max:100',
        ]);

        $authenticatedUser = $request->user();
        $deviceHeader = $request->header('X-Device-Id') ?? null;

        DB::transaction(function () use ($payload, $authenticatedUser, $deviceHeader) {
            foreach ($payload['events'] as $event) {
                AnalyticsEvent::create([
                    'user_id' => $authenticatedUser?->id,
                    'device_id' => $event['device_id'] ?? $deviceHeader,
                    'session_id' => $event['session_id'] ?? null,
                    'event_name' => $event['event_name'],
                    'entity_type' => $event['entity_type'] ?? null,
                    'entity_id' => $event['entity_id'] ?? null,
                    'metadata' => $event['metadata'] ?? [],
                    'context' => $event['context'] ?? [],
                    'occurred_at' => isset($event['occurred_at'])
                        ? Carbon::parse($event['occurred_at'])
                        : now(),
                ]);
            }
        });

        return response()->json([
            'stored' => count($payload['events']),
        ], 201);
    }
}
