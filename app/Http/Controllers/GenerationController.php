<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeException;
use App\Http\Requests\GenerationRequest;
use App\Jobs\RefreshGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationQuote;
use App\Services\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerationController extends Controller
{
    public function estimate(GenerationRequest $request, GenerationQuote $quotes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $quote = $quotes->create($user, $request->generationInput());
        } catch (ConnectionException|PixelforgeException $exception) {
            return response()->json(['message' => 'An estimate is temporarily unavailable. Please try again shortly.'], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'An estimate is temporarily unavailable. Please try again shortly.'], 503);
        }

        // Provider costs remain on the server; the customer sees their own estimate.
        return response()->json([
            'quote_id' => $quote['quote_id'],
            'expires_at' => $quote['expires_at'],
            'minimum_usd' => $quote['minimum']['customer_charge_usd'],
            'maximum_usd' => $quote['maximum']['customer_charge_usd'],
        ]);
    }

    public function store(GenerationRequest $request, GenerationQuote $quotes, PixelforgeClient $client): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->generationInput();
        $requestId = (string) $request->validated('request_id');
        $existing = Generation::query()->where('user_id', $user->id)->where('request_id', $requestId)->first();

        if ($existing !== null) {
            $this->ensureSameInput($existing, $input);

            return to_route('dashboard');
        }

        if (! $client->configured()) {
            throw ValidationException::withMessages(['prompt' => 'Image generation is temporarily unavailable. Please try again later.']);
        }

        $quote = $quotes->forSubmission($user, (string) $request->validated('quote_id'), $input);

        DB::transaction(function () use ($user, $requestId, $input, $quote): void {
            $generation = Generation::query()->firstOrCreate([
                'user_id' => $user->id,
                'request_id' => $requestId,
            ], [
                ...$input,
                'status' => 'submitting',
                'estimated_provider_min_usd' => $quote['minimum']['provider_cost_usd'],
                'estimated_provider_max_usd' => $quote['maximum']['provider_cost_usd'],
                'estimated_customer_min_usd' => $quote['minimum']['customer_charge_usd'],
                'estimated_customer_max_usd' => $quote['maximum']['customer_charge_usd'],
            ]);

            $this->ensureSameInput($generation, $input);

            if ($generation->wasRecentlyCreated) {
                SubmitGeneration::dispatch($generation->id)->afterCommit();
            }
        });

        return to_route('dashboard');
    }

    public function retry(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()?->getAuthIdentifier(), 404);

        if ($generation->isTerminal() || $generation->error_message === null) {
            return to_route('dashboard');
        }

        $generation->error_code = null;
        $generation->error_message = null;

        if ($generation->provider_id === null) {
            $generation->status = 'submitting';
            $generation->save();
            SubmitGeneration::dispatch($generation->id);
        } else {
            $generation->save();
            RefreshGeneration::dispatch($generation->id);
        }

        return to_route('dashboard');
    }

    /** @param array{prompt: string, size: string, quality: string, reference_images: list<string>} $input */
    private function ensureSameInput(Generation $generation, array $input): void
    {
        if ($generation->inputPayload() !== $input) {
            throw ValidationException::withMessages(['request_id' => 'This submission has already been used. Refresh the page before generating another image.']);
        }
    }
}
