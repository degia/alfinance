<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi unggahan berkas backup JSON untuk diimpor (PRD.md §3.11).
 *
 * Validasi di sini hanya soal bentuk berkas; isi & skema payload diperiksa
 * oleh {@see \App\Services\Backups\BackupService::import()} sebelum dicatat.
 */
class ImportBackupRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:json', 'max:51200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('Pilih berkas backup (.json) yang ingin diimpor.'),
            'file.file' => __('Berkas backup gagal diunggah.'),
            'file.mimes' => __('Berkas backup harus berformat JSON.'),
            'file.max' => __('Ukuran berkas backup maksimal 50 MB.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => __('berkas backup'),
        ];
    }
}
