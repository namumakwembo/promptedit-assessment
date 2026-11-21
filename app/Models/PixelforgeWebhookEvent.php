<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string|null $provider_generation_id
 * @property string|null $generation_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property CarbonImmutable $provider_created_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $last_error
 */
#[Fillable(['event_id', 'event_type', 'provider_generation_id', 'payload', 'provider_created_at'])]
class PixelforgeWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'provider_created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
