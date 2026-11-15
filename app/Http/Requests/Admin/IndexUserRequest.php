<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter daftar pengguna di panel admin.
 *
 * Kolom sort divalidasi lewat allow-list (bukan sekadar `string`) karena
 * nilainya masuk langsung ke `orderBy` — tanpa itu query string bisa
 * mengurutkan kolom sensitif seperti `password` atau `two_factor_secret`.
 */
class IndexUserRequest extends FormRequest
{
    /**
     * Kolom yang boleh dipakai mengurutkan daftar.
     */
    public const array SORTABLE = [
        'name',
        'email',
        'created_at',
        'last_login_at',
        'last_seen_at',
    ];

    public const array STATUSES = [
        'all',
        'online',
        'admin',
        'suspended',
        'unverified',
        'never_logged_in',
    ];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function searchTerm(): ?string
    {
        $search = trim((string) $this->string('search'));

        return $search === '' ? null : $search;
    }

    public function status(): string
    {
        return $this->string('status')->value() ?: 'all';
    }

    public function sortColumn(): string
    {
        return $this->string('sort')->value() ?: 'created_at';
    }

    /**
     * @return 'asc'|'desc'
     */
    public function sortDirection(): string
    {
        return $this->string('direction')->value() === 'asc' ? 'asc' : 'desc';
    }
}
