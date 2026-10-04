# ARCHITECTURE.md — Alfinance.com

## 1. Tech Stack

| Layer                          | Pilihan                                                               |
| ------------------------------ | --------------------------------------------------------------------- |
| Backend framework              | Laravel 12 (PHP 8.3+)                                                 |
| Frontend bridge                | Inertia.js (server-driven SPA, no separate REST layer needed for UI)  |
| Frontend                       | Vue 3 (via Inertia) + Tailwind CSS                                    |
| Database                       | MySQL 8 (Laragon lokal untuk dev)                                     |
| Cache / Read layer             | Redis (cache + queue driver)                                          |
| Queue                          | Laravel Queue (Redis driver) + Horizon untuk monitoring               |
| Charts                         | Chart.js                                                              |
| PDF Export                     | barryvdh/laravel-dompdf                                               |
| Excel/CSV Export               | maatwebsite/excel                                                     |
| Auth                           | Laravel Fortify/Breeze (Inertia stack) + custom multi-workspace layer |
| Realtime notifikasi (opsional) | Laravel Reverb / Echo (untuk notifikasi job export selesai)           |

## 2. Prinsip Kunci: Hindari Query Langsung ke DB Saat Request

Permintaan eksplisit: aplikasi **tidak boleh** membaca langsung ke database server pada jalur request yang sering diakses (dashboard, list transaksi, report ringkasan), agar respons cepat dan DB tidak terbebani.

### 2.1 Strategi

1. **Read-through cache (Redis) sebagai default read path**
    - Semua endpoint "baca" bernilai tinggi (Dashboard KPI, Net Worth trend, Budget progress, Financial Health Score) membaca dari Redis terlebih dahulu.
    - Cache key digenerate per `workspace_id` + konteks (mis. `ws:{id}:dashboard:2026-09`).
    - Cache miss → satu kali query agregasi ke MySQL → simpan hasil ke Redis dengan TTL (mis. 5–15 menit) → kembalikan.

2. **Write-behind invalidation, bukan write-through langsung**
    - Saat transaksi baru dibuat/diubah/dihapus, request tidak menunggu recompute agregat.
    - Event `TransactionSaved` di-dispatch → listener menghapus/menandai stale cache key terkait (dashboard, budget bulan tsb, net worth) → job antrian (queue) melakukan recompute di background.
    - Untuk saldo akun: saldo di-update segera di tabel `accounts.cached_balance` dalam transaksi DB yang sama (agar akurat), tapi ini bagian dari write path, bukan dibaca ulang dari agregasi transaksi setiap kali ditampilkan.

3. **Materialized aggregate tables (denormalized)**
    - `dashboard_snapshots` (per workspace, per bulan): total income, expense, net cash flow — diisi/diupdate oleh queue job, bukan dihitung on-the-fly dari `transactions` saat halaman dibuka.
    - `net_worth_snapshots` (per workspace, per bulan): total aset, total kewajiban, net worth — dibuat oleh scheduled job bulanan + di-invalidate/dijadwalkan ulang saat ada perubahan signifikan.
    - `budget_progress_cache` (per workspace, kategori, bulan): total terpakai vs limit — di-update via listener saat transaksi masuk kategori terkait.
    - Halaman list & report membaca dari tabel agregat/cache ini, bukan `SUM()`/`GROUP BY` langsung ke tabel `transactions` mentah pada setiap request.

4. **Queue untuk operasi berat**
    - Financial Health Score recompute, export PDF/Excel, recurring transaction generation, backup/restore — semua dijalankan sebagai job antrian (Redis queue + Horizon), bukan sinkron dalam request HTTP.
    - User mendapat notifikasi (polling ringan atau broadcast) saat job selesai.

5. **Pagination & query terbatas untuk list mentah**
    - Saat user memang butuh detail transaksi mentah (bukan agregat), gunakan cursor pagination + index yang tepat, tetap lewat query builder Eloquent — bagian ini boleh menyentuh DB langsung karena sifatnya on-demand & sudah difilter kecil, tapi tetap dibungkus cache singkat (30–60 detik) untuk filter yang sama diklik berulang.

