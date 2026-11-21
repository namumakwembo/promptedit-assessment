<?php

use App\Services\GenerationPricing;
use Brick\Math\Exception\RoundingNecessaryException;

beforeEach(function () {
    $this->calculator = new GenerationPricing;
    $this->pricing = json_decode(
        file_get_contents(__DIR__.'/../Fixtures/pixelforge-pricing.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $this->usage = [
        'input_text_tokens' => 0,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
    ];
});

test('each token category uses its own rate per million', function (string $type, string $providerCost, string $customerCharge) {
    $this->usage[$type] = 1;

    expect($this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $this->pricing['rates'],
    ]))->toBe([
        'provider_cost_usd' => $providerCost,
        'customer_charge_usd' => $customerCharge,
    ]);
})->with([
    'text' => ['input_text_tokens', '0.000005000', '0.00000625000'],
    'reference image' => ['input_image_tokens', '0.000010000', '0.00001250000'],
    'output' => ['output_tokens', '0.000040000', '0.00005000000'],
    'reasoning' => ['reasoning_tokens', '0.000012500', '0.00001562500'],
]);

test('the published completed generation has the exact final cost', function () {
    expect($this->calculator->actual([
        'status' => 'succeeded',
        'usage' => [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ],
        'rates' => $this->pricing['rates'],
    ]))->toBe([
        'provider_cost_usd' => '0.305510000',
        'customer_charge_usd' => '0.38188750000',
    ]);
});

test('final costs use the generation rate snapshot', function () {
    $this->usage['output_tokens'] = 1;
    $rates = $this->pricing['rates'];
    $rates['output_tokens'] = '1.00';

    expect($this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $rates,
    ]))->toBe([
        'provider_cost_usd' => '0.000001000',
        'customer_charge_usd' => '0.00000125000',
    ]);
});

test('markup preserves a fraction of a nanodollar', function () {
    $this->usage['output_tokens'] = 1;
    $rates = $this->pricing['rates'];
    $rates['output_tokens'] = '0.001';

    expect($this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $rates,
    ]))->toBe([
        'provider_cost_usd' => '0.000000001',
        'customer_charge_usd' => '0.00000000125',
    ]);
});

test('provider costs are never silently rounded', function () {
    $this->usage['output_tokens'] = 1;
    $rates = $this->pricing['rates'];
    $rates['output_tokens'] = '0.0001';

    expect(fn () => $this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $rates,
    ]))->toThrow(RoundingNecessaryException::class);
});

test('the documented maximum estimate includes reference images and variable tokens', function (string $prompt) {
    expect($this->calculator->estimate(
        prompt: $prompt,
        size: '1536x1024',
        quality: 'high',
        referenceImages: ['https://example.test/one.png', 'https://example.test/two.png'],
        pricing: $this->pricing,
    ))->toBe([
        'minimum' => [
            'provider_cost_usd' => '0.286225000',
            'customer_charge_usd' => '0.35778125000',
        ],
        'maximum' => [
            'provider_cost_usd' => '0.373875000',
            'customer_charge_usd' => '0.46734375000',
        ],
    ]);
})->with([
    'ASCII characters' => [str_repeat('a', 400)],
    'Unicode characters' => [str_repeat('🦊', 400)],
]);

test('estimates support every documented quality and size', function (string $quality, string $size, string $minimum, string $maximum) {
    $estimate = $this->calculator->estimate('abcd', $size, $quality, [], $this->pricing);

    expect($estimate['minimum']['provider_cost_usd'])->toBe($minimum)
        ->and($estimate['maximum']['provider_cost_usd'])->toBe($maximum);
})->with([
    ['low', '1024x1024', '0.011200000', '0.017460000'],
    ['low', '1536x1024', '0.016800000', '0.023060000'],
    ['low', '1024x1536', '0.016800000', '0.023060000'],
    ['medium', '1024x1024', '0.046500000', '0.069010000'],
    ['medium', '1536x1024', '0.068500000', '0.091010000'],
    ['medium', '1024x1536', '0.068500000', '0.091010000'],
    ['high', '1024x1024', '0.184500000', '0.272010000'],
    ['high', '1536x1024', '0.270500000', '0.358010000'],
    ['high', '1024x1536', '0.270500000', '0.358010000'],
]);

