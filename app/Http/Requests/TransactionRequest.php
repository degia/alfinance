<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi create/update transaksi (PRD.md §3.3).
 *
 * Aturan kondisional dipakai agar tipe transaksi konsisten:
 * - income/expense tidak boleh punya akun tujuan, kategori opsional
 *   (dipakai sebagai dimensi laporan, bukan kolom wajib);
 * - transfer wajib akun tujuan yang berbeda dari akun sumber dan tidak berkategori.
 */
class TransactionRequest extends FormRequest
{
    /**
     * Batas ukuran lampiran bukti: 2 MB (ARCHITECTURE.md §5).
     */
    private const ATTACHMENT_MAX_KB = 2048;

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
            'occurred_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],

            // Kategori hanya relevan untuk income/expense.
            'category_id' => [
                $isTransfer ? 'prohibited' : 'nullable',
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],

            // Akun tujuan hanya relevan untuk transfer, dan harus berbeda
            // dari akun sumber supaya transfer ke diri sendiri tertangkap.
            'transfer_to_account_id' => [
                $isTransfer ? 'required' : 'prohibited',
                'different:account_id',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],

            'tag_ids' => ['nullable', 'array', 'max:10'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('workspace_id', $workspaceId),
            ],

            'attachment' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf',
                'max:'.self::ATTACHMENT_MAX_KB,
            ],
            'remove_attachment' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required' => __('Akun wajib dipilih.'),
            'account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
            'type.in' => __('Tipe transaksi tidak dikenal.'),
            'amount.required' => __('Nominal wajib diisi.'),
            'amount.decimal' => __('Nominal maksimal dua angka desimal.'),
            'amount.min' => __('Nominal harus lebih besar dari nol.'),
            'occurred_at.required' => __('Tanggal transaksi wajib diisi.'),
            'occurred_at.date' => __('Tanggal transaksi tidak valid.'),
            'note.max' => __('Catatan maksimal 255 karakter.'),
            'category_id.prohibited' => __('Transfer antar akun tidak memakai kategori.'),
            'category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
            'transfer_to_account_id.required' => __('Transfer wajib memilih akun tujuan.'),
            'transfer_to_account_id.prohibited' => __('Hanya transfer yang punya akun tujuan.'),
            'transfer_to_account_id.different' => __('Akun tujuan harus berbeda dari akun sumber.'),
            'transfer_to_account_id.exists' => __('Akun tujuan tidak ditemukan di workspace ini.'),
            'tag_ids.max' => __('Maksimal 10 tag per transaksi.'),
            'tag_ids.*.exists' => __('Tag tidak ditemukan di workspace ini.'),
            'attachment.mimes' => __('Lampiran harus gambar (jpg, png, webp) atau PDF.'),
            'attachment.max' => __('Ukuran lampiran maksimal 2 MB.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_id' => __('akun'),
            'type' => __('tipe transaksi'),
            'amount' => __('nominal'),
            'occurred_at' => __('tanggal transaksi'),
            'note' => __('catatan'),
            'category_id' => __('kategori'),
            'transfer_to_account_id' => __('akun tujuan'),
            'tag_ids' => __('tag'),
            'attachment' => __('lampiran'),
        ];
    }

    /**
     * Atribut siap simpan, sudah dinormalisasi.
     *
     * @return array<string, mixed>
     */
    public function transactionAttributes(): array
    {
        $type = $this->type();
        $isTransfer = $type?->isTransfer() ?? false;

        return [
            'account_id' => (int) $this->validated('account_id'),
            'transfer_to_account_id' => $isTransfer
                ? (int) $this->validated('transfer_to_account_id')
                : null,
            'category_id' => $isTransfer
                ? null
                : $this->validated('category_id'),
            'type' => $type,
            'amount' => (string) $this->validated('amount'),
            'note' => $this->validated('note'),
            'occurred_at' => CarbonImmutable::parse((string) $this->validated('occurred_at')),
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

    public function shouldRemoveAttachment(): bool
    {
        return $this->boolean('remove_attachment');
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
