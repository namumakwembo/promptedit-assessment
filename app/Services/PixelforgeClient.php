<?php

namespace App\Services;

use App\Exceptions\PixelforgeException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use UnexpectedValueException;

/**
 * @phpstan-import-type Pricing from GenerationPricing
 *
 * @phpstan-type GenerationPayload array{
 *     id: string, status: string, rates: array<string, mixed>, usage: array<string, mixed>|null,
 *     image_url: string|null, image_url_expires_at: string|null,
 *     created_at: string, started_at: string|null, completed_at: string|null,
 *     error: array<string, mixed>|null
 * }
 */
class PixelforgeClient
{
    public function configured(): bool
    {
        return filled(config('services.pixelforge.api_key'));
    }

    /** @return Pricing */
    public function pricing(): array
    {
        return Cache::remember('pixelforge:pricing:'.sha1(config('services.pixelforge.base_url')), 60, function (): array {
            $data = $this->decode($this->request()->get('pricing'));

            if (($data['object'] ?? null) !== 'pricing' || ! isset($data['currency'], $data['unit'])
                || ! is_array($data['rates'] ?? null) || ! is_array($data['tokens'] ?? null)) {
                throw new UnexpectedValueException('Invalid Pixelforge pricing response.');
            }

            /** @var Pricing $data */
            return $data;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return GenerationPayload
     */
    public function createGeneration(array $input, string $idempotencyKey): array
    {
        return $this->generation($this->request(authenticated: true)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $input));
    }

    /** @return GenerationPayload */
    public function getGeneration(string $id): array
    {
        return $this->generation($this->request(authenticated: true)->get('generations/'.rawurlencode($id)));
    }

    private function request(bool $authenticated = false): PendingRequest
    {
        $request = Http::baseUrl(config('services.pixelforge.base_url'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();

        if ($authenticated) {
            if (! $this->configured()) {
                throw new LogicException('Pixelforge API key is not configured.');
            }

            $request->withToken(config('services.pixelforge.api_key'));
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw PixelforgeException::fromResponse($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedValueException('Invalid Pixelforge JSON response.');
        }

        return $data;
    }

    /** @return GenerationPayload */
    private function generation(Response $response): array
    {
        return $this->parseGeneration($this->decode($response));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return GenerationPayload
     */
    public function parseGeneration(array $data): array
    {

        if (! is_string($data['id'] ?? null) || ! str_starts_with($data['id'], 'gen_')
            || ! in_array($data['status'] ?? null, ['queued', 'processing', 'succeeded', 'failed'], true)
            || ! is_array($data['rates'] ?? null) || ! is_string($data['created_at'] ?? null)) {
            throw new UnexpectedValueException('Invalid Pixelforge generation response.');
        }

        return [
            'id' => $data['id'],
            'status' => $data['status'],
            'rates' => $data['rates'],
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : null,
            'image_url' => is_string($data['image_url'] ?? null) ? $data['image_url'] : null,
            'image_url_expires_at' => is_string($data['image_url_expires_at'] ?? null) ? $data['image_url_expires_at'] : null,
            'created_at' => $data['created_at'],
            'started_at' => is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            'completed_at' => is_string($data['completed_at'] ?? null) ? $data['completed_at'] : null,
            'error' => is_array($data['error'] ?? null) ? $data['error'] : null,
        ];
    }
}
