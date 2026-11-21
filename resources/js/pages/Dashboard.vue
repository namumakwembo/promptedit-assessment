<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import GenerationForm from '@/components/GenerationForm.vue';
import GenerationHistory from '@/components/GenerationHistory.vue';
import { dashboard } from '@/routes';
import type { GenerationPage } from '@/types/generation';

const props = defineProps<{
    title: string;
    canGenerate: boolean;
    generations: GenerationPage;
}>();

const poll = usePoll(3000, { only: ['generations'] }, { autoStart: false });

watch(
    () =>
        props.generations.data.some(
            (generation) =>
                ['submitting', 'queued', 'processing'].includes(
                    generation.status,
                ) && !generation.can_retry,
        ),
    (pending) => {
        if (pending) poll.start();
        else poll.stop();
    },
    { immediate: true },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard marketplace',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col gap-8 p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Track your images and generation costs.
            </p>
        </div>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <GenerationForm :can-generate="canGenerate" />
            <GenerationHistory :generations="generations" />
        </div>
    </div>
</template>
