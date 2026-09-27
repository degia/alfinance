<?php

namespace App\Http\Requests;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi utang/piutang (PRD.md §3.7).
 *
 * `include_in_net_worth` hanya relevan untuk satu arah: piutang (orang
 * berutang ke kita) otomatis menambah aset, jadi tidak perlu centang. Utang
 * (kita berutang) baru masuk kewajiban kalau dicentang, supaya user tidak
 * kaget saat net worth tiba-tiba turun.
 */
class DebtRequest extends FormRequest
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

        return [
            'direction' => ['required', Rule::in(DebtDirection::values())],
            'counterparty' => ['required', 'string', 'max:120'],
            'principal' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
            'interest_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:999.99'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'term_count' => ['nullable', 'integer', 'min:1', 'max:600'],
            'include_in_net_worth' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'account_id' => [
                'nullable',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],

            // Hanya relevan saat mengedit utang yang belum punya pembayaran;
            // service yang menolaknya supaya pesannya/domain rule-nya satu
            // tempat, bukan tercermin di dua form.
            'status' => ['nullable', Rule::in(DebtStatus::values())],
            'remaining' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direction.required' => __('Tipe utang wajib dipilih.'),
            'direction.in' => __('Tipe utang tidak dikenal.'),
            'counterparty.required' => __('Nama pihak (pemberi/peminjam) wajib diisi.'),
            'principal.required' => __('Pokok wajib diisi.'),
            'principal.min' => __('Pokok harus lebih besar dari nol.'),
            'interest_rate.min' => __('Suku bunga tidak boleh negatif.'),
            'due_date.after_or_equal' => __('Jatuh tempo tidak boleh sebelum tanggal mulai.'),
            'term_count.min' => __('Jumlah cicilan minimal 1.'),
            'term_count.max' => __('Jumlah cicilan terlalu banyak.'),
            'account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'direction' => __('tipe utang'),
            'counterparty' => __('pihak'),
            'principal' => __('pokok'),
            'interest_rate' => __('suku bunga'),
            'start_date' => __('tanggal mulai'),
            'due_date' => __('jatuh tempo'),
            'term_count' => __('jumlah cicilan'),
            'account_id' => __('akun pembayaran'),
            'include_in_net_worth' => __('masukkan ke net worth'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debtAttributes(): array
    {
        $direction = $this->direction() ?? DebtDirection::Payable;

        return [
            'direction' => $direction,
            'counterparty' => (string) $this->validated('counterparty'),
            'principal' => (string) $this->validated('principal'),
            'interest_rate' => $this->filled('interest_rate') ? (string) $this->validated('interest_rate') : null,
            'start_date' => $this->filled('start_date') ? (string) $this->validated('start_date') : null,
            'due_date' => $this->filled('due_date') ? (string) $this->validated('due_date') : null,
            'term_count' => $this->filled('term_count') ? (int) $this->validated('term_count') : null,
            // Piutang selalu dihitung sebagai aset — opt-in hanya bermakna
            // untuk utang yang menaikkan kewajiban.
            'include_in_net_worth' => $direction->isPayable()
                ? $this->boolean('include_in_net_worth')
                : true,
            'note' => $this->validated('note'),
            'account_id' => $this->filled('account_id') ? (int) $this->validated('account_id') : null,
        ];
    }

    /**
     * Arah saat ini; dipakai controller untuk menentukan arah form.
     */
    public function direction(): ?DebtDirection
    {
        $value = $this->input('direction');

        return is_string($value) ? DebtDirection::tryFrom($value) : null;
    }

    public function directionDefaultsToPayable(): bool
    {
        return $this->direction()?->isPayable() ?? true;
    }

    public function paymentTransactionType(): TransactionType
    {
        return $this->directionDefaultsToPayable() ? TransactionType::Expense : TransactionType::Income;
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
