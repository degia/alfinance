<?php

namespace App\Support;

/**
 * Aritmetika nominal uang tanpa float.
 *
 * Seluruh nominal Alfinance disimpan sebagai `DECIMAL(15,2)` dan di-cast
 * `decimal:2`, jadi Atribut model menerima string ("1500000.50"), bukan
 * float. Operasi di sini memecah string menjadi integer "sen" (cents),
 * melakukan aritmetika bulat, lalu memformat kembali ke dua desimal — presisi
 * 2 desimal terjaga penuh untuk nilai hingga 9.999.999.999.999,99.
 *
 * Parameter nominal sengaja TIDAK menerima `float`: presisi float sudah
 * hilang sebelum helper ini dipanggil.
 */
final class Money
{
    /**
     * Ubah nominal desimal menjadi integer sen.
     */
    public static function toCents(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $normalized = str_replace([' ', ','], '', (string) $value);

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');

        if (! str_contains($normalized, '.')) {
            $normalized .= '.00';
        }

        [$whole, $fraction] = explode('.', $normalized, 2);

        // fraction dipotong (bukan dibulatkan) ke 2 desimal; input sudah
        // divalidasi dengan aturan `decimal:0,2` di FormRequest.
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    /**
     * Format integer sen menjadi string desimal dua angka di belakang koma.
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(string|int|null $augend, string|int|null $addend): string
    {
        return self::fromCents(self::toCents($augend) + self::toCents($addend));
    }

    public static function subtract(string|int|null $minuend, string|int|null $subtrahend): string
    {
        return self::fromCents(self::toCents($minuend) - self::toCents($subtrahend));
    }

    public static function absolute(string|int|null $value): string
    {
        return self::fromCents(abs(self::toCents($value)));
    }

    public static function isNegative(string|int|null $value): bool
    {
        return self::toCents($value) < 0;
    }

    /**
     * Kembalikan `0.00` bila nilai negatif, selain itu nilai aslinya.
     */
    public static function atLeastZero(string|int|null $value): string
    {
        return self::toCents($value) < 0 ? self::fromCents(0) : self::fromCents(self::toCents($value));
    }

    /**
     * Persentase 0–100 (satu desimal) dari `part` terhadap `whole`.
     *
     * Rasio ini sengaja dikembalikan sebagai float karena bukan nominal uang,
     * hanya angka presentasi untuk progress bar. Null bila `whole` nol.
     */
    public static function percentageOf(string|int|null $part, string|int|null $whole): ?float
    {
        $wholeCents = self::toCents($whole);

        if ($wholeCents === 0) {
            return null;
        }

        // x1000 lalu /10 menghasilkan satu angka desimal tanpa pembulatan
        // di tengah; pembulatan dilakukan sekali di akhir.
        $perMille = intdiv(
            self::toCents($part) * 1000 + intdiv($wholeCents, 2),
            $wholeCents
        );

        return round(min(max($perMille / 10, 0), 100), 1);
    }
}
