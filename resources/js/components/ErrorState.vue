<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleAlert, Lightbulb, OctagonAlert, RotateCw } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { resolveHttpError } from '@/lib/httpErrors';

type Props = {
    status: number;
};

const props = defineProps<Props>();

const info = computed(() => resolveHttpError(props.status));
const isServerError = computed(() => props.status >= 500);

function reload() {
    window.location.reload();
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-background p-6">
        <Transition appear name="error-pop">
            <div class="w-full max-w-md rounded-2xl bg-card p-8 text-center shadow-neu-raised">
                <div
                    class="relative mx-auto mb-5 flex size-16 items-center justify-center rounded-full"
                    :class="isServerError ? 'bg-destructive/10 text-destructive' : 'bg-warning/10 text-warning'"
                >
                    <span
                        class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-25"
                        :class="isServerError ? 'bg-destructive' : 'bg-warning'"
                    />
                    <OctagonAlert v-if="isServerError" class="relative size-8" />
                    <CircleAlert v-else class="relative size-8" />
                </div>

                <p class="text-sm font-medium text-muted-foreground">Error {{ status }}</p>
                <h1 class="mt-1 text-xl font-semibold text-foreground">
                    {{ info.title }}
                </h1>

                <Alert :variant="isServerError ? 'destructive' : 'default'" class="mt-5 text-left">
                    <CircleAlert class="size-4" />
                    <AlertTitle>Keterangan</AlertTitle>
                    <AlertDescription>{{ info.description }}</AlertDescription>
                </Alert>

                <div class="mt-4 rounded-lg bg-surface-muted p-4 text-left text-sm">
                    <div class="mb-1.5 flex items-center gap-1.5 font-medium text-foreground">
                        <Lightbulb class="size-4 text-accent" />
                        Solusi
                    </div>

                    <p v-if="info.solution.type === 'hint'" class="text-surface-muted-foreground">
                        {{ info.solution.message }}
                    </p>

                    <Button
                        v-else-if="info.solution.method === 'reload'"
                        class="mt-1 w-full gap-2"
                        @click="reload"
                    >
                        <RotateCw class="size-4" />
                        {{ info.solution.label }}
                    </Button>

                    <Button v-else as-child class="mt-1 w-full">
                        <Link :href="info.solution.href">{{ info.solution.label }}</Link>
                    </Button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.error-pop-enter-active {
    transition:
        opacity var(--motion-slow, 300ms) var(--ease-neu, cubic-bezier(0.2, 0, 0, 1)),
        transform var(--motion-slow, 300ms) var(--ease-neu, cubic-bezier(0.2, 0, 0, 1));
}

.error-pop-enter-from {
    opacity: 0;
    transform: translateY(16px) scale(0.96);
}
</style>
