<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * @phpstan-type Cost array{provider_cost_usd: string, customer_charge_usd: string}
 * @phpstan-type Pricing array{
 *     currency: string,
 *     unit: string,
 *     rates: array<string, mixed>,
 *     tokens: array{
 *         characters_per_text_token: int,
 *         text_token_variance: int|float|string,
 *         per_reference_image: int,
 *         output: array<string, array<string, int>>,
 *         reasoning: array<string, array{min: int, max: int}>
 *     }
 * }
 */
class GenerationPricing
{
    private const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * @param  list<string>  $referenceImages
     * @param  Pricing  $pricing  The response from GET /v1/pricing.
     * @return array{minimum: Cost, maximum: Cost}
     */
    public function estimate(string $prompt, string $size, string $quality, array $referenceImages, array $pricing): array
    {
        if ($pricing['currency'] !== 'usd' || $pricing['unit'] !== 'per_1m_tokens') {
            throw new InvalidArgumentException('Pricing must be in USD per one million tokens.');
        }

        $tokens = $pricing['tokens'];

        if (! isset($tokens['output'][$quality][$size], $tokens['reasoning'][$quality])) {
            throw new InvalidArgumentException('Unsupported image size or quality.');
        }

        $charactersPerToken = $tokens['characters_per_text_token'];
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);

        if ($charactersPerToken < 1 || $variance->isLessThan('0') || $variance->isGreaterThan('1')) {
            throw new InvalidArgumentException('Invalid text token estimation rules.');
        }

        $estimatedTextTokens = BigDecimal::of(intdiv(
            mb_strlen($prompt, 'UTF-8') + $charactersPerToken - 1,
            $charactersPerToken,
        ));

        // Round the bounds outward so integer token counts stay inside the range.
        $minimumTextTokens = $estimatedTextTokens
            ->multipliedBy(BigDecimal::of('1')->minus($variance))
            ->toScale(0, RoundingMode::Floor)->toInt();
        $maximumTextTokens = $estimatedTextTokens
            ->multipliedBy(BigDecimal::of('1')->plus($variance))
            ->toScale(0, RoundingMode::Ceiling)->toInt();

        $usage = [
            'input_image_tokens' => count($referenceImages) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
        ];

        return [
            'minimum' => $this->cost([
                ...$usage,
                'input_text_tokens' => $minimumTextTokens,
                'reasoning_tokens' => $tokens['reasoning'][$quality]['min'],
            ], $pricing['rates']),
            'maximum' => $this->cost([
                ...$usage,
                'input_text_tokens' => $maximumTextTokens,
                'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
            ], $pricing['rates']),
        ];
    }

    /**
     * @param  array{status: string, usage?: array<string, mixed>|null, rates?: array<string, mixed>}  $generation
     * @return Cost|null Null means the actual cost is not known yet.
     */
    public function actual(array $generation): ?array
    {
        return match ($generation['status']) {
            'queued', 'processing' => null,
            'failed' => [
                'provider_cost_usd' => '0.000000000',
                'customer_charge_usd' => '0.00000000000',
            ],
            'succeeded' => $this->cost(
                $generation['usage'] ?? [],
                $generation['rates'] ?? [],
            ),
            default => throw new InvalidArgumentException('Unknown generation status.'),
        };
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $rates
     * @return Cost
     */
    private function cost(array $usage, array $rates): array
    {
        $weightedTokens = BigDecimal::of('0');

        foreach (self::TOKEN_TYPES as $type) {
            $count = $usage[$type] ?? null;
            $rate = $rates[$type] ?? null;

            if (! is_int($count) || $count < 0) {
                throw new InvalidArgumentException("Missing or invalid token count: {$type}.");
            }

            if (! is_string($rate) || preg_match('/\A\d+(?:\.\d+)?\z/', $rate) !== 1) {
                throw new InvalidArgumentException("Missing or invalid decimal rate: {$type}.");
            }

            $weightedTokens = $weightedTokens->plus(BigDecimal::of($rate)->multipliedBy($count));
        }

        $providerCost = $weightedTokens->dividedBy('1000000', 9);

        return [
            'provider_cost_usd' => (string) $providerCost,
            'customer_charge_usd' => (string) $providerCost->multipliedBy('1.25')->toScale(11),
        ];
    }
}
