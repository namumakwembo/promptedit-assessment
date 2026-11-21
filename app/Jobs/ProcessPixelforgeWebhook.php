<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Services\ApplyGenerationResult;
use App\Services\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class ProcessPixelforgeWebhook implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 40;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(PixelforgeClient $client, ApplyGenerationResult $results): void
    {
        DB::transaction(function () use ($client, $results): void {
            $event = PixelforgeWebhookEvent::query()->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            if ($event->event_type === 'ping') {
                $this->finish($event, 'processed');

                return;
            }

            $data = $event->payload['data'] ?? null;
            $object = is_array($data) ? ($data['object'] ?? null) : null;

            if (! is_array($object)) {
                throw new UnexpectedValueException('Webhook is missing its generation object.');
            }

            $payload = $client->parseGeneration($object);
            $metadata = $object['metadata'] ?? null;
            $localId = is_array($metadata) && is_string($metadata['generation_id'] ?? null) ? $metadata['generation_id'] : null;
            $generation = $localId === null ? null : Generation::query()->find($localId);
            $generation ??= Generation::query()->where('provider_id', $payload['id'])->first();

            if ($generation === null) {
                $this->finish($event, 'ignored');

                return;
            }

            $results->apply($generation, $payload);
            $event->generation_id = $generation->id;
            $this->finish($event, 'processed');
        });
    }

    private function finish(PixelforgeWebhookEvent $event, string $status): void
    {
        $event->status = $status;
        $event->processed_at = CarbonImmutable::now();
        $event->last_error = null;
        $event->save();
    }

    public function failed(?Throwable $exception): void
    {
        PixelforgeWebhookEvent::query()->whereKey($this->eventId)->whereNull('processed_at')->update([
            'last_error' => $exception?->getMessage() ?? 'Webhook processing failed.',
        ]);
    }
}
