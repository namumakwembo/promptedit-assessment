<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string $request_id
 * @property string|null $provider_id
 * @property string $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property string $estimated_provider_min_usd
 * @property string $estimated_provider_max_usd
 * @property string $estimated_customer_min_usd
 * @property string $estimated_customer_max_usd
 * @property string|null $provider_cost_usd
 * @property string|null $customer_charge_usd
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $rates
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property bool $submission_uncertain
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'request_id', 'status', 'prompt', 'size', 'quality', 'reference_images', 'estimated_provider_min_usd', 'estimated_provider_max_usd', 'estimated_customer_min_usd', 'estimated_customer_max_usd'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'submission_uncertain' => 'boolean',
            'estimated_provider_min_usd' => 'decimal:9',
            'estimated_provider_max_usd' => 'decimal:9',
            'estimated_customer_min_usd' => 'decimal:11',
            'estimated_customer_max_usd' => 'decimal:11',
            'provider_cost_usd' => 'decimal:9',
            'customer_charge_usd' => 'decimal:11',
            'image_url_expires_at' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    /** @return array{prompt: string, size: string, quality: string, reference_images: list<string>} */
    public function inputPayload(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
        ];
    }
}
