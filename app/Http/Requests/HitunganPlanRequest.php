<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi penyimpanan rencana halaman Hitungan.
 *
 * Semua nominal boleh kosong (dianggap nol) karena form-nya adalah kalkulator:
 * user mungkin hanya mengisi sebagian. Yang penting, kalau diisi, nilainya
 * harus desimal non-negatif. Karena tanggal mulai selalu hari ini, `end_date`
 * tidak boleh sebelum hari ini.
 */
class HitunganPlanRequest extends FormRequest
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
            'daily_limit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'end_date' => ['nullable', 'date', 'after_or_equal:today'],
            'rows' => ['present', 'array'],
            'rows.*.name' => ['nullable', 'string', 'max:120'],
            'rows.*.plan' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'rows.*.actual' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'daily_limit.decimal' => __('Limit harian harus berupa angka.'),
            'end_date.date' => __('Tanggal berakhir tidak valid.'),
            'end_date.after_or_equal' => __('Tanggal berakhir tidak boleh sebelum hari ini.'),
            'rows.present' => __('Daftar rencana wajib dikirim.'),
            'rows.*.name.max' => __('Nama rencana maksimal :max karakter.'),
            'rows.*.plan.decimal' => __('Nominal rencana harus berupa angka.'),
            'rows.*.actual.decimal' => __('Nominal aktual harus berupa angka.'),
        ];
    }

    /**
     * Atribut siap simpan: nominal dinormalkan ke dua desimal, tanggal null
     * bila kosong, dan baris rencana dibersihkan agar hanya berisi tiga kunci
     * yang dikenal.
     *
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        return [
            'daily_limit' => $this->money('daily_limit'),
            'end_date' => $this->filled('end_date') ? (string) $this->validated('end_date') : null,
            'rows' => $this->rows(),
        ];
    }

    /**
     * @return array<int, array{name: string, plan: string, actual: string}>
     */
    private function rows(): array
    {
        /** @var array<int, mixed> $rows */
        $rows = $this->validated('rows', []);
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'plan' => is_string($row['plan'] ?? null) ? $this->normalizeMoney($row['plan']) : '0.00',
                'actual' => is_string($row['actual'] ?? null) ? $this->normalizeMoney($row['actual']) : '0.00',
            ];
        }

        return $normalized;
    }

    private function money(string $key): string
    {
        $value = $this->validated($key);

        return is_string($value) ? $this->normalizeMoney($value) : '0.00';
    }

    private function normalizeMoney(string $value): string
    {
        return Money::fromCents(Money::toCents($value));
    }
}
