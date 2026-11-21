<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** @phpstan-import-type GenerationPayload from PixelforgeClient */
class ApplyGenerationResult
{
    public function __construct(private GenerationPricing $pricing) {}

    /** @param GenerationPayload $payload */
    public function apply(Generation $generation, array $payload): Generation
    {
        return DB::transaction(function () use ($generation, $payload): Generation {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->isTerminal()) {
                return $generation;
            }

            if ($generation->provider_id !== null && $generation->provider_id !== $payload['id']) {
                throw new UnexpectedValueException('Generation identity does not match.');
            }

            if ($generation->status === 'processing' && $payload['status'] === 'queued') {
                return $generation;
            }

            $cost = $this->pricing->actual($payload);

            if ($cost !== null && $payload['completed_at'] === null) {
                throw new UnexpectedValueException('Completed generation is missing its completion date.');
            }

            $generation->provider_id = $payload['id'];
            $generation->status = $payload['status'];
            $generation->rates = $payload['rates'];
            $generation->usage = $payload['usage'];
            $generation->provider_created_at = CarbonImmutable::parse($payload['created_at']);
            $generation->started_at = $payload['started_at'] === null ? null : CarbonImmutable::parse($payload['started_at']);
            $generation->completed_at = $payload['completed_at'] === null ? null : CarbonImmutable::parse($payload['completed_at']);
            $generation->image_url = $payload['image_url'];
            $generation->image_url_expires_at = $payload['image_url_expires_at'] === null ? null : CarbonImmutable::parse($payload['image_url_expires_at']);
            $generation->submission_uncertain = false;
            $generation->error_code = is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null;
            $generation->error_message = $payload['status'] === 'failed' ? 'This image could not be generated. You were not charged.' : null;

            if ($cost !== null) {
                $generation->provider_cost_usd = $cost['provider_cost_usd'];
                $generation->customer_charge_usd = $cost['customer_charge_usd'];
            }

            $generation->save();

            return $generation;
        });
    }
}
