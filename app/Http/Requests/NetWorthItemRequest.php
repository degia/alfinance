<?php

namespace App\Http\Requests;

use App\Enums\NetWorthItemType;
use App\Enums\NetWorthSubtype;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi item net worth (PRD.md §3.6).
 *
 * Aturan paling penting: `subtype` harus cocok dengan `type`, jadi form tidak
 * bisa menyimpan "properti" sebagai kewajiban. Validasi silang dilakukan di
 * sini (bukan hanya enum) karena input tetap berupa string dari form.
 *
 * `annual_rate` boleh kosong: nilai kosong berarti nilai tetap seperti yang
 * diinput, bukan 0%.
 */
class NetWorthItemRequest extends FormRequest
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
        $type = $this->type();

        $subtypeRule = $type instanceof NetWorthItemType
            ? Rule::in(array_map(
                static fn (NetWorthSubtype $subtype): string => $subtype->value,
                NetWorthSubtype::forType($type),
            ))
            : Rule::in(NetWorthSubtype::values());

        return [
            'type' => ['required', Rule::in(NetWorthItemType::values())],
            'subtype' => ['required', $subtypeRule],
            'name' => ['required', 'string', 'max:120'],
            'value' => ['required', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
            'annual_rate' => ['nullable', 'decimal:0,2', 'min:0', 'max:999.99'],
            'valued_at' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => __('Tipe item wajib dipilih.'),
            'type.in' => __('Tipe item tidak dikenal.'),
            'subtype.required' => __('Jenis item wajib dipilih.'),
            'subtype.in' => __('Jenis item tidak sesuai dengan tipenya.'),
            'name.required' => __('Nama item wajib diisi.'),
            'value.required' => __('Nilai item wajib diisi.'),
            'value.min' => __('Nilai harus lebih besar dari nol.'),
            'annual_rate.min' => __('Suku bunga tidak boleh negatif.'),
            'valued_at.required' => __('Tanggal nilai wajib diisi.'),
            'valued_at.before_or_equal' => __('Tanggal nilai tidak boleh di masa depan.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('tipe'),
            'subtype' => __('jenis'),
            'name' => __('nama'),
            'value' => __('nilai'),
            'annual_rate' => __('suku bunga tahunan'),
            'valued_at' => __('tanggal nilai'),
            'note' => __('catatan'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        return [
            'type' => NetWorthItemType::from((string) $this->validated('type')),
            'subtype' => NetWorthSubtype::from((string) $this->validated('subtype')),
            'name' => (string) $this->validated('name'),
            'value' => (string) $this->validated('value'),
            'annual_rate' => $this->filled('annual_rate') ? (string) $this->validated('annual_rate') : null,
            'valued_at' => CarbonImmutable::parse((string) $this->validated('valued_at'))->startOfDay(),
            'note' => $this->validated('note'),
        ];
    }

    private function type(): ?NetWorthItemType
    {
        $value = $this->input('type');

        return is_string($value) ? NetWorthItemType::tryFrom($value) : null;
    }
}
