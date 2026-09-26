<?php

namespace App\Enums;

/**
 * Kumpulan ikon yang boleh dipakai kategori (PRD.md §3.4).
 *
 * Ikon disimpan sebagai nama komponen lucide, lalu dipetakan ke komponen
 * Vue di `resources/js/components/CategoryIcon.vue`. Dengan begitu nama ikon
 * selalu valid (tidak ada string bebas yang bisa menggagalkan render chart)
 * dan tipe di frontend ikut ter-narrow.
 */
enum CategoryIcon: string
{
    case Utang = 'hand-coins';
    case Food = 'utensils';
    case Shopping = 'shopping-bag';
    case Transport = 'car';
    case Housing = 'house';
    case Utilities = 'zap';
    case Healthcare = 'heart-pulse';
    case Education = 'graduation-cap';
    case Entertainment = 'clapperboard';
    case Subscriptions = 'repeat';
    case Insurance = 'shield-check';
    case Savings = 'piggy-bank';
    case Investment = 'trending-up';
    case Salary = 'wallet';
    case Business = 'briefcase-business';
    case Gift = 'gift';
    case Family = 'baby';
    case Travel = 'plane';
    case Other = 'shapes';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Ikon default per nama kategori yang sering dipakai, supaya form
     * terisi pilihan yang masuk akal saat user membuat kategori baru.
     */
    public static function suggest(string $name): self
    {
        return match (true) {
            str_contains($name, 'gaji'), str_contains($name, 'salary') => self::Salary,
            str_contains($name, 'makan'), str_contains($name, 'kopi'), str_contains($name, 'food') => self::Food,
            str_contains($name, 'belanja') => self::Shopping,
            str_contains($name, 'transport'), str_contains($name, 'bensin'), str_contains($name, 'parkir') => self::Transport,
            str_contains($name, 'sewa'), str_contains($name, 'kontrakan'), str_contains($name, 'rumah') => self::Housing,
            str_contains($name, 'listrik'), str_contains($name, 'air'), str_contains($name, 'internet') => self::Utilities,
            str_contains($name, 'kesehatan'), str_contains($name, 'obat') => self::Healthcare,
            str_contains($name, 'pendidikan'), str_contains($name, 'sekolah') => self::Education,
            str_contains($name, 'hiburan'), str_contains($name, 'nonton') => self::Entertainment,
            str_contains($name, 'langganan'), str_contains($name, 'netflix'), str_contains($name, 'spotify') => self::Subscriptions,
            str_contains($name, 'asuransi') => self::Insurance,
            str_contains($name, 'tabung'), str_contains($name, 'dana darurat') => self::Savings,
            str_contains($name, 'investasi'), str_contains($name, 'saham') => self::Investment,
            str_contains($name, 'bisnis'), str_contains($name, 'usaha') => self::Business,
            str_contains($name, 'hadiah') => self::Gift,
            str_contains($name, 'anak'), str_contains($name, 'keluarga') => self::Family,
            str_contains($name, 'travel'), str_contains($name, 'liburan') => self::Travel,
            str_contains($name, 'utang'), str_contains($name, 'cicilan') => self::Utang,
            default => self::Other,
        };
    }
}
