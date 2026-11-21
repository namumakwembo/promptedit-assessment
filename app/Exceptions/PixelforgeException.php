<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?int $retryAfter,
    ) {
        parent::__construct("Pixelforge returned {$errorCode} (HTTP {$status}).");
    }

    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $retryAfter = $response->header('Retry-After');

        return new self(
            is_string($code) ? $code : 'provider_error',
            $response->status(),
            ctype_digit($retryAfter) ? max(1, (int) $retryAfter) : null,
        );
    }

    public function retryable(): bool
    {
        return ($this->status === 429 && in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true))
            || $this->status >= 500;
    }

    public function definitelyRejected(): bool
    {
        return match ($this->status) {
            400 => $this->errorCode === 'content_policy_violation',
            401 => $this->errorCode === 'invalid_api_key',
            409 => $this->errorCode === 'idempotency_key_reused',
            422 => $this->errorCode === 'validation_failed',
            429 => in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded'], true),
            503 => $this->errorCode === 'overloaded',
            default => false,
        };
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'This prompt could not be accepted. Please change it and try again.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'Image generation is busy. Please try again shortly.',
            default => 'Image generation is temporarily unavailable. Please try again later.',
        };
    }
}
