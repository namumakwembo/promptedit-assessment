<?php

namespace App\Jobs;

use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use App\Services\ApplyGenerationResult;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class SubmitGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 40;

    public int $uniqueFor = 300;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(PixelforgeClient $client, ApplyGenerationResult $results): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->isTerminal() || $generation->provider_id !== null) {
            return;
        }

        $previouslyUncertain = $generation->submission_uncertain;
        // Persist before the network call: a worker can stop before saving the response.
        $generation->submission_uncertain = true;
        Generation::query()->whereKey($generation->id)->whereNull('provider_id')
            ->whereNotIn('status', ['succeeded', 'failed'])->update(['submission_uncertain' => true]);

        try {
            $payload = $client->createGeneration([
                ...$generation->inputPayload(),
                'metadata' => ['generation_id' => $generation->id],
            ], $generation->id);
        } catch (ConnectionException $exception) {
            $this->retryOrPause($generation, null);

            return;
        } catch (PixelforgeException $exception) {
            if ($exception->definitelyRejected()) {
                $generation->submission_uncertain = $previouslyUncertain;
                Generation::query()->whereKey($generation->id)->whereNull('provider_id')
                    ->whereNotIn('status', ['succeeded', 'failed'])->update(['submission_uncertain' => $previouslyUncertain]);
            }

            if ($exception->retryable()) {
                $this->retryOrPause($generation, $exception);
            } elseif ($generation->submission_uncertain) {
                $this->pause($generation);
            } else {
                $this->reject($generation, $exception);
            }

            return;
        }

        $generation = $results->apply($generation, $payload);

        if (! $generation->isTerminal()) {
            RefreshGeneration::dispatch($generation->id)->delay(now()->addSeconds(5));
        }
    }

    private function retryOrPause(Generation $generation, ?PixelforgeException $exception): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release(max($exception->retryAfter ?? 0, min(60, 2 ** $this->attempts())));

            return;
        }

        if ($generation->submission_uncertain) {
            $this->pause($generation);
        } else {
            $this->reject($generation, $exception);
        }
    }

    private function pause(Generation $generation): void
    {
        Generation::query()->whereKey($generation->id)->whereNull('provider_id')
            ->whereNotIn('status', ['succeeded', 'failed'])->update([
                'status' => 'submission_unknown',
                'submission_uncertain' => true,
                'error_code' => 'submission_unconfirmed',
                'error_message' => 'Your submission is taking longer to confirm. Check it again to continue.',
            ]);
    }

    private function reject(Generation $generation, ?PixelforgeException $exception): void
    {
        Generation::query()->whereKey($generation->id)->whereNull('provider_id')
            ->whereNotIn('status', ['succeeded', 'failed'])->update([
                'status' => 'failed',
                'submission_uncertain' => false,
                'error_code' => $exception?->errorCode,
                'error_message' => $exception?->userMessage() ?? 'Image generation is temporarily unavailable. Please try again later.',
                'provider_cost_usd' => '0.000000000',
                'customer_charge_usd' => '0.00000000000',
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null && ! $generation->isTerminal() && $generation->provider_id === null) {
            $this->pause($generation);
        }
    }
}
