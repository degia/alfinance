<?php

namespace App\Http\Controllers;

use App\Enums\CategoryIcon;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use AuthorizesWorkspaceData;

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        return Inertia::render('categories/Index', [
            'categories' => $this->tree(),
            'parents' => Category::query()
                ->roots()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])
                ->values()
                ->all(),
            'icons' => array_map(
                fn (CategoryIcon $icon): array => [
                    'value' => $icon->value,
                    'label' => $icon->name,
                ],
                CategoryIcon::cases(),
            ),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $category = Category::create($request->categoryAttributes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Kategori :name berhasil dibuat.', ['name' => $category->name]),
        ]);

        return to_route('categories.index');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorizeEditData();

        $category->update($request->categoryAttributes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Kategori :name berhasil diperbarui.', ['name' => $category->name]),
        ]);

        return to_route('categories.index');
    }

    /**
     * Kategori induk yang masih punya sub-kategori tidak boleh dihapus, agar
     * transaksi yang sudah memakai sub-kategori itu tidak kehilangan induknya.
     */
    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeEditData();

        if ($category->hasChildren()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Kategori :name masih punya sub-kategori. Hapus sub-kategorinya dulu.', [
                    'name' => $category->name,
                ]),
            ]);

            return to_route('categories.index');
        }

        $name = $category->name;
        $category->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Kategori :name berhasil dihapus.', ['name' => $name]),
        ]);

        return to_route('categories.index');
    }

    /**
     * @return array<int, array{id: int, name: string, icon: string, color: string, parent_id: int|null, children: array<int, array{id: int, name: string, icon: string, color: string, parent_id: int|null}>}>
     */
    private function tree(): array
    {
        $all = Category::query()
            ->orderBy('name')
            ->get();

        $childrenByParent = [];

        foreach ($all as $category) {
            if ($category->parent_id !== null) {
                $childrenByParent[$category->parent_id][] = $this->present($category);
            }
        }

        return $all
            ->filter(fn (Category $category): bool => $category->isRoot())
            ->map(fn (Category $root): array => [
                ...$this->present($root),
                'children' => $childrenByParent[$root->id] ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, icon: string, color: string, parent_id: int|null}
     */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon->value,
            'color' => $category->color,
            'parent_id' => $category->parent_id,
        ];
    }
}
