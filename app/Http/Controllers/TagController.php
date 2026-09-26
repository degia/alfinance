<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\TagRequest;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    use AuthorizesWorkspaceData;

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        return Inertia::render('tags/Index', [
            'tags' => Tag::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(TagRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $tag = Tag::create(['name' => (string) $request->validated('name')]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Tag :name berhasil dibuat.', ['name' => $tag->name]),
        ]);

        return to_route('tags.index');
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $this->authorizeEditData();

        $tag->update(['name' => (string) $request->validated('name')]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Tag :name berhasil diperbarui.', ['name' => $tag->name]),
        ]);

        return to_route('tags.index');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        $this->authorizeEditData();

        $name = $tag->name;
        $tag->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Tag :name berhasil dihapus.', ['name' => $name]),
        ]);

        return to_route('tags.index');
    }
}
