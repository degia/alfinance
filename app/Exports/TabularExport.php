<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Export spreadsheet generik (PRD.md §3.10).
 *
 * Data yang sudah disusun service (transaksi ataupun laporan) diserahkan
 * lewat konstruktor sebagai baris dua dimensi. Kolom angka disimpan sebagai
 * string desimal — bukan float — supaya nominal tidak kehilangan presisi
 * ketika dibuka di spreadsheet (uang selalu `DECIMAL(15,2)`).
 */
class TabularExport implements FromArray, WithHeadings
{
    /**
     * @param  array<int, array<int|string, mixed>>  $rows
     * @param  array<int, string>  $headings
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $headings,
    ) {}

    /**
     * @return array<int, array<int|string, mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return $this->headings;
    }
}
