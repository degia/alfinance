<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Tag, Trash2, X } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, store, update } from '@/routes/tags';
import type { TagFormData, TagListItem } from '@/types';

defineOptions({
    layout: {
        title: 'Tag',
        description: 'Label bebas untuk menandai transaksi',
    },
});

const props = defineProps<{
    tags: TagListItem[];
}>();

const emptyForm = (): TagFormData => ({ name: '' });

const form = useForm<TagFormData>(emptyForm());
const editingId = ref<number | null>(null);

const isEditing = computed(() => editingId.value !== null);

function startCreate(): void {
    editingId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
}

function startEdit(tag: TagListItem): void {
    editingId.value = tag.id;
    form.defaults({ name: tag.name });
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (editingId.value === null) {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => startCreate(),
        });

        return;
    }

    form.put(update(editingId.value).url, {
        preserveScroll: true,
        onSuccess: () => startCreate(),
    });
}

function remove(id: number): void {
    router.delete(destroy(id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tag" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Tag"
            description="Tag bebas untuk menandai transaksi, misalnya “liburan” atau “proyek A”."
        />

        <Card class="rounded-2xl border-0 shadow-neu-flat">
            <CardContent class="p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">
                        {{ isEditing ? 'Ubah tag' : 'Tambah tag' }}
                    </h3>
                    <Button
                        v-if="isEditing"
                        variant="ghost"
                        size="sm"
                        @click="startCreate"
                    >
                        <X class="size-4" />
                        Batal
                    </Button>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="tag-name">Nama tag</Label>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Input
                                id="tag-name"
                                v-model="form.name"
                                name="name"
                                required
                                minlength="2"
                                maxlength="30"
                                autocomplete="off"
                                placeholder="Contoh: proyek A"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.name)"
                            />
                            <Button
                                type="submit"
                                class="gap-2 sm:w-40"
                                :disabled="form.processing"
                            >
                                <Spinner
                                    v-if="form.processing"
                                    class="size-4"
                                />
                                <Pencil v-else-if="isEditing" class="size-4" />
                                <Plus v-else class="size-4" />
                                {{ isEditing ? 'Simpan' : 'Tambah' }}
                            </Button>
                        </div>
                        <InputError :message="form.errors.name" />
                    </div>
                </form>
            </CardContent>
        </Card>

        <div
            v-if="props.tags.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Belum ada tag. Buat beberapa label yang sering kamu pakai.
        </div>

        <div v-else class="flex flex-wrap gap-2">
            <div
                v-for="tag in props.tags"
                :key="tag.id"
                class="neu-card neu-interactive flex items-center gap-1 rounded-full py-1 pr-1 pl-3"
            >
                <Tag class="size-3.5 text-accent" />
                <span class="text-sm">{{ tag.name }}</span>
                <span class="flex items-center">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="size-7 rounded-full p-0"
                        :aria-label="`Ubah tag ${tag.name}`"
                        @click="startEdit(tag)"
                    >
                        <Pencil class="size-3" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="size-7 rounded-full p-0 text-muted-foreground"
                        :aria-label="`Hapus tag ${tag.name}`"
                        @click="remove(tag.id)"
                    >
                        <Trash2 class="size-3" />
                    </Button>
                </span>
            </div>
        </div>
    </div>
</template>
