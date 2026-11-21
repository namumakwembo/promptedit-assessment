<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd } from '@/lib/money';
import { estimate as estimateRoute, store } from '@/routes/generations';
import type { GenerationEstimate } from '@/types/generation';

defineProps<{ canGenerate: boolean }>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
    request_id: crypto.randomUUID(),
    quote_id: '',
});
const references = ref('');
const quote = ref<GenerationEstimate | null>(null);
const estimating = ref(false);
const estimateError = ref('');
const estimateErrors = ref<Record<string, string>>({});
let debounce: ReturnType<typeof setTimeout> | undefined;
let expiry: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;

const input = computed(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: references.value
        .split('\n')
        .map((url) => url.trim())
        .filter(Boolean),
}));

function errorFor(field: string): string | undefined {
    return Object.entries({ ...estimateErrors.value, ...form.errors }).find(
        ([key]) => key === field || key.startsWith(`${field}.`),
    )?.[1];
}

function clearEstimate(): void {
    clearTimeout(debounce);
    clearTimeout(expiry);
    request?.abort();
    request = undefined;
    quote.value = null;
    form.quote_id = '';
    estimating.value = false;
}

async function refreshEstimate(): Promise<void> {
    clearEstimate();
    estimateError.value = '';
    estimateErrors.value = {};

    if (!form.prompt.trim()) return;

    const currentRequest = new AbortController();
    request = currentRequest;
    estimating.value = true;
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    try {
        const response = await fetch(estimateRoute().url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: currentRequest.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    cookie?.slice('XSRF-TOKEN='.length) ?? '',
                ),
            },
            body: JSON.stringify(input.value),
        });
        const data = (await response.json()) as GenerationEstimate & {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (request !== currentRequest) return;

        if (!response.ok) {
            estimateErrors.value = Object.fromEntries(
                Object.entries(data.errors ?? {}).map(([key, messages]) => [
                    key,
                    messages[0],
                ]),
            );
            estimateError.value =
                response.status === 422
                    ? 'Please check the fields above.'
                    : (data.message ??
                      'An estimate is unavailable. Please try again.');

            return;
        }

        quote.value = data;
        form.quote_id = data.quote_id;
        form.clearErrors('quote_id');
        expiry = setTimeout(
            () => {
                quote.value = null;
                form.quote_id = '';
                estimateError.value =
                    'Your estimate expired. Refresh it before generating.';
            },
            Math.max(0, Date.parse(data.expires_at) - Date.now()),
        );
    } catch {
        if (!currentRequest.signal.aborted) {
            estimateError.value =
                'Could not load an estimate. Please try again.';
        }
    } finally {
        if (request === currentRequest) estimating.value = false;
    }
}

watch(input, () => {
    clearEstimate();
    form.clearErrors();
    estimateError.value = '';
    estimateErrors.value = {};

    if (form.prompt.trim()) {
        estimating.value = true;
        debounce = setTimeout(() => {
            void refreshEstimate();
        }, 600);
    }
});

function submit(): void {
    if (!quote.value || form.processing) return;

    form.reference_images = input.value.reference_images;
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'quote_id');
            form.request_id = crypto.randomUUID();
            references.value = '';
            clearEstimate();
        },
        onError: (errors) => {
            if (errors.quote_id) void refreshEstimate();
        },
    });
}

onUnmounted(clearEstimate);
</script>

<template>
    <section
        class="rounded-xl border p-5"
        aria-labelledby="generation-form-heading"
    >
        <h2 id="generation-form-heading" class="text-lg font-semibold">
            Generate an image
        </h2>
        <p class="mt-1 text-sm text-muted-foreground">
            Describe your image and review the estimated price.
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <fieldset :disabled="form.processing" class="space-y-5">
                <div class="space-y-2">
                    <Label for="prompt">Prompt</Label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        name="prompt"
                        rows="4"
                        maxlength="4000"
                        required
                        class="w-full rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                        placeholder="A lighthouse at sunrise, painted in watercolors"
                    />
                    <InputError :message="errorFor('prompt')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="size">Image size</Label>
                        <select
                            id="size"
                            v-model="form.size"
                            name="size"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="1024x1024">
                                Square (1024 x 1024)
                            </option>
                            <option value="1536x1024">
                                Landscape (1536 x 1024)
                            </option>
                            <option value="1024x1536">
                                Portrait (1024 x 1536)
                            </option>
                        </select>
                        <InputError :message="errorFor('size')" />
                    </div>
                    <div class="space-y-2">
                        <Label for="quality">Quality</Label>
                        <select
                            id="quality"
                            v-model="form.quality"
                            name="quality"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                        <InputError :message="errorFor('quality')" />
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="reference-images"
                        >Reference image URLs (optional)</Label
                    >
                    <textarea
                        id="reference-images"
                        v-model="references"
                        rows="2"
                        class="w-full rounded-md border bg-transparent px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                        placeholder="https://example.com/reference.png"
                    />
                    <p class="text-xs text-muted-foreground">
                        One URL per line, up to four images.
                    </p>
                    <InputError :message="errorFor('reference_images')" />
                </div>

                <div
                    class="rounded-lg bg-muted/50 p-4 text-sm"
                    aria-live="polite"
                >
                    <p v-if="estimating" class="flex items-center gap-2">
                        <Spinner /> Calculating estimate...
                    </p>
                    <template v-else-if="quote">
                        <p class="font-medium">
                            Estimated charge:
                            {{ formatUsd(quote.minimum_usd) }} -
                            {{ formatUsd(quote.maximum_usd) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            USD. Final cost depends on actual token usage.
                            Failed generations are free.
                        </p>
                    </template>
                    <p v-else-if="estimateError" class="text-destructive">
                        {{ estimateError }}
                    </p>
                    <p v-else class="text-muted-foreground">
                        Enter a prompt to see your estimated charge.
                    </p>
                    <Button
                        v-if="estimateError"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="refreshEstimate"
                        >Refresh estimate</Button
                    >
                </div>

                <InputError
                    :message="form.errors.request_id || form.errors.quote_id"
                />
                <p v-if="!canGenerate" class="text-sm text-muted-foreground">
                    Image generation is temporarily unavailable.
                </p>
                <Button
                    type="submit"
                    :disabled="
                        !canGenerate || !quote || estimating || form.processing
                    "
                    class="w-full"
                >
                    <Spinner v-if="form.processing" />
                    {{ form.processing ? 'Submitting...' : 'Generate image' }}
                </Button>
            </fieldset>
        </form>
    </section>
</template>
