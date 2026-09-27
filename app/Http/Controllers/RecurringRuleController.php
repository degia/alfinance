<?php

namespace App\Http\Controllers;

use App\Enums\RecurringFrequency;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\RecurringRuleRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Tag;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecurringRuleController extends Controller
{
    use AuthorizesWorkspaceData;

    /**
     * Daftar aturan berulang beserta instance yang masih menunggu konfirmasi.
     */
    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $includeInactive = $request->boolean('inactive');

        $rules = RecurringRule::query()
            ->with(['account', 'transferToAccount', 'category'])
            ->when(! $includeInactive, fn ($query) => $query->active())
            ->orderBy('next_run_at')
            ->get()
            ->map(fn (RecurringRule $rule): array => $this->present($rule))
            ->values()
            ->all();

        // Hanya instance yang lahir dari aturan berulang; transaksi pending
        // manual tetap dikelola di halaman Transaksi.
        $pending = Transaction::query()
            ->pending()
            ->whereNotNull('recurring_rule_id')
            ->with(['account', 'category', 'tags'])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'type_label' => $transaction->type->label(),
                'amount' => $transaction->amount,
                'note' => $transaction->note,
                'occurred_at' => $transaction->occurred_at->toDateString(),
                'account' => $transaction->account?->only(['id', 'name']),
                'category' => $transaction->category?->only(['id', 'name']),
                'tags' => $transaction->tags->pluck('name')->all(),
                'recurring_rule_id' => $transaction->recurring_rule_id,
            ])
            ->values()
            ->all();

        return Inertia::render('recurring-rules/Index', [
            'rules' => $rules,
            'pending' => $pending,
            'filters' => [
                'inactive' => $includeInactive,
            ],
            'options' => $this->formOptions(),
        ]);
    }

    public function store(RecurringRuleRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->ruleAttributes();

        // Rule adalah template: tidak ada touch ke `cached_balance` di sini.
        RecurringRule::query()->create($attributes);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Aturan transaksi berulang berhasil dibuat.'),
        ]);

        return to_route('recurring-rules.index');
    }

    public function update(RecurringRuleRequest $request, RecurringRule $recurring_rule): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->ruleAttributes();

        // `next_run_at` tidak pernah boleh ditarik mundur ke masa lalu tanpa
        // sengaja: scheduler akan segera memproses rule pada run berikutnya.
        if ($attributes['next_run_at']->lessThan(CarbonImmutable::now()->subDay())) {
            $attributes['next_run_at'] = CarbonImmutable::now();
        }

        $recurring_rule->fill($attributes)->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Aturan transaksi berulang berhasil diperbarui.'),
        ]);

        return to_route('recurring-rules.index');
    }

    /**
     * Aktifkan/nonaktifkan rule. Nonaktif dipakai sebagai pengganti hapus supaya
     * histori instance yang sudah pernah dibuat tetap punya induk yang jelas.
     */
    public function toggle(Request $request, RecurringRule $recurring_rule): RedirectResponse
    {
        $this->authorizeEditData();

        $recurring_rule->is_active = ! $recurring_rule->is_active;
        $recurring_rule->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $recurring_rule->is_active
                ? __('Aturan diaktifkan kembali.')
                : __('Aturan dinonaktifkan.'),
        ]);

        return to_route('recurring-rules.index');
    }

    /*
     |--------------------------------------------------------------------------
     | Payload Inertia
     |--------------------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>
     */
    private function present(RecurringRule $rule): array
    {
        return [
            'id' => $rule->id,
            'type' => $rule->type->value,
            'type_label' => $rule->type->label(),
            'amount' => $rule->amount,
            'note' => $rule->note,
            'frequency' => $rule->frequency->value,
            'frequency_label' => $rule->frequency->label(),
            'next_run_at' => $rule->next_run_at->toDateString(),
            'end_date' => $rule->end_date?->toDateString(),
            'requires_confirmation' => $rule->requires_confirmation,
            'is_active' => $rule->is_active,
            'last_generated_at' => $rule->last_generated_at?->toDateString(),
            'account' => $rule->account?->only(['id', 'name']),
            'transfer_to_account' => $rule->transferToAccount?->only(['id', 'name']),
            'category' => $rule->category?->only(['id', 'name', 'color']),
            'tag_ids' => $rule->tag_ids ?? [],
            'tags' => Tag::query()
                ->whereIn('id', $rule->tag_ids ?? [])
                ->get(['id', 'name'])
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Opsi form (akun, kategori, tag, frekuensi) untuk membuat rule baru.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'accounts' => Account::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                ])
                ->all(),
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])
                ->all(),
            'tags' => Tag::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->values()
                ->all(),
            'frequencies' => array_map(
                fn (RecurringFrequency $frequency): array => [
                    'value' => $frequency->value,
                    'label' => $frequency->label(),
                ],
                RecurringFrequency::cases(),
            ),
        ];
    }
}
