# AGENT.md — Build Instructions for AI Coding Agent (Opencode)

Kamu adalah AI coding agent yang bertugas membangun **Alfinance.com**, aplikasi web manajemen keuangan personal multi-workspace. Baca `PRD.md` (fitur lengkap) dan `ARCHITECTURE.md` (desain teknis & strategi cache) sebelum mulai. Dokumen ini adalah working prompt-mu — ikuti secara bertahap, jangan lompat fase.

## Aturan Kerja

1. Kerjakan per **fase**, urutan di bawah tidak boleh dilompati. Setelah tiap fase, jalankan `php artisan test` (jika ada test) dan pastikan aplikasi masih bisa `php artisan serve` tanpa error sebelum lanjut.
2. Jangan membuat query agregat berat langsung di controller untuk halaman Dashboard/Report/Budget — semua HARUS lewat cache/agregat sesuai `ARCHITECTURE.md` §2. Kalau ragu, buat Repository/Service class terpisah (`app/Services/*`) yang mengenkapsulasi logika cache-first.
3. Semua nominal uang: gunakan tipe `decimal:2` di model & migration `DECIMAL(15,2)` di DB. Jangan pakai float.
4. Semua tabel data domain wajib punya kolom `workspace_id` dan didaftarkan dengan global scope workspace (lihat Fase 2).
5. Commit kecil per unit kerja logis, pesan commit jelas (mis. `feat(accounts): CRUD akun + arsip`).
6. Gunakan Inertia + Vue 3 untuk semua halaman — jangan campur Blade untuk UI utama (Blade hanya untuk export PDF).
7. Ikuti gaya UI Neumorphism & palet warna White/Blue/Purple konsisten — buat design tokens (Tailwind config) di awal (Fase 1) supaya semua komponen berikutnya konsisten, jangan hardcode warna di tiap file.

## Fase 0 — Bootstrap Proyek

