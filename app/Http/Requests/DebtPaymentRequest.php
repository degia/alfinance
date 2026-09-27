<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi satu cicilan utang/piutang (PRD.md §3.7).
 *
 * Dua mode dalam satu form:
 * - hanya mencatat cicilan (`create_transaction` kosong), atau
 * - mencatat cicilan sekaligus membuat expense/income di akun terkait.
 *
 * Batas "nominal tidak melebihi sisa" tidak divalidasi di sini karena
 * `remaining` bisa berubah di antara render form dan submit; {@see
 * \App\Services\Debt\DebtService} memeriksanya di dalam DB transaction yang
 * sama dengan penulisan pembayarannya.
 */
class DebtPaymentRequest extends FormRequest
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
            'amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],

            'create_transaction' => ['nullable', 'boolean'],
            'transaction' => ['nullable', 'array'],
            'transaction.account_id' => [
                'required_if:create_transaction,1',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'transaction.category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],
            'transaction.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => __('Nominal cicilan wajib diisi.'),
            'amount.min' => __('Nominal cicilan harus lebih besar dari nol.'),
            'paid_at.required' => __('Tanggal bayar wajib diisi.'),
            'paid_at.before_or_equal' => __('Tanggal bayar tidak boleh di masa depan.'),
            'transaction.account_id.required_if' => __('Pilih akun kalau cicilan ini ikut dicatat sebagai transaksi.'),
            'transaction.account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
            'transaction.category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => __('nominal cicilan'),
            'paid_at' => __('tanggal bayar'),
            'note' => __('catatan'),
            'transaction.account_id' => __('akun'),
            'transaction.category_id' => __('kategori'),
        ];
    }

    /**
     * @return array{amount: string, paid_at: string, note: string|null, transaction: array<string, mixed>|null}
     */
    public function paymentAttributes(): array
    {
        $transaction = $this->boolean('create_transaction')
            ? [
                'account_id' => (int) $this->validated('transaction.account_id'),
                'category_id' => $this->validated('transaction.category_id') !== null
                    ? (int) $this->validated('transaction.category_id')
                    : null,
                'note' => $this->validated('transaction.note'),
                'amount' => (string) $this->validated('amount'),
            ]
            : null;

        return [
            'amount' => (string) $this->validated('amount'),
            'paid_at' => (string) $this->validated('paid_at'),
            'note' => $this->validated('note'),
            'transaction' => $transaction,
        ];
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
