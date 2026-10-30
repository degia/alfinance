/**
 * Peta pesan untuk halaman error HTTP (400–504).
 *
 * Setiap kode dipetakan ke judul singkat, penjelasan detail, dan solusi.
 * Solusi berupa `action` (tombol yang langsung menyelesaikan masalah, mis.
 * reload / kembali ke beranda) atau `hint` (petunjuk teks karena tidak ada
 * aksi yang bisa dilakukan otomatis oleh pengguna).
 */

export type ErrorSolution =
    | { type: 'action'; label: string; href: string; method?: 'get' | 'reload' }
    | { type: 'hint'; message: string };

export type HttpErrorInfo = {
    title: string;
    description: string;
    solution: ErrorSolution;
};

const home = '/';
const login = '/login';

const errorMap: Record<number, HttpErrorInfo> = {
    400: {
        title: 'Permintaan tidak valid',
        description:
            'Server tidak dapat memproses permintaan ini karena format atau data yang dikirim tidak sesuai.',
        solution: {
            type: 'hint',
            message: 'Periksa kembali data yang diisi, lalu ulangi aksi tersebut. Jika masalah berlanjut, muat ulang halaman.',
        },
    },
    401: {
        title: 'Belum masuk / sesi tidak ditemukan',
        description: 'Kamu perlu masuk terlebih dahulu untuk mengakses halaman ini.',
        solution: { type: 'action', label: 'Masuk ke akun', href: login },
    },
    402: {
        title: 'Pembayaran diperlukan',
        description: 'Akses ke sumber daya ini membutuhkan pembayaran atau langganan aktif.',
        solution: { type: 'hint', message: 'Hubungi admin workspace untuk memeriksa status langganan.' },
    },
    403: {
        title: 'Akses ditolak',
        description: 'Kamu tidak memiliki izin yang cukup untuk melakukan aksi atau membuka halaman ini.',
        solution: {
            type: 'hint',
            message: 'Hubungi pemilik workspace jika menurutmu ini seharusnya diizinkan, atau kembali ke halaman sebelumnya.',
        },
    },
    404: {
        title: 'Halaman tidak ditemukan',
        description: 'Alamat yang kamu tuju tidak ada, sudah dipindahkan, atau sudah dihapus.',
        solution: { type: 'action', label: 'Kembali ke beranda', href: home },
    },
    405: {
        title: 'Metode tidak diizinkan',
        description: 'Cara mengakses alamat ini tidak didukung oleh server.',
        solution: { type: 'action', label: 'Kembali ke beranda', href: home },
    },
    406: {
        title: 'Format tidak dapat diterima',
        description: 'Server tidak bisa menghasilkan respons dalam format yang diminta browser.',
        solution: { type: 'hint', message: 'Muat ulang halaman. Jika masalah berlanjut, hubungi dukungan teknis.' },
    },
    408: {
        title: 'Waktu permintaan habis',
        description: 'Koneksi ke server memakan waktu terlalu lama sehingga permintaan dibatalkan.',
        solution: { type: 'action', label: 'Coba lagi', href: '', method: 'reload' },
    },
    409: {
        title: 'Terjadi konflik data',
        description: 'Data yang kamu ubah sudah berubah di tempat lain sebelum permintaan ini selesai diproses.',
        solution: { type: 'action', label: 'Muat ulang halaman', href: '', method: 'reload' },
    },
    410: {
        title: 'Konten sudah tidak tersedia',
        description: 'Halaman ini pernah ada, tetapi sudah dihapus secara permanen.',
        solution: { type: 'action', label: 'Kembali ke beranda', href: home },
    },
    413: {
        title: 'Berkas terlalu besar',
        description: 'Ukuran data atau lampiran yang dikirim melebihi batas yang diizinkan server.',
        solution: { type: 'hint', message: 'Perkecil ukuran berkas atau kompres lampiran, lalu unggah kembali.' },
    },
    415: {
        title: 'Jenis berkas tidak didukung',
        description: 'Format berkas yang diunggah tidak sesuai dengan yang diterima sistem.',
        solution: { type: 'hint', message: 'Gunakan format berkas yang didukung (lihat keterangan di formulir unggah).' },
    },
    419: {
        title: 'Sesi halaman kedaluwarsa',
        description: 'Token keamanan halaman sudah tidak berlaku, biasanya karena halaman dibuka terlalu lama atau sesi berakhir.',
        solution: { type: 'action', label: 'Muat ulang halaman', href: '', method: 'reload' },
    },
    422: {
        title: 'Data tidak lolos validasi',
        description: 'Beberapa isian yang dikirim belum sesuai dengan aturan yang berlaku.',
        solution: { type: 'hint', message: 'Periksa kembali isian yang ditandai, perbaiki, lalu kirim ulang formulir.' },
    },
    423: {
        title: 'Sumber daya terkunci',
        description: 'Data ini sedang dikunci atau sedang diproses oleh pengguna/sistem lain.',
        solution: { type: 'hint', message: 'Tunggu beberapa saat lalu coba lagi.' },
    },
    429: {
        title: 'Terlalu banyak permintaan',
        description: 'Kamu mengirim permintaan terlalu sering dalam waktu singkat sehingga dibatasi sementara.',
        solution: { type: 'hint', message: 'Tunggu sebentar sebelum mencoba lagi.' },
    },
    431: {
        title: 'Header permintaan terlalu besar',
        description: 'Data header yang dikirim browser melebihi batas yang diizinkan server.',
        solution: { type: 'action', label: 'Muat ulang halaman', href: '', method: 'reload' },
    },
    500: {
        title: 'Terjadi kesalahan pada server',
        description: 'Ada masalah tak terduga di sisi server saat memproses permintaan ini.',
        solution: { type: 'hint', message: 'Tim kami sudah menerima laporan otomatis. Coba lagi dalam beberapa saat.' },
    },
    501: {
        title: 'Fitur belum tersedia',
        description: 'Server belum mendukung fungsi yang diminta oleh permintaan ini.',
        solution: { type: 'hint', message: 'Hubungi dukungan teknis jika kamu mengharapkan fitur ini sudah tersedia.' },
    },
    502: {
        title: 'Gateway bermasalah',
        description: 'Server perantara menerima respons yang tidak valid dari server tujuan.',
        solution: { type: 'action', label: 'Coba lagi', href: '', method: 'reload' },
    },
    503: {
        title: 'Layanan sedang tidak tersedia',
        description: 'Server sedang dalam pemeliharaan atau kelebihan beban sehingga tidak bisa melayani permintaan.',
        solution: { type: 'hint', message: 'Coba lagi dalam beberapa menit. Layanan biasanya kembali normal secara otomatis.' },
    },
    504: {
        title: 'Waktu tunggu gateway habis',
        description: 'Server perantara tidak menerima balasan tepat waktu dari server tujuan.',
        solution: { type: 'action', label: 'Coba lagi', href: '', method: 'reload' },
    },
};

const fallback4xx: HttpErrorInfo = {
    title: 'Permintaan tidak dapat diproses',
    description: 'Terjadi kesalahan pada permintaan yang kamu kirim ke server.',
    solution: { type: 'hint', message: 'Periksa kembali yang kamu lakukan, lalu coba lagi.' },
};

const fallback5xx: HttpErrorInfo = {
    title: 'Terjadi kesalahan pada server',
    description: 'Server mengalami masalah saat memproses permintaan ini.',
    solution: { type: 'hint', message: 'Coba lagi dalam beberapa saat. Jika terus terjadi, hubungi dukungan teknis.' },
};

export function resolveHttpError(status: number): HttpErrorInfo {
    if (errorMap[status]) {
        return errorMap[status];
    }

    return status < 500 ? fallback4xx : fallback5xx;
}
