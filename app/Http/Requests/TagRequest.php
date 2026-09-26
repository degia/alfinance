<?php

namespace App\Http\Requests;

use App\Models\Tag;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
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
        $tag = $this->route('tag');

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:30',
                Rule::unique('tags', 'name')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($tag instanceof Tag ? $tag->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Nama tag wajib diisi.'),
            'name.min' => __('Nama tag minimal 2 karakter.'),
            'name.max' => __('Nama tag maksimal 30 karakter.'),
            'name.unique' => __('Tag dengan nama tersebut sudah ada.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('nama tag'),
        ];
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
