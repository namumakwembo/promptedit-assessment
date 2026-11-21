<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Image } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd } from '@/lib/money';
import { retry } from '@/routes/generations';
import type { GenerationPage, GenerationSummary } from '@/types/generation';

defineProps<{ generations: GenerationPage }>();

const unavailableImages = ref(new Set<string>());
const retrying = ref<string | null>(null);
const statuses: Record<GenerationSummary['status'], string> = {
    submitting: 'Submitting',
    submission_unknown: 'Awaiting confirmation',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

function checkAgain(generation: GenerationSummary): void {
    retrying.value = generation.id;
    router.post(
        retry(generation.id).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                retrying.value = null;
            },
        },
    );
}
</script>

<template>
    <section
        aria-labelledby="generation-history-heading"
        class="min-w-0 space-y-4"
    >
        <h2 id="generation-history-heading" class="text-lg font-semibold">
            Generation history
        </h2>
        <div
            v-if="generations.data.length === 0"
            class="flex min-h-72 flex-col items-center justify-center rounded-xl border border-dashed px-6 py-12 text-center"
        >
            <Image
                class="mb-4 size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <h3 class="font-medium">No generations yet</h3>
            <p class="mt-2 text-sm text-muted-foreground">
                Your generated images will appear here.
            </p>
        </div>

        <article
            v-for="generation in generations.data"
            :key="generation.id"
            class="overflow-hidden rounded-xl border"
        >
            <template v-if="generation.status === 'succeeded'">
                <img
                    v-if="
                        generation.image_url &&
                        !unavailableImages.has(generation.id)
                    "
                    :src="generation.image_url"
                    :alt="`Generated image: ${generation.prompt.slice(0, 160)}`"
                    class="max-h-96 w-full bg-muted object-contain"
                    loading="lazy"
                    @error="unavailableImages.add(generation.id)"
                />
                <p
                    v-else
                    class="bg-muted/50 p-6 text-center text-sm text-muted-foreground"
                >
                    Image preview is no longer available.
                </p>
            </template>

            <div class="space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <p class="min-w-0 text-sm font-medium wrap-anywhere">
                        {{ generation.prompt }}
                    </p>
                    <span
                        class="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs"
                        :class="{
                            'text-destructive': generation.status === 'failed',
                            'text-green-700 dark:text-green-400':
                                generation.status === 'succeeded',
                        }"
                    >
                        {{ statuses[generation.status] }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }} quality
                </p>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Estimated charge (USD)
                        </dt>
                        <dd class="mt-1 wrap-anywhere">
                            {{ formatUsd(generation.estimated_min_usd) }} -
                            {{ formatUsd(generation.estimated_max_usd) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Actual charge (USD)
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{
                                generation.actual_cost_usd === null
                                    ? 'Pending'
                                    : formatUsd(generation.actual_cost_usd)
                            }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="generation.error_message"
                    class="text-sm text-muted-foreground"
                >
                    {{ generation.error_message }}
                </p>
                <Button
                    v-if="generation.can_retry"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="retrying === generation.id"
                    @click="checkAgain(generation)"
                >
                    <Spinner v-if="retrying === generation.id" />
                    {{
                        generation.status === 'submission_unknown'
                            ? 'Check submission'
                            : 'Check status'
                    }}
                </Button>
            </div>
        </article>

        <nav
            v-if="generations.last_page > 1"
            aria-label="Generation history pages"
            class="flex items-center justify-between gap-3 text-sm"
        >
            <Link
                v-if="generations.prev_page_url"
                :href="generations.prev_page_url"
                preserve-scroll
                class="underline underline-offset-4"
                >Previous</Link
            >
            <span v-else />
            <span class="text-muted-foreground"
                >Page {{ generations.current_page }} of
                {{ generations.last_page }}</span
            >
            <Link
                v-if="generations.next_page_url"
                :href="generations.next_page_url"
                preserve-scroll
                class="underline underline-offset-4"
                >Next</Link
            >
            <span v-else />
        </nav>
    </section>
</template>
