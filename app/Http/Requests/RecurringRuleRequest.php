<?php

namespace App\Http\Requests;

use App\Enums\RecurringFrequency;
use App\Enums\TransactionType;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi aturan transaksi berulang (PRD.md §3.3).
 *
 * field transaksi (akun, tipe, nominal, kategori/tag) mengikuti aturan yang
 * sama dengan {@see TransactionRequest}; sisanya mengatur jadwal.
 */
class RecurringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workspaceId = $this->workspaceId();
        $type = $this->type();
        $isTransfer = $type?->isTransfer() ?? false;

        return [
            'account_id' => [
                'required',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'type' => ['required', Rule::in(TransactionType::values())],
            'amount' => ['required', 'decimal:0,2', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],

            // `nullable` selalu ikut pada kedua field, termasuk di cabang `prohibited`:
            // form mengirim string kosong yang diubah jadi `null` oleh
            // `ConvertEmptyStringsToNull`, dan tanpa `nullable` aturan `exists`
            // akan ikut dijalankan terhadap `null`. Lihat TransactionRequest
            // untuk penjelasan lengkap.
            'category_id' => [
                'nullable',
                ...($isTransfer ? ['prohibited'] : []),
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],
            'transfer_to_account_id' => [
                'nullable',
                ...($isTransfer ? ['required'] : ['prohibited']),
                'different:account_id',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'tag_ids' => ['nullable', 'array', 'max:10'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('workspace_id', $workspaceId),
            ],

            'frequency' => ['required', Rule::in(RecurringFrequency::values())],
            'next_run_at' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:next_run_at'],
            'requires_confirmation' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required' => __('Akun wajib dipilih.'),
            'amount.min' => __('Nominal harus lebih besar dari nol.'),
            'category_id.prohibited' => __('Transfer antar akun tidak memakai kategori.'),
            'transfer_to_account_id.required' => __('Transfer wajib memilih akun tujuan.'),
            'transfer_to_account_id.prohibited' => __('Hanya transfer yang punya akun tujuan.'),
            'transfer_to_account_id.different' => __('Akun tujuan harus berbeda dari akun sumber.'),
            'frequency.in' => __('Frekuensi transaksi berulang tidak dikenal.'),
            'next_run_at.required' => __('Tanggal transaksi berikutnya wajib diisi.'),
            'end_date.after_or_equal' => __('Tanggal akhir tidak boleh sebelum tanggal transaksi berikutnya.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'account_id' => __('akun'),
            'amount' => __('nominal'),
            'category_id' => __('kategori'),
            'transfer_to_account_id' => __('akun tujuan'),
            'frequency' => __('frekuensi'),
            'next_run_at' => __('tanggal transaksi berikutnya'),
            'end_date' => __('tanggal akhir'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $type = $this->type();
        $isTransfer = $type?->isTransfer() ?? false;

        return [
            'account_id' => (int) $this->validated('account_id'),
            'transfer_to_account_id' => $isTransfer
                ? (int) $this->validated('transfer_to_account_id')
                : null,
            'category_id' => $isTransfer ? null : $this->validated('category_id'),
            'type' => $type,
            'amount' => (string) $this->validated('amount'),
            'note' => $this->validated('note'),
            'tag_ids' => $this->tagIds(),
            'frequency' => RecurringFrequency::from((string) $this->validated('frequency')),
            'next_run_at' => CarbonImmutable::parse((string) $this->validated('next_run_at')),
            'end_date' => $this->filled('end_date')
                ? CarbonImmutable::parse((string) $this->validated('end_date'))
                : null,
            'requires_confirmation' => $this->boolean('requires_confirmation'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function tagIds(): array
    {
        $tagIds = $this->validated('tag_ids') ?? [];

        return array_map('intval', is_array($tagIds) ? $tagIds : [$tagIds]);
    }

    private function type(): ?TransactionType
    {
        $value = $this->input('type');

        return is_string($value) ? TransactionType::tryFrom($value) : null;
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
