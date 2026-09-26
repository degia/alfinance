<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:60',
                Rule::unique('workspaces', 'name')->where(
                    fn ($query) => $query->whereIn(
                        'id',
                        $this->user()->workspaces()->select('workspaces.id'),
                    ),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Nama workspace wajib diisi.'),
            'name.min' => __('Nama workspace minimal 3 karakter.'),
            'name.max' => __('Nama workspace maksimal 60 karakter.'),
            'name.unique' => __('Anda sudah memiliki workspace dengan nama tersebut.'),
        ];
    }
}
