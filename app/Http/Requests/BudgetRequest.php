<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Closure;
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
            // Limit opsional untuk tiap sub-kategori dari kategori terpilih.
            'sub_limits' => [
                'sometimes',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    $parentId = (int) $this->input('category_id');
                    $childIds = Category::query()
                        ->where('workspace_id', $this->workspaceId())
                        ->where('parent_id', $parentId)
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();

                    foreach (array_keys($value) as $categoryId) {
                        if (! in_array((int) $categoryId, $childIds, true)) {
                            $fail(__('Sub-kategori tidak valid untuk kategori yang dipilih.'));

                            return;
                        }
                    }
                },
            ],
            'sub_limits.*' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
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
            'sub_limits.*.min' => __('Limit sub-kategori harus lebih besar dari nol.'),
            'sub_limits.*.max' => __('Limit sub-kategori melebihi batas maksimum yang disimpan.'),
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
            'sub_limits.*' => __('limit sub-kategori'),
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

    /**
     * Limit sub-kategori yang diisi pengguna; entri kosong dibuang.
     *
     * @return array<int, string> `category_id => limit`
     */
    public function subLimits(): array
    {
        $validated = $this->validated();
        $raw = $validated['sub_limits'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $limits = [];

        foreach ($raw as $categoryId => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }

            $limits[(int) $categoryId] = (string) $amount;
        }

        return $limits;
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
