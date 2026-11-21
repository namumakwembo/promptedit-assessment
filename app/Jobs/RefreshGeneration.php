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

class RefreshGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

        if ($generation === null || $generation->isTerminal() || $generation->provider_id === null) {
            return;
        }

        try {
            $generation = $results->apply($generation, $client->getGeneration($generation->provider_id));
        } catch (ConnectionException|PixelforgeException $exception) {
            if ($this->attempts() < $this->tries && (! $exception instanceof PixelforgeException || $exception->retryable())) {
                $this->release(max($exception instanceof PixelforgeException ? ($exception->retryAfter ?? 0) : 0, min(60, 2 ** $this->attempts())));
            } else {
                $this->pause($generation);
            }

            return;
        }

        if (! $generation->isTerminal()) {
            // A successful status check starts a fresh polling job; only errors consume retries.
            self::dispatch($generation->id)->delay(now()->addSeconds(5));
        }
    }

    private function pause(Generation $generation): void
    {
        Generation::query()->whereKey($generation->id)->whereNotIn('status', ['succeeded', 'failed'])->update([
            'error_code' => 'status_delayed',
            'error_message' => 'Status updates are delayed. Check again to get the latest result.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation !== null && ! $generation->isTerminal()) {
            $this->pause($generation);
        }
    }
}
