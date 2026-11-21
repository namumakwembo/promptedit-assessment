<?php

use App\Jobs\RefreshGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\ApplyGenerationResult;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.base_url' => 'https://pixelforge.example.test/v1']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->input = ['prompt' => 'A lighthouse at sunrise', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];
    $this->pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->providerGeneration = json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge-generation.json'), true, flags: JSON_THROW_ON_ERROR);
});

function completedPixelforgePayload(array $payload): array
{
    return [
        ...$payload,
        'status' => 'succeeded',
        'started_at' => '2026-11-19T12:00:03Z',
        'completed_at' => '2026-11-19T12:00:30Z',
        'image_url' => 'https://pixelforge.example.test/image.png',
        'image_url_expires_at' => '2026-11-20T12:00:30Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
    ];
}

test('guests cannot estimate or submit generations', function () {
    $this->postJson(route('generations.estimate'), $this->input)->assertUnauthorized();
    $this->postJson(route('generations.store'), $this->input)->assertUnauthorized();
    Http::assertNothingSent();
});

test('estimates use live pricing without sending the api key and cache it briefly', function () {
    Http::fake(['*/pricing' => Http::response($this->pricing)]);
    $this->actingAs($this->user);

    $first = $this->postJson(route('generations.estimate'), $this->input)
        ->assertOk()->assertJsonStructure(['quote_id', 'expires_at', 'minimum_usd', 'maximum_usd']);
    $this->postJson(route('generations.estimate'), $this->input)->assertOk();

    expect($first->json('minimum_usd'))->toBeString();
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://pixelforge.example.test/v1/pricing' && ! $request->hasHeader('Authorization'));
    $this->assertDatabaseCount('generations', 0);
});

test('invalid image inputs are rejected before contacting the provider', function (array $invalid, string $field) {
    $this->actingAs($this->user)->postJson(route('generations.estimate'), [...$this->input, ...$invalid])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    Http::assertNothingSent();
})->with([
    [['prompt' => ''], 'prompt'],
    [['prompt' => str_repeat('a', 4001)], 'prompt'],
    [['quality' => 'ultra'], 'quality'],
    [['size' => '512x512'], 'size'],
    [['reference_images' => array_fill(0, 5, 'https://example.test/image.png')], 'reference_images'],
    [['reference_images' => ['ftp://example.test/image.png']], 'reference_images.0'],
]);

test('a provider pricing outage returns a useful error without creating a generation', function () {
    Http::fake(['*/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    $this->actingAs($this->user)->postJson(route('generations.estimate'), $this->input)
        ->assertServiceUnavailable()->assertJsonPath('message', 'An estimate is temporarily unavailable. Please try again shortly.');
    $this->assertDatabaseCount('generations', 0);
});

test('submitting saves the quoted estimate and dispatches one background job', function () {
    Http::fake(['*/pricing' => Http::response($this->pricing)]);
    $this->actingAs($this->user);
    $quote = $this->postJson(route('generations.estimate'), $this->input)->json();
    $requestId = (string) Str::uuid();
    $payload = [...$this->input, 'quote_id' => $quote['quote_id'], 'request_id' => $requestId, 'estimated_customer_max_usd' => '0'];

    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));
    $generation = Generation::query()->sole();

    expect($generation->status)->toBe('submitting')
        ->and($generation->user_id)->toBe($this->user->id)
        ->and($generation->estimated_customer_min_usd)->toBe($quote['minimum_usd'])
        ->and($generation->estimated_customer_max_usd)->toBe($quote['maximum_usd'])
        ->and($generation->customer_charge_usd)->toBeNull();
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generationId === $generation->id);

    Cache::flush();
    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('generations', 1);
    Queue::assertPushed(SubmitGeneration::class, 1);
});

