<?php

namespace App\Http\Requests;

use App\Enums\ExportType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi permintaan ekspor (PRD.md §3.10).
 *
 * `type` menentukan jenis dan format berkas; sisanya adalah snapshot filter
 * yang dipakai saat job membangun berkas (transaksi ataupun laporan).
 */
class StoreExportRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ExportType::class)],

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

            'tx_type' => ['nullable', Rule::in(TransactionType::values())],
            'tx_status' => ['nullable', Rule::in(TransactionStatus::values())],

            'amount_min' => ['nullable', 'decimal:0,2', 'min:0'],
            'amount_max' => ['nullable', 'decimal:0,2', 'gte:amount_min'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => __('Jenis ekspor wajib dipilih.'),
            'type.enum' => __('Jenis ekspor tidak dikenal.'),
            'to.after_or_equal' => __('Tanggal akhir tidak boleh sebelum tanggal mulai.'),
            'account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
            'category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
            'tag_id.exists' => __('Tag tidak ditemukan di workspace ini.'),
            'amount_max.gte' => __('Nominal maksimum harus lebih besar dari nominal minimum.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('tanggal mulai'),
            'to' => __('tanggal akhir'),
            'account_id' => __('akun'),
            'category_id' => __('kategori'),
            'tag_id' => __('tag'),
        ];
    }

    /**
     * Filter yang di-snapshot ke `export_jobs.filters` (semua kecuali `type`).
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();

        unset($filters['type']);

        return $filters;
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
