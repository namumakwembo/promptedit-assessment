<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Generation $generation */
        $generation = $this->resource;

        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'status' => $generation->status,
            'estimated_min_usd' => $generation->estimated_customer_min_usd,
            'estimated_max_usd' => $generation->estimated_customer_max_usd,
            'actual_cost_usd' => $generation->customer_charge_usd,
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'created_at' => $generation->created_at->toIso8601String(),
            'error_message' => $generation->error_message,
            'can_retry' => ! $generation->isTerminal() && $generation->error_message !== null,
        ];
    }
}
