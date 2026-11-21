<?php

use App\Jobs\ProcessPixelforgeWebhook;
use App\Jobs\RefreshGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Services\ApplyGenerationResult;
use App\Services\GenerationPricing;
use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function () {
    $this->freezeTime();
    config(['services.pixelforge.webhook_secret' => 'whsec_test_secret', 'services.pixelforge.api_key' => 'test-key']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->generation = Generation::factory()->create();
    $this->object = json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-generation.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->object = [
        ...$this->object,
        'metadata' => ['generation_id' => $this->generation->id],
        'status' => 'succeeded',
        'started_at' => '2026-11-19T12:00:03Z',
        'completed_at' => '2026-11-19T12:00:30Z',
        'image_url' => 'https://pixelforge.example.test/image.png',
        'image_url_expires_at' => '2026-11-20T12:00:30Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
    ];
    $this->event = [
        'id' => 'evt_test_success',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-19T12:00:30Z',
        'data' => ['object' => $this->object],
    ];
});

function pixelforgeTestSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test_secret'): string
{
    $timestamp ??= now()->getTimestamp();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

function deliverPixelforgeEvent(TestCase $test, array $event, ?int $timestamp = null, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return $test->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature ?? pixelforgeTestSignature($body, $timestamp),
    ], $body);
}

test('a signed webhook is accepted without a user session and processed asynchronously', function () {
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();

    $receipt = PixelforgeWebhookEvent::query()->sole();
    expect($receipt->event_id)->toBe('evt_test_success')
        ->and($receipt->status)->toBe('received')
        ->and($this->generation->fresh()->customer_charge_usd)->toBeNull();
    Queue::assertPushed(ProcessPixelforgeWebhook::class, fn (ProcessPixelforgeWebhook $job) => $job->eventId === $receipt->id);
    Http::assertNothingSent();

    (new ProcessPixelforgeWebhook($receipt->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($receipt->fresh()->status)->toBe('processed')
        ->and($receipt->fresh()->generation_id)->toBe($this->generation->id)
        ->and($this->generation->fresh()->status)->toBe('succeeded')
        ->and($this->generation->fresh()->provider_cost_usd)->toBe('0.305510000')
        ->and($this->generation->fresh()->customer_charge_usd)->toBe('0.38188750000');
    Http::assertNothingSent();
});

test('duplicate deliveries share one receipt and do not process a completed event again', function () {
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
    Queue::assertPushed(ProcessPixelforgeWebhook::class, 1);

    $receipt = PixelforgeWebhookEvent::query()->sole();
    $job = new ProcessPixelforgeWebhook($receipt->id);
    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));
    $processedAt = $receipt->fresh()->processed_at->toIso8601String();

    $this->travel(6)->minutes();
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    $this->assertDatabaseCount('pixelforge_webhook_events', 1);
    Queue::assertPushed(ProcessPixelforgeWebhook::class, 1);
    expect($receipt->fresh()->processed_at->toIso8601String())->toBe($processedAt)
        ->and($this->generation->fresh()->customer_charge_usd)->toBe('0.38188750000');
});

test('a late processing event cannot rewind a terminal generation', function (string $status, string $cost) {
    $terminal = $this->event;
    $terminal['type'] = 'generation.'.$status;
    $terminal['data']['object']['status'] = $status;
    deliverPixelforgeEvent($this, $terminal)->assertNoContent();
    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))
        ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    $late = $this->event;
    $late['id'] = 'evt_late_processing';
    $late['type'] = 'generation.processing';
    $late['data']['object']['status'] = 'processing';
    $late['data']['object']['usage'] = null;
    $late['data']['object']['completed_at'] = null;
    deliverPixelforgeEvent($this, $late)->assertNoContent();
    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->where('event_id', $late['id'])->sole()->id))
        ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($this->generation->fresh()->status)->toBe($status)
        ->and($this->generation->fresh()->customer_charge_usd)->toBe($cost)
        ->and(PixelforgeWebhookEvent::query()->where('status', 'processed')->count())->toBe(2);
})->with([['succeeded', '0.38188750000'], ['failed', '0.00000000000']]);

test('a webhook can update a generation before the submission request returns', function (string $status, ?string $charge) {
    $this->event['type'] = 'generation.'.$status;
    $this->event['data']['object']['status'] = $status;
    if ($status === 'processing') {
        $this->event['data']['object']['usage'] = null;
        $this->event['data']['object']['completed_at'] = null;
        $this->event['data']['object']['image_url'] = null;
        $this->event['data']['object']['image_url_expires_at'] = null;
    }
    Http::fake(['*/generations' => function (ClientRequest $request) {
        expect($request['metadata']['generation_id'])->toBe($this->generation->id);
        deliverPixelforgeEvent($this, $this->event)->assertNoContent();
        (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))
            ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

        return Http::response([...$this->object, 'status' => 'queued', 'usage' => null, 'completed_at' => null], 202);
    }]);

    (new SubmitGeneration($this->generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($this->generation->fresh()->status)->toBe($status)
        ->and($this->generation->fresh()->provider_id)->toBe('gen_test_01')
        ->and($this->generation->fresh()->customer_charge_usd)->toBe($charge);
    if ($status === 'processing') {
        Queue::assertPushed(RefreshGeneration::class);
    } else {
        Queue::assertNotPushed(RefreshGeneration::class);
    }
})->with([['succeeded', '0.38188750000'], ['processing', null]]);

test('webhooks can find an already accepted generation by its provider id', function () {
    $this->generation->provider_id = 'gen_test_01';
    $this->generation->save();
    unset($this->event['data']['object']['metadata']);

    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))
        ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($this->generation->fresh()->status)->toBe('succeeded');
});