test('text token estimates round characters up and token bounds outward', function () {
    $estimate = $this->calculator->estimate('abcde', '1024x1024', 'low', [], $this->pricing);

    expect($estimate['minimum']['provider_cost_usd'])->toBe('0.011205000')
        ->and($estimate['maximum']['provider_cost_usd'])->toBe('0.017465000');
});

test('estimates read rates and token rules from supplied pricing', function () {
    $pricing = $this->pricing;
    $pricing['rates']['output_tokens'] = '80.00';
    $pricing['tokens']['characters_per_text_token'] = 2;
    $pricing['tokens']['text_token_variance'] = 0;
    $pricing['tokens']['per_reference_image'] = 10;
    $pricing['tokens']['output']['low']['1024x1024'] = 100;
    $pricing['tokens']['reasoning']['low'] = ['min' => 0, 'max' => 0];

    $estimate = $this->calculator->estimate('abcd', '1024x1024', 'low', ['https://example.test/ref.png'], $pricing);
    $expected = ['provider_cost_usd' => '0.008110000', 'customer_charge_usd' => '0.01013750000'];

    expect($estimate)->toBe(['minimum' => $expected, 'maximum' => $expected]);
});

test('unfinished generations have no actual cost yet', function (string $status) {
    expect($this->calculator->actual(['status' => $status]))->toBeNull();
})->with(['queued', 'processing']);

test('failed generations are free even when usage is present', function () {
    expect($this->calculator->actual([
        'status' => 'failed',
        'usage' => ['output_tokens' => 6450],
        'rates' => $this->pricing['rates'],
    ]))->toBe([
        'provider_cost_usd' => '0.000000000',
        'customer_charge_usd' => '0.00000000000',
    ]);
});

test('missing usage or rates cannot silently produce a charge', function (string $missing) {
    $generation = ['status' => 'succeeded', 'usage' => $this->usage, 'rates' => $this->pricing['rates']];
    unset($generation[$missing]);

    expect(fn () => $this->calculator->actual($generation))->toThrow(InvalidArgumentException::class);
})->with(['usage', 'rates']);

test('invalid token counts are rejected', function (mixed $count) {
    $this->usage['input_text_tokens'] = $count;

    expect(fn () => $this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $this->pricing['rates'],
    ]))->toThrow(InvalidArgumentException::class);
})->with([[-1], [1.5], ['1'], [null]]);

test('rates must be nonnegative decimal strings', function (mixed $rate) {
    $rates = $this->pricing['rates'];
    $rates['output_tokens'] = $rate;

    expect(fn () => $this->calculator->actual([
        'status' => 'succeeded',
        'usage' => $this->usage,
        'rates' => $rates,
    ]))->toThrow(InvalidArgumentException::class);
})->with([[-1], ['-1.00'], [40.0], ['free'], [null]]);

test('unknown generation states are rejected', function () {
    expect(fn () => $this->calculator->actual(['status' => 'cancelled']))->toThrow(InvalidArgumentException::class);
});

test('unsupported quality or size is rejected', function (string $quality, string $size) {
    expect(fn () => $this->calculator->estimate('abcd', $size, $quality, [], $this->pricing))
        ->toThrow(InvalidArgumentException::class);
})->with([['ultra', '1024x1024'], ['low', '999x999']]);

test('unsupported currency or pricing unit is rejected', function (string $field, string $value) {
    $pricing = $this->pricing;
    $pricing[$field] = $value;

    expect(fn () => $this->calculator->estimate('abcd', '1024x1024', 'low', [], $pricing))
        ->toThrow(InvalidArgumentException::class);
})->with([['currency', 'eur'], ['unit', 'per_1k_tokens']]);
