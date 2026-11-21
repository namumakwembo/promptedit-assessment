<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** @phpstan-import-type GenerationInput from \App\Services\GenerationQuote */
class GenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $submitting = $this->routeIs('generations.store');

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['sometimes', 'array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            'request_id' => [Rule::requiredIf($submitting), 'uuid'],
            'quote_id' => [Rule::requiredIf($submitting), 'uuid'],
        ];
    }

    /** @return GenerationInput */
    public function generationInput(): array
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: list<string>} $data */
        $data = $this->safe()->only(['prompt', 'size', 'quality', 'reference_images']);

        return [
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $data['reference_images'] ?? [],
        ];
    }
}