- `laravel new alfinance` (PHP 8.3+), install Breeze dengan stack Inertia + Vue.
- Setup MySQL via Laragon, buat `.env` sesuai `ARCHITECTURE.md` §6.
- Install: `predis/predis` atau ext-redis, set `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- Install package: `barryvdh/laravel-dompdf`, `maatwebsite/excel`, `laravel/horizon`.
- Setup Tailwind design tokens: warna primary (blue), accent (purple), surface (white/off-white), shadow neumorphism (`shadow-neu-flat`, `shadow-neu-pressed`, dsb custom di `tailwind.config.js`).

## Fase 1 — Auth & Multi-Workspace

- Implement auth (login/register/2FA opsional) via Breeze/Fortify.
- Migration & model: `workspaces`, `workspace_user` (role enum).
- Middleware `EnsureWorkspaceSelected` + global scope workspace di base model `WorkspaceScopedModel`.
- UI: halaman pilih/buat workspace setelah login, workspace switcher di header.
- Sidebar kiri: komponen Vue `AppSidebar.vue` — expanded (ikon+label) & collapsed (ikon saja + tooltip), state disimpan di `localStorage` dan disinkronkan ke `users.sidebar_collapsed` (update via request ringan, debounce).
- Tambahkan active-bar indicator (animasi transisi posisi) untuk menu aktif.

## Fase 2 — Master Data: Accounts, Categories, Tags

- Migration & CRUD: `accounts` (kas/bank/ewallet/kartu kredit dengan field limit/billing_date/due_date), `categories` (2 level, ikon+warna), `tags`.
- Fitur arsip akun (soft-archive: kolom `archived_at`, bukan delete).
- UI form dengan neumorphism card, validasi Inertia form request.

## Fase 3 — Transactions

- Migration: `transactions`, `transaction_tag`, `attachments`, `recurring_rules`.
- Logic transfer antar akun (2 sisi ledger dalam satu DB transaction).
- Upload lampiran bukti (validasi tipe/ukuran).
- Filter & pencarian (query scope Eloquent, index kolom `occurred_at`, `account_id`, `category_id`).
- Job scheduler untuk generate transaksi recurring (`RecurringTransactionJob`), dengan opsi "perlu konfirmasi" sebelum posting final.
- **Penting:** setiap simpan transaksi → update `accounts.cached_balance` dalam DB transaction yang sama → dispatch event `TransactionSaved` → listener invalidasi cache (lihat Fase 6).

## Fase 4 — Budgets, Net Worth, Debt Tracker

- Budgets: migration `budgets`, `budget_progress_cache`; UI matriks bulanan dengan inline edit (Vue reactive grid) + progress bar warna dinamis.
- Net Worth: migration `net_worth_items`, `net_worth_snapshots`; job bulanan snapshot (`GenerateNetWorthSnapshotJob` via scheduler).
- Debt Tracker: migration `debts`, `debt_payments`; UI riwayat cicilan + status otomatis (Berjalan/Lunas/Terlambat berdasarkan `remaining` & `due_date`).

## Fase 5 — Dashboard & Reports

- Buat `DashboardService` yang HANYA baca dari Redis/`dashboard_snapshots`, tidak pernah `SUM()` langsung ke `transactions` di controller.
- 4 KPI card, chart tren cash flow (Chart.js line/bar), donut expense breakdown, tabel 10 transaksi terakhir (query ringan terpisah, boleh langsung tapi dengan limit & index).
- Reports: Cash Flow Statement, Budget vs Actual, Expense Breakdown — semua baca dari tabel agregat/cache, dengan opsi filter tanggal yang men-trigger cache key baru (bukan recompute manual tiap klik).
- Financial Health Score: `FinancialHealthService` menghitung Savings Rate, DTI, Emergency Fund dari snapshot bulanan (job `RecomputeFinancialHealthJob`), simpan ke `financial_health_scores`, tampilkan skor + rekomendasi rule-based.

## Fase 6 — Caching Layer (Cross-Cutting, WAJIB Selesai Sebelum Fase 5 Final)

- Buat `app/Services/Cache/WorkspaceCache.php` — wrapper Redis dengan key convention `ws:{workspace_id}:{context}:{period}`.
- Listener `InvalidateWorkspaceCache` di-attach ke event: `TransactionSaved`, `BudgetUpdated`, `AccountUpdated`, `DebtPaymentRecorded` — hapus/refresh key terkait.
- Queue jobs: `RecomputeDashboardSnapshot`, `RecomputeBudgetProgress`, `RecomputeNetWorthSnapshot`, `RecomputeFinancialHealth` — semua idempotent, aman dijalankan ulang.
- Setup Horizon untuk monitoring queue.

## Fase 7 — Exports & Backup

- PDF export (DomPDF, Blade view khusus print-friendly) untuk 3 jenis report.
- Excel/CSV export (Maatwebsite Excel) untuk data transaksi & report.
- Export besar → job antrian (`ExportJob` model status: queued/processing/done/failed) + notifikasi saat siap unduh.
- Backup: generate payload JSON (scope penuh atau per rentang tanggal) via job, simpan ke storage, list riwayat backup + restore dengan validasi skema JSON sebelum apply (dry-run diff sebelum commit ke DB).

## Fase 8 — Polish & QA

- Cek semua animasi (hover motion, transisi komponen, active bar, animasi chart) konsisten di seluruh modul.
- Test skenario: transaksi lintas bulan, transfer antar akun, recurring edge case (akhir bulan), workspace switching tidak bocor data.
- Load test halaman dashboard: pastikan tidak ada query N+1 (cek via Telescope), verifikasi cache hit-rate.
- Review aksesibilitas kontras warna neumorphism (soft shadow sering kontras rendah — pastikan teks tetap terbaca).

## Output yang Diharapkan Tiap Fase

Setelah menyelesaikan tiap fase, laporkan singkat: file/migration yang dibuat, keputusan desain penting, dan hal yang butuh review manusia (kalau ada) — jangan lanjut ke fase berikutnya tanpa konfirmasi jika ada keputusan arsitektur yang ambigu.