### 2.2 Diagram Alur (ringkas)

```
Request Dashboard
   → Controller
   → CacheRepository::get("ws:{id}:dashboard:{month}")
       → HIT  → return JSON/Inertia props (cepat)
       → MISS → Query agregat ke dashboard_snapshots (bukan raw transactions)
              → simpan ke Redis TTL 10 menit
              → return

Transaksi baru disimpan
   → TransactionController::store()
       → DB transaction: insert transaction + update accounts.cached_balance
       → event(TransactionSaved)
   → Listener: InvalidateCacheKeys (hapus key dashboard/budget bulan terkait)
   → Job (queue): RecomputeDashboardSnapshot, RecomputeBudgetProgress
```

## 3. Multi-Tenant / Workspace Model

- `users` — akun login.
- `workspaces` — satu workspace = satu "buku keuangan".
- `workspace_user` (pivot) — role: owner/admin/member/viewer.
- Semua tabel data (accounts, transactions, budgets, categories, debts, net_worth_items) memiliki `workspace_id` dan **global scope** Eloquent yang otomatis memfilter berdasarkan workspace aktif di session — mencegah kebocoran data antar workspace.
- Middleware `EnsureWorkspaceSelected` + `SetActiveWorkspaceScope` dijalankan di awal setiap request.

## 4. Skema Data Inti (ringkas)

- `users(id, name, email, password, ...)`
- `workspaces(id, name, owner_id, ...)`
- `workspace_user(workspace_id, user_id, role)`
- `accounts(id, workspace_id, type[kas|bank|ewallet|tabungan|kartu_kredit], name, cached_balance, credit_limit, billing_date, due_date, archived_at)`
- `categories(id, workspace_id, parent_id, name, icon, color)`
- `tags(id, workspace_id, name)`
- `transactions(id, workspace_id, account_id, type[income|expense|transfer], transfer_to_account_id, category_id, amount, note, occurred_at, is_recurring_instance_of, created_by)`
- `transaction_tag(transaction_id, tag_id)`
- `attachments(id, transaction_id, file_path)`
- `recurring_rules(id, workspace_id, template_json, frequency, next_run_at)`
- `budgets(id, workspace_id, category_id, month, limit_amount)`
- `budget_progress_cache(workspace_id, category_id, month, used_amount, updated_at)`
- `net_worth_items(id, workspace_id, type[asset|liability], subtype, name, value, valued_at)`
- `net_worth_snapshots(workspace_id, month, total_assets, total_liabilities, net_worth)`
- `debts(id, workspace_id, direction[payable|receivable], counterparty, principal, remaining, due_date, status)`
- `debt_payments(id, debt_id, amount, paid_at)`
- `dashboard_snapshots(workspace_id, month, total_income, total_expense, net_cash_flow)`
- `financial_health_scores(workspace_id, month, savings_rate, dti, emergency_fund_months, score, label)`
- `export_jobs(id, workspace_id, type, status, file_path)`
- `backups(id, workspace_id, scope, file_path, created_at)`

## 5. Keamanan

- Password hashing bcrypt/argon2 (default Laravel).
- 2FA opsional (Fortify).
- Policy per model (Laravel Authorization) berbasis role workspace.
- File upload (lampiran bukti) divalidasi tipe & ukuran, disimpan di storage disk terpisah per workspace.
- Rate limiting pada endpoint export & backup.

## 6. Deployment (dev lokal)

- Laragon (Nginx/Apache + MySQL + PHP) untuk dev.
- Redis via WSL/Docker lokal atau ekstensi Laragon.
- `.env`: `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- `php artisan queue:work` (atau Horizon) berjalan sebagai proses terpisah untuk memproses job.
- Scheduler (`php artisan schedule:work`) untuk snapshot bulanan & recurring transactions.

## 7. Observability

- Log query lambat (Laravel Telescope di dev) untuk memastikan tidak ada N+1 atau query berat tersembunyi di jalur dashboard.
- Horizon dashboard untuk memantau antrian job (export, recompute, backup).
