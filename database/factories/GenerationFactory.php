<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'request_id' => (string) Str::uuid(),
            'prompt' => 'A lighthouse at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'submitting',
            'estimated_provider_min_usd' => '0.046500000',
            'estimated_provider_max_usd' => '0.069100000',
            'estimated_customer_min_usd' => '0.05812500000',
            'estimated_customer_max_usd' => '0.08637500000',
        ];
    }
}
