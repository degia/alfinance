<?php

namespace App\Http\Requests;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi query filter halaman transaksi (PRD.md §3.3 "Filter & pencarian").
 *
 * Semua nilai di sini hanya memengaruhi `withQuery()`/scope pada
 * {@see Transaction}; tidak ada efek samping ke saldo.
 */
class TransactionFilterRequest extends FormRequest
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            'account_id' => [
                'nullable',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],
            'tag_id' => [
                'nullable',
                Rule::exists('tags', 'id')->where('workspace_id', $workspaceId),
            ],

            'type' => ['nullable', Rule::in(TransactionType::values())],
            'status' => ['nullable', Rule::in(TransactionStatus::values())],

            'amount_min' => ['nullable', 'decimal:0,2', 'min:0'],
            'amount_max' => ['nullable', 'decimal:0,2', 'gte:amount_min'],

            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('Tanggal akhir tidak boleh sebelum tanggal mulai.'),
            'account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
            'category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
            'tag_id.exists' => __('Tag tidak ditemukan di workspace ini.'),
            'type.in' => __('Tipe transaksi tidak dikenal.'),
            'status.in' => __('Status transaksi tidak dikenal.'),
            'amount_max.gte' => __('Nominal maksimum harus lebih besar dari nominal minimum.'),
            'per_page.max' => __('Maksimal 100 baris per halaman.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'from' => __('tanggal mulai'),
            'to' => __('tanggal akhir'),
            'account_id' => __('akun'),
            'category_id' => __('kategori'),
            'tag_id' => __('tag'),
            'type' => __('tipe transaksi'),
            'status' => __('status'),
            'amount_min' => __('nominal minimum'),
            'amount_max' => __('nominal maksimum'),
            'search' => __('pencarian'),
        ];
    }

    public function accountId(): ?int
    {
        return $this->filled('account_id') ? (int) $this->validated('account_id') : null;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? (int) $this->validated('category_id') : null;
    }

    public function tagId(): ?int
    {
        return $this->filled('tag_id') ? (int) $this->validated('tag_id') : null;
    }

    public function type(): ?TransactionType
    {
        $value = $this->input('type');

        return is_string($value) ? TransactionType::tryFrom($value) : null;
    }

    public function status(): ?TransactionStatus
    {
        $value = $this->input('status');

        return is_string($value) ? TransactionStatus::tryFrom($value) : null;
    }

    public function from(): ?string
    {
        $value = $this->validated('from');

        return is_string($value) ? $value : null;
    }

    public function to(): ?string
    {
        $value = $this->validated('to');

        return is_string($value) ? $value : null;
    }

    public function amountMin(): ?string
    {
        $value = $this->validated('amount_min');

        return is_string($value) ? $value : null;
    }

    public function amountMax(): ?string
    {
        $value = $this->validated('amount_max');

        return is_string($value) ? $value : null;
    }

    public function search(): ?string
    {
        $value = trim((string) $this->validated('search'));

        return $value === '' ? null : $value;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
