# PRD — Alfinance.com

Personal & Multi-Workspace Finance Management SaaS

Version 1.0 · Status: Draft for build

## 1. Ringkasan Produk

Alfinance.com adalah aplikasi web manajemen keuangan personal bergaya SaaS multi-akun (multi-tenant workspace). Pengguna mencatat cash flow, membuat anggaran, melacak net worth, mengelola utang/piutang, dan mendapatkan skor kesehatan finansial otomatis, dengan ekspor laporan PDF/Excel.

**Target pengguna:** individu/keluarga yang ingin mengelola beberapa "workspace" keuangan (misal: Pribadi, Keluarga, Bisnis Sampingan) dalam satu akun login.

## 2. Tujuan & Sukses Kriteria

- Pencatatan transaksi < 10 detik per entri (mobile-friendly form).
- Dashboard termuat < 1 detik (lihat NFR performa — data dashboard **tidak** query langsung ke DB server saat request, lihat ARCHITECTURE.md).
- Skor kesehatan finansial ter-update otomatis setiap transaksi baru disimpan.
- Ekspor laporan (PDF/Excel) selesai < 5 detik untuk rentang 12 bulan.

## 3. Lingkup Modul & Fitur

### 3.1 Dashboard

- 4 KPI card: Total Saldo, Income bulan ini, Expense bulan ini, Net Cash Flow.
- Grafik tren cash flow (line/bar, Chart.js) — 6/12 bulan terakhir.
- Donut chart expense breakdown per kategori (bulan berjalan).
- Tabel 10 transaksi terakhir dengan quick filter.

### 3.2 Accounts

- Tipe akun: Kas/Tunai, Rekening Bank, E-Wallet, Tabungan, Kartu Kredit/Paylater.
- Kartu kredit/paylater: field limit, tanggal cetak tagihan, tanggal jatuh tempo, sisa limit terpakai.
- Tabungan: aset biasa (bisa bertransaksi, saldo bergerak), tapi tidak ikut "Saldo Total" dan tidak dihitung sebagai dana darurat — uangnya sudah disisihkan. Tetap dihitung sebagai aset di Kekayaan Bersih.
- Arsip akun (soft-archive, bukan hapus) — histori transaksi tetap utuh.
- Saldo per akun otomatis ter-update dari transaksi & transfer.

### 3.3 Transactions

- Tipe: Income, Expense, Transfer antar akun (mempengaruhi 2 akun sekaligus).
- Lampiran bukti (upload gambar/PDF struk).
- Tag bebas (many-to-many) + kategori.
- Filter & pencarian: rentang tanggal, akun, kategori, tag, tipe, nominal.
- Transaksi berulang (recurring): harian/mingguan/bulanan/tahunan, auto-generate via scheduled job, dengan opsi konfirmasi manual sebelum posting.

### 3.4 Categories & Tags

- Kategori 2 level (kategori → sub-kategori).
- Ikon & warna kustom per kategori (dipakai di chart & badge).
- Tag bebas many-to-many, autocomplete saat input transaksi.

### 3.5 Budgets

- Limit anggaran per kategori per bulan.
- Progress bar status: hijau (<80%), kuning (80–100%), merah (>100%).
- Matriks anggaran bulanan (kategori × 12 bulan) dengan inline edit.

### 3.6 Net Worth

- Aset: investasi, properti, kendaraan, lainnya (manual input nilai + apresiasi/depresiasi opsional).
- Kewajiban: KPR, kredit kendaraan, saldo kartu kredit/paylater (auto-sync dari modul Accounts).
- Snapshot bulanan otomatis (job terjadwal) + grafik tren net worth.

### 3.7 Debt Tracker

- Utang (saya berutang) & Piutang (orang berutang ke saya).
- Jadwal cicilan, riwayat pembayaran per cicilan, status: Berjalan / Lunas / Terlambat.
- Terhubung opsional ke modul Net Worth (sebagai kewajiban) dan Transactions (pembayaran cicilan = expense/income).

### 3.8 Reports

- Cash Flow Statement: total in/out, net cash flow per bulan.
- Budget vs Actual: per kategori, highlight overbudget.
- Expense Breakdown: chart Chart.js (pie/bar), top-N expense.
- Semua laporan bisa difilter per workspace, rentang tanggal, akun.

### 3.9 Financial Health Score

- Komposit dari 3 metrik:
    - Savings Rate = (Income − Expense) / Income, ideal ≥ 20%.
    - Debt-to-Income Ratio = Total cicilan bulanan / Income, ideal ≤ 30%.
    - Emergency Fund = Total kas likuid / rata-rata expense bulanan, ideal 3–6 bulan.
- Skor 0–100 + label (Perlu Perhatian / Cukup / Sehat / Sangat Sehat) + rekomendasi otomatis berbasis rule (mis. "Savings rate di bawah target, coba kurangi kategori X sebesar Rp...").

### 3.10 Exports

- PDF laporan (DomPDF) — cash flow, budget vs actual, expense breakdown.
- Excel/CSV (Maatwebsite Excel) — semua data transaksi & laporan.
- Export berjalan sebagai job antrian (queue) untuk file besar, notifikasi saat siap unduh.

### 3.11 Backup

- Backup manual/terjadwal per workspace ke payload JSON.
- Scope pilihan: backup penuh (semua data workspace) atau per rentang tanggal.
- Restore dari file JSON dengan validasi skema sebelum apply.

## 4. Multi-Tenant SaaS & Auth

- Satu akun login (email/password + opsi 2FA) bisa memiliki/bergabung ke banyak **Workspace**.
- Role per workspace: Owner, Admin, Member (view/edit sesuai role), Viewer (read-only).
- Workspace switcher di header/sidebar.
- Data terisolasi ketat per `workspace_id` (row-level scoping) — lihat ARCHITECTURE.md untuk enforcement.

## 5. Navigasi & UI

- Sidebar kiri fixed, collapsible: mode expanded (ikon + label) dan mode collapsed (ikon saja, tooltip on hover).
- State collapse tersimpan per user (localStorage + sinkron ke preferensi user di DB).
- Gaya UI: **Neumorphism** (soft shadow, monochrome base, elevated cards).
- Palet warna utama: **White, Blue, Purple** (blue sebagai primary action, purple sebagai accent/highlight, white sebagai base surface).
- Animasi: hover motion di card & button, transisi komponen (fade/slide), active bar indicator di sidebar nav, animasi masuk bar chart.

## 6. Non-Functional Requirements

- **Performa:** dashboard & halaman list tidak melakukan query berat langsung ke DB saat request masuk — gunakan layer cache (lihat ARCHITECTURE.md). Target TTFB halaman utama < 300ms dari cache hit.
- Precision finansial: DECIMAL(15,2), tidak pakai float untuk nominal uang.
- Audit trail dasar: created_by, updated_by, timestamps pada tabel transaksional.
- Multi-currency: out-of-scope v1 (asumsi IDR).
- Aksesibilitas: kontras warna cukup di kedua tema (light/dark opsional v2).

## 7. Out of Scope (v1)

- Integrasi langsung ke bank (open banking/API scraping).
- Aplikasi mobile native.
- Multi-currency & konversi kurs otomatis.
- Kolaborasi real-time (live cursor) antar member workspace.

## 8. Deliverables Terkait

- `ARCHITECTURE.md` — desain teknis, skema data, strategi caching.
- `AGENT.md` — prompt kerja untuk AI coding agent (Opencode) membangun aplikasi secara bertahap.
- Web preview (demo UI statis) — Dashboard & sidebar collapsible, gaya neumorphism, palet White/Blue/Purple.