test('a request id cannot be reused for a different image', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->postJson(route('generations.store'), [
        ...$this->input, 'prompt' => 'A different image', 'request_id' => $generation->request_id, 'quote_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('request_id');
    Queue::assertNothingPushed();
});

test('a changed expired or other users quote cannot authorize submission', function (string $scenario) {
    Http::fake(['*/pricing' => Http::response($this->pricing)]);
    $this->actingAs($this->user);
    $quote = $this->postJson(route('generations.estimate'), $this->input)->json();
    $input = $this->input;

    if ($scenario === 'changed') {
        $input['quality'] = 'high';
    }
    if ($scenario === 'expired') {
        $this->travel(6)->minutes();
    }
    if ($scenario === 'other user') {
        $this->actingAs(User::factory()->create());
    }

    $this->postJson(route('generations.store'), [...$input, 'quote_id' => $quote['quote_id'], 'request_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('quote_id');
    $this->assertDatabaseCount('generations', 0);
    Queue::assertNothingPushed();
})->with(['changed', 'expired', 'other user']);

test('submission sends server credentials stable idempotency and local metadata', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    Http::fake(['*/generations' => Http::response($this->providerGeneration, 202)]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer test-key')
        && $request->hasHeader('Idempotency-Key', $generation->id)
        && $request['metadata']['generation_id'] === $generation->id
        && $request['prompt'] === $generation->prompt
        && ! isset($request['user_id']) && ! isset($request['quote_id']));
    expect($generation->fresh()->provider_id)->toBe('gen_test_01')
        ->and($generation->fresh()->status)->toBe('queued')
        ->and($generation->fresh()->customer_charge_usd)->toBeNull();
    Queue::assertPushed(RefreshGeneration::class);
});

test('a content policy rejection is free', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'content_policy_violation']], 400)]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($generation->fresh()->status)->toBe('failed')
        ->and($generation->fresh()->provider_cost_usd)->toBe('0.000000000')
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.00000000000');
    Queue::assertNothingPushed();
});

test('retry after is respected when the provider is busy', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'concurrency_limit_exceeded']], 429, ['Retry-After' => '9'])]);
    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();

    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    $job->assertReleased(9);
    expect($generation->fresh()->status)->toBe('submitting');
});

test('an uncertain timed out submission stays uncharged and can be checked with the same key', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
    $queuedJob = Mockery::mock(Job::class);
    $queuedJob->shouldReceive('attempts')->andReturn(5);
    $job = new SubmitGeneration($generation->id);
    $job->setJob($queuedJob);

    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($generation->fresh()->status)->toBe('submission_unknown')
        ->and($generation->fresh()->submission_uncertain)->toBeTrue()
        ->and($generation->fresh()->customer_charge_usd)->toBeNull();

    $this->actingAs($this->user)->post(route('generations.retry', $generation))->assertRedirect(route('dashboard'));
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $retry) => $retry->generationId === $generation->id);
    $this->assertDatabaseCount('generations', 1);
});

test('successful polling records exact final costs once and never rewinds a finished generation', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    $results = app(ApplyGenerationResult::class);
    $results->apply($generation, $this->providerGeneration);
    Http::fake(['*/generations/gen_test_01' => Http::response(completedPixelforgePayload($this->providerGeneration))]);

    (new RefreshGeneration($generation->id))->handle(app(PixelforgeClient::class), $results);
    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost_usd)->toBe('0.305510000')
        ->and($generation->customer_charge_usd)->toBe('0.38188750000')
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-19T12:00:30+00:00');

    $results->apply($generation, $this->providerGeneration);
    $results->apply($generation, completedPixelforgePayload($this->providerGeneration));

    expect($generation->fresh()->status)->toBe('succeeded')
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.38188750000');
    Queue::assertNothingPushed();
});

test('a processing status schedules another poll without recording a charge', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    app(ApplyGenerationResult::class)->apply($generation, $this->providerGeneration);
    Http::fake(['*/generations/gen_test_01' => Http::response([...$this->providerGeneration, 'status' => 'processing'])]);

    (new RefreshGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($generation->fresh()->status)->toBe('processing')
        ->and($generation->fresh()->customer_charge_usd)->toBeNull();
    Queue::assertPushed(RefreshGeneration::class, fn (RefreshGeneration $job) => $job->delay !== null);
});

