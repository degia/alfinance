<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { FolderTree, Pencil, Plus, Trash2, X } from '@lucide/vue';
import CategoryIcon from '@/components/CategoryIcon.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { destroy as destroyCategory, store, update } from '@/routes/categories';
import type {
    Category,
    CategoryFormData,
    CategoryIconOption,
    CategoryNode,
    CategoryParentOption,
} from '@/types';

defineOptions({
    layout: {
        title: 'Kategori',
        description: 'Kelompokkan transaksi dengan ikon dan warna',
    },
});

const props = defineProps<{
    categories: CategoryNode[];
    parents: CategoryParentOption[];
    icons: CategoryIconOption[];
}>();

/** Warna pilihan untuk form kategori. */
const palette = [
    '#2563eb',
    '#7c3aed',
    '#059669',
    '#d97706',
    '#dc2626',
    '#0891b2',
    '#db2777',
    '#65a30d',
];

const emptyForm = (): CategoryFormData => ({
    name: '',
    parent_id: '',
    icon: 'shapes',
    color: '#2563eb',
});

const form = useForm<CategoryFormData>(emptyForm());
const editingId = ref<number | null>(null);

const isEditing = computed(() => editingId.value !== null);

function startCreate(): void {
    editingId.value = null;
    form.defaults(emptyForm());
    form.reset();
    form.clearErrors();
}

function startEdit(category: Category): void {
    editingId.value = category.id;
    form.defaults({
        name: category.name,
        parent_id: category.parent_id?.toString() ?? '',
        icon: category.icon,
        color: category.color,
    });
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
    router.delete(destroyCategory(id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Kategori" />

    <div class="flex flex-col gap-6">
        <Heading
            title="Kategori"
            description="Dua level: kategori induk dan sub-kategori. Ikon dan warna dipakai ulang di chart dan badge."
        />

        <Card class="w-full max-w-4xl rounded-2xl border-0 shadow-neu-flat">
            <CardContent>
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">
                        {{ isEditing ? 'Ubah kategori' : 'Tambah kategori' }}
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
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category-name">Nama kategori</Label>
                            <Input
                                id="category-name"
                                v-model="form.name"
                                name="name"
                                required
                                maxlength="60"
                                autocomplete="off"
                                placeholder="Contoh: Makanan"
                                class="shadow-neu-inset"
                                :aria-invalid="Boolean(form.errors.name)"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category-parent">
                                Kategori induk
                            </Label>
                            <Select v-model="form.parent_id">
                                <SelectTrigger
                                    id="category-parent"
                                    class="w-full shadow-neu-inset"
                                >
                                    <SelectValue
                                        placeholder="Kategori induk (opsional)"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="">
                                        Tanpa induk (kategori utama)
                                    </SelectItem>
                                    <SelectItem
                                        v-for="parent in parents"
                                        :key="parent.id"
                                        :value="parent.id.toString()"
                                    >
                                        {{ parent.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                Kosongkan kalau kategori ini mau jadi kategori
                                utama.
                            </p>
                            <InputError :message="form.errors.parent_id" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category-icon">Ikon</Label>
                            <Select v-model="form.icon">
                                <SelectTrigger
                                    id="category-icon"
                                    class="w-full shadow-neu-inset"
                                    :aria-invalid="Boolean(form.errors.icon)"
                                >
                                    <SelectValue placeholder="Pilih ikon" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="icon in props.icons"
                                        :key="icon.value"
                                        :value="icon.value"
                                    >
                                        <span class="flex items-center gap-2">
                                            <CategoryIcon :icon="icon.value" />
                                            {{ icon.label }}
                                        </span>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="form.errors.icon" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="category-color">Warna</Label>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-for="color in palette"
                                    :key="color"
                                    type="button"
                                    class="neu-interactive size-9 rounded-full border-2"
                                    :class="
                                        form.color === color
                                            ? 'border-foreground shadow-neu-pressed-sm'
                                            : 'border-transparent shadow-neu-flat-sm'
                                    "
                                    :style="{ backgroundColor: color }"
                                    :aria-label="`Warna ${color}`"
                                    :aria-pressed="form.color === color"
                                    @click="form.color = color"
                                />
                            </div>
                            <InputError :message="form.errors.color" />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            class="gap-2 sm:w-40"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            <Pencil v-else-if="isEditing" class="size-4" />
                            <Plus v-else class="size-4" />
                            {{ isEditing ? 'Simpan' : 'Tambah' }}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <div
            v-if="props.categories.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Belum ada kategori. Buat kategori utama dulu, misalnya "Kebutuhan
            Pokok" atau "Penghasilan".
        </div>

        <Card
            v-for="category in props.categories"
            :key="category.id"
            class="rounded-2xl border-0 shadow-neu-flat"
        >
            <CardContent class="flex flex-col gap-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl"
                            :style="{
                                backgroundColor: `${category.color}1a`,
                                color: category.color,
                            }"
                        >
                            <CategoryIcon :icon="category.icon" />
                        </span>
                        <p class="truncate font-medium">{{ category.name }}</p>
                        <Badge variant="secondary" class="text-2xs">
                            {{ category.children.length }} sub
                        </Badge>
                    </div>

                    <div class="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Ubah kategori"
                            @click="startEdit(category)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            aria-label="Hapus kategori"
                            @click="remove(category.id)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <ul
                    v-if="category.children.length > 0"
                    class="flex flex-col gap-2 pl-4"
                >
                    <li
                        v-for="child in category.children"
                        :key="child.id"
                        class="neu-inset flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <CategoryIcon
                                :icon="child.icon"
                                :color="child.color"
                                class="size-4"
                            />
                            <span class="truncate text-sm">
                                {{ child.name }}
                            </span>
                        </span>

                        <span class="flex items-center gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label="Ubah sub-kategori"
                                @click="startEdit(child)"
                            >
                                <Pencil class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                aria-label="Hapus sub-kategori"
                                @click="remove(child.id)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </span>
                    </li>
                </ul>

                <p
                    v-else
                    class="flex items-center gap-1 pl-4 text-xs text-muted-foreground"
                >
                    <FolderTree class="size-3" />
                    Belum ada sub-kategori.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
