<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Models\Account;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi create & update akun.
 *
 * Satu request dipakai untuk dua keperluan supaya aturan (termasuk aturan
 * khusus kartu kredit) tidak pernah berbeda antara store dan update.
 */
class AccountRequest extends FormRequest
{
    /**
     * Otorisasi ditangani controller lewat {@see AuthorizesWorkspaceData};
     * di sini cukup memastikan user sudah login.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $workspaceId = $this->workspaceId();
        $type = AccountType::tryFrom((string) $this->input('type'));
        $isCredit = $type?->requiresCreditLimit() ?? false;

        $initialBalance = ['required', 'decimal:0,2', 'max:9999999999999.99'];

        // Saldo awal hanya boleh minus untuk akun liabilitas (kartu kredit);
        // kas, bank, dan e-wallet tidak boleh overdraft.
        if ($type?->allowsNegativeBalance() ?? false) {
            $initialBalance[] = 'min:-9999999999999.99';
        } else {
            $initialBalance[] = 'min:0';
        }

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('accounts', 'name')
                    ->where('workspace_id', $workspaceId)
                    ->ignore($account instanceof Account ? $account->id : null),
            ],
            'type' => ['required', Rule::in(AccountType::values())],
            'initial_balance' => $initialBalance,
            'credit_limit' => $isCredit
                ? ['required', 'decimal:0,2', 'gt:0']
                : ['nullable'],
            'billing_day' => $isCredit
                ? ['required', 'integer', 'between:1,31']
                : ['nullable'],
            'due_day' => $isCredit
                ? ['required', 'integer', 'between:1,31']
                : ['nullable'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Nama akun wajib diisi.'),
            'name.min' => __('Nama akun minimal 2 karakter.'),
            'name.max' => __('Nama akun maksimal 60 karakter.'),
            'name.unique' => __('Sudah ada akun dengan nama tersebut di workspace ini.'),
            'type.required' => __('Tipe akun wajib dipilih.'),
            'type.in' => __('Tipe akun tidak dikenal.'),
            'initial_balance.required' => __('Saldo awal wajib diisi.'),
            'initial_balance.decimal' => __('Saldo awal harus berupa angka dengan maksimal 2 desimal.'),
            'initial_balance.min' => __('Saldo awal tidak boleh minus untuk tipe akun ini.'),
            'credit_limit.required' => __('Limit kredit wajib diisi untuk kartu kredit.'),
            'credit_limit.gt' => __('Limit kredit harus lebih besar dari 0.'),
            'billing_day.required' => __('Tanggal cetak tagihan wajib diisi untuk kartu kredit.'),
            'due_day.required' => __('Tanggal jatuh tempo wajib diisi untuk kartu kredit.'),
            'billing_day.between' => __('Tanggal cetak tagihan harus antara 1 dan 31.'),
            'due_day.between' => __('Tanggal jatuh tempo harus antara 1 dan 31.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('nama akun'),
            'type' => __('tipe akun'),
            'initial_balance' => __('saldo awal'),
            'credit_limit' => __('limit kredit'),
            'billing_day' => __('tanggal cetak tagihan'),
            'due_day' => __('tanggal jatuh tempo'),
            'notes' => __('catatan'),
        ];
    }

    /**
     * Bersihkan input sebelum dipakai controller.
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        $isCredit = AccountType::tryFrom((string) $this->input('type'))?->requiresCreditLimit() ?? false;

        return [
            'type' => (string) $this->input('type'),
            'name' => (string) $this->input('name'),
            'initial_balance' => (string) $this->input('initial_balance'),
            'credit_limit' => $isCredit ? (string) $this->input('credit_limit') : null,
            'billing_day' => $isCredit ? (int) $this->input('billing_day') : null,
            'due_day' => $isCredit ? (int) $this->input('due_day') : null,
            'notes' => $this->input('notes') !== null ? (string) $this->input('notes') : null,
        ];
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
