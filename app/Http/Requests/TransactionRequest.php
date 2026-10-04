<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Workspace;
use App\Services\Debt\DebtPaymentManager;
use App\Services\Transactions\AdminFeeManager;
use App\Support\Money;
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
 *
 * `debt_id` (Bayar utang) hanya berlaku untuk expense, dan hanya untuk utang
 * milik workspace yang sama — sisa utangnya dikurangi lewat
 * {@see DebtPaymentManager}, bukan lewat kolom di baris
 * transaksi.
 *
 * `admin_fee` (potongan admin) juga hanya berlaku untuk transfer: nilainya tidak
 * disimpan di baris transfer, tapi dicatat sebagai baris `expense` terpisah
 * (lihat {@see AdminFeeManager}). `admin_fee_category_id`.opsional mengikuti
 * baris itu — kosong berarti pakai kategori otomatis "Biaya Admin".
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
        $isExpense = $type === TransactionType::Expense;

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
            //
            // `nullable` selalu ikut, termasuk di cabang `prohibited`: form Vue
            // mengirim `category_id: ''` dan middleware `ConvertEmptyStringsToNull`
            // mengubahnya jadi `null` sebelum validasi. Tanpa `nullable`, Laravel
            // tetap menjalankan aturan `exists` terhadap `null` sehingga expense
            // biasa ditolak dengan pesan "Kategori tidak ditemukan di workspace ini."
            // `prohibited` sendiri tetap berlaku karena ia menolak nilai yang
            // benar-benar terisi.
            'category_id' => [
                'nullable',
                ...($isTransfer ? ['prohibited'] : []),
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],

            // Akun tujuan hanya relevan untuk transfer, dan harus berbeda
            // dari akun sumber supaya transfer ke diri sendiri tertangkap.
            //
            // `nullable` selalu ikut dengan alasan yang sama seperti
            // `category_id` di atas: field kosong dari form menjadi `null`, dan
            // tanpa `nullable` aturan `exists` akan salah lensanya. Saat transfer,
            // `required` tetap menagih nilainya.
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

            // Potongan admin hanya untuk transfer, dan opsional — form selalu
            // mengirim field ini, jadi `nullable` wajib agar string kosong dari
            // form (yang jadi `null` setelah `ConvertEmptyStringsToNull`) lolos
            // alih-alih ditolak aturan `decimal`.
            'admin_fee' => [
                'nullable',
                ...($isTransfer ? [] : ['prohibited']),
                'decimal:0,2',
                'min:0.01',
                'max:99999999999',
            ],

            // Kategori untuk baris potongan admin. Opsional: kalau dikosongkan,
            // `AdminFeeManager` memakai/membuat kategori "Biaya Admin". Tetap
            // `nullable` di cabang `prohibited` dengan alasan yang sama seperti
            // `category_id`: form mengirim string kosong yang jadi `null`.
            'admin_fee_category_id' => [
                'nullable',
                ...($isTransfer ? [] : ['prohibited']),
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],

            // Utang yang dilunasi expense ini ("Bayar utang"). Validasi arah,
            // sisa, dan minimal nominal tetap di `DebtPaymentManager`, karena
            // sisa utang ikut berubah setiap ada cicilan baru.
            'debt_id' => [
                'nullable',
                ...($isExpense ? [] : ['prohibited']),
                Rule::exists('debts', 'id')->where('workspace_id', $workspaceId),
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
            'admin_fee.prohibited' => __('Hanya transfer yang punya potongan admin.'),
            'admin_fee.decimal' => __('Potongan admin maksimal dua angka desimal.'),
            'admin_fee.min' => __('Potongan admin harus lebih besar dari nol.'),
            'admin_fee.max' => __('Potongan admin terlalu besar.'),
            'admin_fee_category_id.prohibited' => __('Hanya transfer yang punya potongan admin.'),
            'admin_fee_category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
            'debt_id.prohibited' => __('Hanya pengeluaran yang bisa melunasi utang.'),
            'debt_id.exists' => __('Utang tidak ditemukan di workspace ini.'),
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
            'admin_fee' => __('potongan admin'),
            'admin_fee_category_id' => __('kategori potongan admin'),
            'debt_id' => __('utang'),
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
        $isExpense = $type === TransactionType::Expense;

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

    /**
     * Potongan admin dalam sen, atau null kalau tidak diisi.
     *
     * Dibalik ke sen supaya TransactionManager bebas menentukan formatnya;
     * input user sudah dibatasi dua desimal oleh aturan `decimal:0,2`.
     */
    public function adminFeeCents(): ?int
    {
        $value = $this->validated('admin_fee');

        if ($value === null || $value === '') {
            return null;
        }

        return Money::toCents($value);
    }

    /**
     * Kategori pilihan user untuk baris potongan admin, atau null untuk
     * memakai kategori otomatis "Biaya Admin".
     */
    public function adminFeeCategoryId(): ?int
    {
        $value = $this->validated('admin_fee_category_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Utang yang dilunasi expense ini, atau null kalau expense biasa.
     */
    public function debtId(): ?int
    {
        $value = $this->validated('debt_id');

        return $value === null || $value === '' ? null : (int) $value;
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
