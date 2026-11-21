<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\PixelforgeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        // Signature middleware has already verified these exact bytes.
        try {
            $event = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid webhook JSON.'], 400);
        }

        if (! is_array($event) || array_is_list($event)) {
            return response()->json(['message' => 'Invalid webhook event.'], 422);
        }

        $rules = [
            'id' => ['required', 'string', 'starts_with:evt_', 'max:255'],
            'object' => ['required', Rule::in(['event'])],
            'type' => ['required', Rule::in(['ping', 'generation.processing', 'generation.succeeded', 'generation.failed'])],
            'created_at' => ['required', 'string', 'date'],
        ];

        if (($event['type'] ?? null) !== 'ping') {
            $rules += [
                'data.object' => ['required', 'array'],
                'data.object.id' => ['required', 'string', 'starts_with:gen_', 'max:255'],
                'data.object.status' => ['required', Rule::in(['processing', 'succeeded', 'failed'])],
                'data.object.metadata.generation_id' => ['sometimes', 'uuid'],
            ];
        }

        Validator::make($event, $rules)->validate();

        $expectedStatus = match ($event['type']) {
            'generation.processing' => 'processing',
            'generation.succeeded' => 'succeeded',
            'generation.failed' => 'failed',
            default => null,
        };

        if ($expectedStatus !== null && $event['data']['object']['status'] !== $expectedStatus) {
            throw ValidationException::withMessages(['data.object.status' => 'Event type and generation status do not match.']);
        }

        DB::transaction(function () use ($event): void {
            $receipt = PixelforgeWebhookEvent::query()->firstOrCreate(['event_id' => $event['id']], [
                'event_type' => $event['type'],
                'provider_generation_id' => $event['data']['object']['id'] ?? null,
                'payload' => $event,
                'provider_created_at' => $event['created_at'],
            ]);

            if ($receipt->processed_at === null) {
                ProcessPixelforgeWebhook::dispatch($receipt->id)->afterCommit();
            }
        });

        return response()->noContent();
    }
}
