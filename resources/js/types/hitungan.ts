/**
 * Props halaman Hitungan (kalkulator rencana anggaran bulanan).
 */
export type HitunganIndexProps = {
    /**
     * Total saldo akun aktif yang bisa dibelanjakan (kas/bank/e-wallet).
     * Tabungan dan kartu kredit tidak ikut, mengikuti definisi "saldo total"
     * aplikasi. Dikirim sebagai string desimal agar presisi tidak hilang.
     */
    balance: string;

    /**
     * Total pengeluaran transaksi hari ini, dihitung server dari agregat
     * harian workspace. Read-only: user tidak mengetiknya sendiri. Dikirim
     * sebagai string desimal.
     */
    expenses_today: string;

    /**
     * Tanggal hari ini (`YYYY-MM-DD`) menurut server. Dipakai sebagai tanggal
     * mulai yang selalu tetap dan titik awal hitungan "Jumlah hari".
     */
    today: string;

    /**
     * Rencana tersimpan milik workspace aktif, atau null bila belum pernah
     * disimpan. `balance`, `expenses_today`, dan tanggal mulai sengaja tidak ada
     * di sini karena selalu dihitung ulang dari akun, transaksi & tanggal server
     * saat halaman dimuat.
     */
    plan: HitunganSavedPlan | null;
};

/**
 * Satu baris "Rencana pengeluaran" yang tersimpan.
 */
export type HitunganPlanRow = {
    name: string;
    plan: string;
    actual: string;
};

/**
 * Rencana Hitungan tersimpan. Nominal berupa string desimal dua angka.
 */
export type HitunganSavedPlan = {
    daily_limit: string;
    end_date: string | null;
    rows: HitunganPlanRow[];
    saved_at: string | null;
};

/**
 * Payload form saat menyimpan rencana (POST hitungan).
 */
export type HitunganPlanFormData = {
    daily_limit: string;
    end_date: string;
    rows: HitunganPlanRow[];
};
