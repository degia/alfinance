<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi batas anggaran per kategori per bulan (PRD.md §3.5).
 *
 * `month` menerima "YYYY-MM" maupun "YYYY-MM-DD" dan selalu disimpan sebagai
 * tanggal pertama bulan, jadi satu kategori tidak bisa punya dua limit untuk
 * bulan yang sama dengan format berbeda.
 */
class BudgetRequest extends FormRequest
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
        return [
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('workspace_id', $this->workspaceId()),
            ],
            'month' => ['required', 'string', 'date_format:Y-m'],
            'limit_amount' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => __('Kategori wajib dipilih.'),
            'category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
            'month.required' => __('Bulan anggaran wajib diisi.'),
            'month.date_format' => __('Format bulan harus YYYY-MM, contoh 2026-09.'),
            'limit_amount.required' => __('Limit anggaran wajib diisi.'),
            'limit_amount.min' => __('Limit harus lebih besar dari nol.'),
            'limit_amount.max' => __('Limit melebihi batas maksimum yang disimpan.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => __('kategori'),
            'month' => __('bulan'),
            'limit_amount' => __('limit'),
        ];
    }

    /**
     * Atribut siap simpan: bulan sudah dinormalkan ke tanggal pertama.
     *
     * @return array{category_id: int, month: string, limit_amount: string}
     */
    public function budgetAttributes(): array
    {
        return [
            'category_id' => (int) $this->validated('category_id'),
            'month' => MonthPeriod::from((string) $this->validated('month'))->toDateString(),
            'limit_amount' => (string) $this->validated('limit_amount'),
        ];
    }

    public function category(): Category
    {
        return Category::query()->findOrFail((int) $this->validated('category_id'));
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