test('an accepted generation that later fails costs nothing', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    $payload = [...$this->providerGeneration, 'status' => 'failed', 'completed_at' => '2026-11-19T12:00:30Z', 'error' => ['code' => 'generation_failed', 'message' => 'Failed']];

    app(ApplyGenerationResult::class)->apply($generation, $payload);

    expect($generation->fresh()->status)->toBe('failed')
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.00000000000');
});

test('a temporary polling outage keeps the final cost unknown', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    app(ApplyGenerationResult::class)->apply($generation, $this->providerGeneration);
    Http::fake(['*/generations/gen_test_01' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

    (new RefreshGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($generation->fresh()->status)->toBe('queued')
        ->and($generation->fresh()->customer_charge_usd)->toBeNull()
        ->and($generation->fresh()->error_code)->toBe('status_delayed');
});

test('users only see their own generation history without provider costs or credentials', function () {
    $own = Generation::factory()->create(['user_id' => $this->user->id]);
    Generation::factory()->create();

    $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('generations.data', 1)
        ->where('generations.data.0.id', $own->id)
        ->missing('generations.data.0.provider_cost_usd')
        ->missing('api_key')
        ->where('canGenerate', true));
});

test('users cannot check another users generation', function () {
    $generation = Generation::factory()->create();
    $this->actingAs($this->user)->post(route('generations.retry', $generation))->assertNotFound();
    Queue::assertNothingPushed();
});

test('daily limits are not retried automatically', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    Http::fake(['*/generations' => Http::response(['error' => ['code' => 'daily_limit_exceeded']], 429)]);
    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();

    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    $job->assertNotReleased();
    expect($generation->fresh()->status)->toBe('failed')
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.00000000000');
});

test('a missing api key prevents submission without exposing configuration', function () {
    config(['services.pixelforge.api_key' => '']);

    $this->actingAs($this->user)->postJson(route('generations.store'), [
        ...$this->input, 'request_id' => (string) Str::uuid(), 'quote_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('prompt');

    $this->assertDatabaseCount('generations', 0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('a late polling error cannot change a completed generation', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    $results = app(ApplyGenerationResult::class);
    $results->apply($generation, $this->providerGeneration);
    Http::fake(['*/generations/gen_test_01' => function () use ($generation, $results) {
        $results->apply($generation, completedPixelforgePayload($this->providerGeneration));

        return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
    }]);

    (new RefreshGeneration($generation->id))->handle(app(PixelforgeClient::class), $results);

    expect($generation->fresh()->status)->toBe('succeeded')
        ->and($generation->fresh()->error_message)->toBeNull()
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.38188750000');
});

test('a late submission rejection cannot replace a completed charge with zero', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    $results = app(ApplyGenerationResult::class);
    Http::fake(['*/generations' => function () use ($generation, $results) {
        $results->apply($generation, completedPixelforgePayload($this->providerGeneration));

        return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
    }]);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), $results);

    expect($generation->fresh()->status)->toBe('succeeded')
        ->and($generation->fresh()->customer_charge_usd)->toBe('0.38188750000');
});

test('a worker interruption before saving the provider response preserves submission uncertainty', function () {
    $generation = Generation::factory()->create(['user_id' => $this->user->id]);
    $requests = 0;
    Http::fake(['*/generations' => function () use ($generation, &$requests) {
        if (++$requests === 1) {
            expect($generation->fresh()->submission_uncertain)->toBeTrue();

            throw new RuntimeException('Simulated worker interruption');
        }

        return Http::response(['error' => ['code' => 'invalid_api_key']], 401);
    }]);

    expect(fn () => (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class)))
        ->toThrow(RuntimeException::class);

    (new SubmitGeneration($generation->id))->handle(app(PixelforgeClient::class), app(ApplyGenerationResult::class));

    expect($generation->fresh()->status)->toBe('submission_unknown')
        ->and($generation->fresh()->customer_charge_usd)->toBeNull();
});
