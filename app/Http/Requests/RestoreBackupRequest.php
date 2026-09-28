<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Konfirmasi restore backup.
 *
 * Tidak ada badan request — semua state (file, workspace target) sudah
 * tertanam di model `{backup}` yang dilewati route. Dikenai rate limiter
 * `backups.restore` di route.
 */
class RestoreBackupRequest extends FormRequest
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
        return [];
    }
}
