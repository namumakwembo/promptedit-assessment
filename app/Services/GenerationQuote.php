<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type Cost from GenerationPricing
 *
 * @phpstan-type GenerationInput array{prompt: string, size: string, quality: string, reference_images: list<string>}
 * @phpstan-type Quote array{quote_id: string, expires_at: string, minimum: Cost, maximum: Cost}
 */
class GenerationQuote
{
    public function __construct(private PixelforgeClient $client, private GenerationPricing $pricing) {}

    /**
     * @param  GenerationInput  $input
     * @return Quote
     */
    public function create(User $user, array $input): array
    {
        $costs = $this->pricing->estimate($input['prompt'], $input['size'], $input['quality'], $input['reference_images'], $this->client->pricing());
        $id = (string) Str::uuid();
        $expires = now()->addMinutes(5);
        $quote = ['quote_id' => $id, 'expires_at' => $expires->toIso8601String(), ...$costs];

        Cache::put($this->key($user, $id), ['input' => $input, 'quote' => $quote], $expires);

        return $quote;
    }

    /**
     * @param  GenerationInput  $input
     * @return Quote
     */
    public function forSubmission(User $user, string $id, array $input): array
    {
        /** @var array{input: GenerationInput, quote: Quote}|null $saved */
        $saved = Cache::get($this->key($user, $id));

        if ($saved === null || $saved['input'] !== $input) {
            throw ValidationException::withMessages(['quote_id' => 'Refresh the estimate before generating this image.']);
        }

        return $saved['quote'];
    }

    private function key(User $user, string $id): string
    {
        return "generation-quote:{$user->id}:{$id}";
    }
}
