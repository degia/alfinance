<?php

namespace App\Http\Requests;

use App\Enums\BackupScope;
use App\Models\Workspace;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi permintaan backup (PRD.md §3.11).
 */
class StoreBackupRequest extends FormRequest
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
        $this->workspaceId();

        return [
            'scope' => ['required', Rule::enum(BackupScope::class)],
            'from' => ['nullable', 'date', 'required_if:scope,range'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:scope,range'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.required' => __('Cakupan backup wajib dipilih.'),
            'scope.enum' => __('Cakupan backup tidak dikenal.'),
            'from.required_if' => __('Tanggal mulai wajib diisi untuk backup rentang.'),
            'to.required_if' => __('Tanggal akhir wajib diisi untuk backup rentang.'),
            'to.after_or_equal' => __('Tanggal akhir tidak boleh sebelum tanggal mulai.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => __('cakupan backup'),
            'from' => __('tanggal mulai'),
            'to' => __('tanggal akhir'),
        ];
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
