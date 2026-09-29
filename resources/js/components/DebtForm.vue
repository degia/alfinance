<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import type { InertiaForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { emptyDebtForm } from '@/lib/debt';
import type { Debt, DebtFormData, DebtFormOptions } from '@/types';

const props = defineProps<{
    options: DebtFormOptions;
    /** `undefined` berarti mode create. */
    debt?: Debt;
    submitLabel: string;
    onSubmit: (form: InertiaForm<DebtFormData>) => void;
}>();

const empty = (): DebtFormData => emptyDebtForm();

const initial = (): DebtFormData =>
    props.debt === undefined
        ? empty()
        : {
              direction: props.debt.direction,
              counterparty: props.debt.counterparty,
              principal: props.debt.principal,
              interest_rate: props.debt.interest_rate ?? '',
              start_date: props.debt.start_date ?? '',
              due_date: props.debt.due_date ?? '',
              term_count:
                  props.debt.term_count === null
                      ? ''
                      : String(props.debt.term_count),
              include_in_net_worth: props.debt.include_in_net_worth,
              note: props.debt.note ?? '',
              account_id:
                  props.debt.account === null
                      ? ''
                      : String(props.debt.account.id),
          };

const form = useForm<DebtFormData>(initial());

/**
 * Piutang selalu menambah aset, jadi centang "masukkan ke net worth" hanya
 * bermakna untuk utang. Ini aturan yang sama dengan `DebtRequest`.
 */
const isPayable = computed(() => form.direction !== 'receivable');

function submit(): void {
    props.onSubmit(form);
}
</script>

<template>
    <Card class="w-full max-w-4xl rounded-2xl border-0 shadow-neu-flat">
        <CardContent>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="debt-direction">Tipe</Label>
                        <Select v-model="form.direction">
                            <SelectTrigger id="debt-direction" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="direction in props.options
                                        .directions"
                                    :key="direction.value"
                                    :value="direction.value"
                                >
                                    {{ direction.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.direction" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="debt-counterparty">Pihak</Label>
                        <Input
                            id="debt-counterparty"
                            v-model="form.counterparty"
                            required
                            maxlength="120"
                            placeholder="Contoh: severance"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.counterparty)"
                        />
                        <InputError :message="form.errors.counterparty" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="debt-principal">Pokok (Rp)</Label>
                        <Input
                            id="debt-principal"
                            v-model="form.principal"
                            inputmode="decimal"
                            required
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.principal)"
                        />
                        <InputError :message="form.errors.principal" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="debt-interest">Suku bunga (%/thn)</Label>
                        <Input
                            id="debt-interest"
                            v-model="form.interest_rate"
                            inputmode="decimal"
                            placeholder="Kosongkan kalau tidak ada"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.interest_rate)"
                        />
                        <InputError :message="form.errors.interest_rate" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="debt-start">Tanggal mulai</Label>
                        <Input
                            id="debt-start"
                            v-model="form.start_date"
                            type="date"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.start_date)"
                        />
                        <InputError :message="form.errors.start_date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="debt-due">Jatuh tempo</Label>
                        <Input
                            id="debt-due"
                            v-model="form.due_date"
                            type="date"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.due_date)"
                        />
                        <InputError :message="form.errors.due_date" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="debt-terms">Jumlah cicilan</Label>
                        <Input
                            id="debt-terms"
                            v-model="form.term_count"
                            inputmode="numeric"
                            placeholder="Kosongkan kalau bebas"
                            class="shadow-neu-inset"
                            :aria-invalid="Boolean(form.errors.term_count)"
                        />
                        <InputError :message="form.errors.term_count" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="debt-account">Akun pembayaran</Label>
                        <Select v-model="form.account_id">
                            <SelectTrigger id="debt-account" class="w-full">
                                <SelectValue placeholder="Pilih akun" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">Tanpa akun</SelectItem>
                                <SelectItem
                                    v-for="account in props.options.accounts"
                                    :key="account.id"
                                    :value="String(account.id)"
                                >
                                    {{ account.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.account_id" />
                    </div>

                    <div class="flex items-end">
                        <label
                            v-if="isPayable"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                id="debt-include"
                                :model-value="form.include_in_net_worth"
                                @update:model-value="
                                    form.include_in_net_worth = Boolean($event)
                                "
                            />
                            Masukkan sisa utang ke net worth
                        </label>
                        <p v-else class="pb-2 text-xs text-muted-foreground">
                            Piutang selalu dihitung sebagai aset net worth.
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="debt-note">Catatan</Label>
                    <Input
                        id="debt-note"
                        v-model="form.note"
                        maxlength="500"
                        class="shadow-neu-inset"
                    />
                    <InputError :message="form.errors.note" />
                </div>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        class="gap-2 sm:w-44"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" />
                        {{ props.submitLabel }}
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
