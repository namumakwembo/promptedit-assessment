<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Image } from '@lucide/vue';
import { dashboard } from '@/routes';

defineProps<{
    title: string;
}>();

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

        <section
            aria-label="Generation history"
            class="flex min-h-72 flex-col items-center justify-center rounded-xl border border-dashed px-6 py-12 text-center">

            <Image
                class="mb-4 size-8 text-muted-foreground"
                aria-hidden="true"/>

            <h2 class="font-medium">No generations yet</h2>

            <p class="mt-2 text-sm text-muted-foreground">
                Your generated images will appear here.
            </p>

        </section>
    </div>
</template>