test('polling and webhooks share the same finalization path', function () {
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    $client = app(PixelforgeClient::class);
    $results = app(ApplyGenerationResult::class);
    $results->apply($this->generation, $client->parseGeneration($this->object));

    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))->handle($client, $results);

    expect($this->generation->fresh()->customer_charge_usd)->toBe('0.38188750000')
        ->and(PixelforgeWebhookEvent::query()->sole()->status)->toBe('processed');
});

test('signature verification uses raw bytes including whitespace and unicode', function () {
    $event = ['id' => 'evt_ping_raw', 'object' => 'event', 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['text' => 'A fox 🦊']];
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => pixelforgeTestSignature($body),
    ], $body)->assertNoContent();

    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))
        ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));
    expect(PixelforgeWebhookEvent::query()->sole()->status)->toBe('processed')
        ->and($this->generation->fresh()->customer_charge_usd)->toBeNull();
});

test('invalid signatures are rejected before storing an event', function (string $scenario) {
    $body = json_encode($this->event, JSON_THROW_ON_ERROR);
    $signature = match ($scenario) {
        'missing' => '',
        'malformed' => 't=invalid,v1=invalid',
        'wrong secret' => pixelforgeTestSignature($body, secret: 'wrong-secret'),
        'old timestamp' => pixelforgeTestSignature($body, now()->getTimestamp() - 301),
        'future timestamp' => pixelforgeTestSignature($body, now()->getTimestamp() + 301),
        'duplicate field' => pixelforgeTestSignature($body).',t='.now()->getTimestamp(),
        'tampered body' => pixelforgeTestSignature($body.' '),
    };

    deliverPixelforgeEvent($this, $this->event, signature: $signature)->assertBadRequest();
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
    Queue::assertNothingPushed();
})->with(['missing', 'malformed', 'wrong secret', 'old timestamp', 'future timestamp', 'duplicate field', 'tampered body']);

test('an unconfigured signing secret does not accept deliveries', function () {
    config(['services.pixelforge.webhook_secret' => null]);
    deliverPixelforgeEvent($this, $this->event)->assertServiceUnavailable();
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
    Queue::assertNothingPushed();
});

test('signed malformed json is rejected without a receipt', function () {
    $body = '{invalid json';
    $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => pixelforgeTestSignature($body),
    ], $body)->assertBadRequest();
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
});

test('invalid event types and conflicting statuses are rejected', function (string $scenario) {
    if ($scenario === 'unknown type') {
        $this->event['type'] = 'generation.unknown';
    }
    if ($scenario === 'wrong status') {
        $this->event['data']['object']['status'] = 'processing';
    }
    if ($scenario === 'wrong id') {
        $this->event['id'] = 'invalid';
    }

    deliverPixelforgeEvent($this, $this->event)->assertUnprocessable();
    $this->assertDatabaseCount('pixelforge_webhook_events', 0);
})->with(['unknown type', 'wrong status', 'wrong id']);

test('events without a matching local generation are recorded once and ignored', function () {
    unset($this->event['data']['object']['metadata']);
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    (new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id))
        ->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect(PixelforgeWebhookEvent::query()->sole()->status)->toBe('ignored')
        ->and($this->generation->fresh()->customer_charge_usd)->toBeNull();
});

test('an event with a conflicting provider identity cannot change a generation', function () {
    $this->generation->provider_id = 'gen_other';
    $this->generation->save();
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    $job = new ProcessPixelforgeWebhook(PixelforgeWebhookEvent::query()->sole()->id);

    expect(fn () => $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class)))
        ->toThrow(UnexpectedValueException::class);
    expect($this->generation->fresh()->customer_charge_usd)->toBeNull()
        ->and(PixelforgeWebhookEvent::query()->sole()->processed_at)->toBeNull();
});

test('receipt completion and financial updates roll back together and can be retried', function () {
    deliverPixelforgeEvent($this, $this->event)->assertNoContent();
    $receipt = PixelforgeWebhookEvent::query()->sole();
    $job = new ProcessPixelforgeWebhook($receipt->id);
    $interrupted = new class(app(GenerationPricing::class)) extends ApplyGenerationResult
    {
        public function apply(Generation $generation, array $payload): Generation
        {
            parent::apply($generation, $payload);

            throw new RuntimeException('Simulated processing interruption');
        }
    };

    expect(fn () => $job->handle(app(PixelforgeClient::class), $interrupted))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('Simulated processing interruption'));

    expect($this->generation->fresh()->customer_charge_usd)->toBeNull()
        ->and($receipt->fresh()->processed_at)->toBeNull()
        ->and($receipt->fresh()->last_error)->toBe('Simulated processing interruption');

    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));
    expect($this->generation->fresh()->customer_charge_usd)->toBe('0.38188750000')
        ->and($receipt->fresh()->status)->toBe('processed')
        ->and($receipt->fresh()->last_error)->toBeNull();
});
