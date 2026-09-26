<?php

namespace App\Http\Requests;

use App\Enums\CategoryIcon;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi create & update kategori (dua level).
 *
 * Catatan soal unik: MySQL menganggap dua NULL pada unique index selalu
 * berbeda, jadi unique index di level DB tidak bisa dipakai untuk aturan
 * "nama unik per level". Aturan ini karena itu dijaga di sini, memakai
 * `Rule::unique` yang difilter eksplisit berdasarkan `parent_id`.
 */
class CategoryRequest extends FormRequest
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
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : null;
        $workspaceId = $this->workspaceId();
        $parentId = $this->parentId();

        // Induk wajib root (parent_id NULL) supaya depth maksimal dua level.
        // `Exists` tidak punya `ignore()`, jadi pengecualian "tidak boleh jadi
        // induk dirinya sendiri" ditulis lewat `whereNot`.
        $parentRule = Rule::exists('categories', 'id')
            ->where('workspace_id', $workspaceId)
            ->whereNull('parent_id');

        if ($categoryId !== null) {
            $parentRule->whereNot('id', $categoryId);
        }

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('categories', 'name')
                    ->where('workspace_id', $workspaceId)
                    ->where(fn ($query) => $parentId === null
                        ? $query->whereNull('parent_id')
                        : $query->where('parent_id', $parentId))
                    ->ignore($categoryId),
            ],
            'parent_id' => ['nullable', $parentRule],
            'icon' => ['required', Rule::in(CategoryIcon::values())],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Nama kategori wajib diisi.'),
            'name.min' => __('Nama kategori minimal 2 karakter.'),
            'name.max' => __('Nama kategori maksimal 60 karakter.'),
            'name.unique' => __('Sudah ada kategori dengan nama tersebut di level ini.'),
            'parent_id.exists' => __('Kategori induk harus kategori utama di workspace ini.'),
            'icon.required' => __('Ikon wajib dipilih.'),
            'icon.in' => __('Ikon tidak dikenal.'),
            'color.required' => __('Warna wajib dipilih.'),
            'color.regex' => __('Warna harus berupa hex, contoh #2563eb.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('nama kategori'),
            'parent_id' => __('kategori induk'),
            'icon' => __('ikon'),
            'color' => __('warna'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryAttributes(): array
    {
        return [
            'name' => (string) $this->input('name'),
            'parent_id' => $this->parentId(),
            'icon' => (string) $this->input('icon'),
            'color' => strtolower((string) $this->input('color')),
        ];
    }

    private function parentId(): ?int
    {
        $parentId = $this->input('parent_id');

        return $parentId === null || $parentId === '' ? null : (int) $parentId;
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
