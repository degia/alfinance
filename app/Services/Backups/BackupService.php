<?php

namespace App\Services\Backups;

use App\Jobs\GenerateNetWorthSnapshotJob;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Jobs\RecomputeDashboardSnapshotJob;
use App\Jobs\RecomputeFinancialHealthJob;
use App\Models\Backup;
use App\Services\Cache\WorkspaceCache;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Pembuatan & restore backup JSON per workspace (PRD.md §3.11).
 *
 * Backup menyimpan baris persis seperti yang ada di database (string desimal
 * untuk uang, string untuk enum, datetime asli), sehingga restore bersifat
 * byte-true dan tidak melewati float. Tabel turunan — `budget_progress_cache`,
 * `dashboard_snapshots`, `financial_health_scores`, `net_worth_snapshots` —
 * sengaja TIDAK ikut di-backup: nilainya murni hasil agregasi, dan dihidupkan
 * kembali lewat job recompute setelah restore.
 *
 * Restore diarahkan ke workspace aktif pemakai (bukan workspace pemilik
 * backup) dan tidak pernah menghapus baris yang tidak ada di payload:
 * baris dengan id bebas dibuat, id yang sudah dimiliki workspace aktif
 * ditimpa, dan id yang dimiliki workspace lain dilewati (dilaporkan di
 * preview/diff).
 */
final class BackupService
{
    public const SCHEMA_VERSION = 1;

    /**
     * Bagian payload yang dibackup, dalam urutan ketergantungan FK.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'accounts' => 'accounts',
        'categories' => 'categories',
        'tags' => 'tags',
        'recurring_rules' => 'recurring_rules',
        'transactions' => 'transactions',
        'budgets' => 'budgets',
        'net_worth_items' => 'net_worth_items',
        'debts' => 'debts',
        'debt_payments' => 'debt_payments',
        'attachments' => 'attachments',
    ];

    public function __construct(private readonly WorkspaceCache $cache) {}

    /**
     * Bangun payload untuk satu backup, tulis ke disk `backups`, lalu tandai
     * selesai. Dipanggil dari dalam job antrean (bukan jalur request).
     */
    public function generate(Backup $backup): void
    {
        $payload = $this->payloadFor($backup);

        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $workspaceId = (int) $backup->workspace_id;
        $scope = $backup->scope->value;
        $name = sprintf('backup-%d-%s-%s.json', $workspaceId, $scope, CarbonImmutable::now()->format('Ymd-His'));
        $path = 'backups/'.$workspaceId.'/'.$name;

        $disk = Storage::disk('backups');

        if (! $disk->put($path, $json)) {
            throw new RuntimeException('File backup tidak bisa ditulis ke storage.');
        }

        /** @var array<string, int> $summary */
        $summary = $payload['summary'];

        $backup->markDone($path, $name, strlen($json), $summary);
    }

    /**
     * Susun payload backup (tabel + metadata + ringkasan baris).
     *
     * @return array<string, mixed>
     */
    public function payloadFor(Backup $backup): array
    {
        $workspaceId = (int) $backup->workspace_id;
        $hasRange = $backup->scope->needsRange();
        $range = $hasRange
            ? ['from' => $backup->from?->toDateString(), 'to' => $backup->to?->toDateString()]
            : null;

        $transactions = $hasRange
            ? $this->transactionsInRange($backup)
            : $this->workspaceRows('transactions', $workspaceId);

        $transactionIds = array_column($transactions, 'id');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'meta' => [
                'app' => 'alfinance',
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
                'workspace_id' => $workspaceId,
                'scope' => $backup->scope->value,
                'range' => $range,
            ],
            'workspace' => $this->workspaceRow($workspaceId),
            'accounts' => $this->workspaceRows('accounts', $workspaceId),
            'categories' => $this->workspaceRows('categories', $workspaceId),
            'tags' => $this->workspaceRows('tags', $workspaceId),
            'recurring_rules' => $this->workspaceRows('recurring_rules', $workspaceId),
            'transactions' => $transactions,
            'budgets' => $this->workspaceRows('budgets', $workspaceId),
            'net_worth_items' => $this->workspaceRows('net_worth_items', $workspaceId),
            'debts' => $this->workspaceRows('debts', $workspaceId),
            'debt_payments' => $hasRange
                ? $this->debtPaymentsInRange($backup)
                : $this->workspaceRows('debt_payments', $workspaceId),
            'attachments' => $this->attachmentsFor($workspaceId, $transactionIds),
            'transaction_tag' => $this->pivotRows('transaction_tag', 'transaction_id', $transactionIds),
        ];

        $summary = [];

        foreach (self::TABLES as $key => $table) {
            $summary[$key] = count($payload[$key]);
        }

        $summary['transaction_tag'] = count($payload['transaction_tag']);
        $payload['summary'] = $summary;

        return $payload;
    }

    /*
     |--------------------------------------------------------------------------
     | Preview (dry-run diff) & restore
     |--------------------------------------------------------------------------
     */

    /**
     * Diff dry-run sebelum restore: validasi skema dulu, lalu bandingkan
     * payload terhadap kondisi tabel workspace aktif.
     *
     * @return array<string, mixed>
     */
    public function preview(Backup $backup): array
    {
        $payload = $this->readPayload($backup);
        $errors = $this->validatePayload($payload);

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'sections' => [],
                'overwrites_any' => false,
            ];
        }

        /** @var array<string, mixed> $meta */
        $meta = $payload['meta'];
        $workspaceId = (int) ($meta['workspace_id'] ?? 0);
        $sections = [];

        foreach (self::TABLES as $key => $table) {
            $sections[$key] = $this->sectionStats($table, $workspaceId, $payload[$key] ?? []);
        }

        $sections['transaction_tag'] = $this->pivotStats($payload);

        $overwritesAny = false;

        foreach ($sections as $stats) {
            if ($stats['replace'] > 0) {
                $overwritesAny = true;
            }
        }

        return [
            'ok' => true,
            'schema_version' => $payload['schema_version'],
            'scope' => $meta['scope'] ?? null,
            'range' => $meta['range'] ?? null,
            'workspace_id' => $workspaceId,
            'sections' => $sections,
            'overwrites_any' => $overwritesAny,
        ];
    }

    /**
     * Terapkan restore dalam satu transaksi DB: timpa/tambah baris payload,
     * hitung ulang saldo akun, jadwalkan recompute agregat, lalu bersihkan
     * cache workspace.
     *
     * @return array<string, mixed> ringkasan hasil restore.
     */
    public function restore(Backup $backup): array
    {
        $payload = $this->readPayload($backup);
        $errors = $this->validatePayload($payload);

        if ($errors !== []) {
            throw new RuntimeException('Backup tidak valid: '.implode(' ', $errors));
        }

        /** @var array<string, mixed> $meta */
        $meta = $payload['meta'];
        $workspaceId = (int) ($meta['workspace_id'] ?? 0);

        if ($workspaceId < 1) {
            throw new RuntimeException('Payload backup tidak memuat workspace_id.');
        }

        try {
            DB::transaction(function () use ($payload, $workspaceId): void {
                $this->restoreWorkspaceRow($payload, $workspaceId);

                foreach (self::TABLES as $key => $table) {
                    $this->restoreTable($table, $workspaceId, $payload[$key] ?? []);
                }

                $this->restorePivotRows($payload);
                $this->recomputeAccountBalances($workspaceId);
                $this->scheduleRecompute($workspaceId, $payload);
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('Restore gagal: '.$exception->getMessage(), 0, $exception);
        }

        // Cache dihapus setelah transaksi sukses supaya halaman tidak pernah
        // melihat setengah-restore.
        $this->cache->forgetAll($workspaceId);

        return $this->resultSummary($payload, $workspaceId);
    }

    /*
     |--------------------------------------------------------------------------
     | Baca & validasi payload
     |--------------------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>
     */
    private function readPayload(Backup $backup): array
    {
        $path = $backup->file_path;

        if ($path === null) {
            throw new RuntimeException('Backup belum menghasilkan file.');
        }

        $raw = Storage::disk('backups')->get($path);

        if ($raw === null) {
            throw new RuntimeException('File backup tidak ditemukan di storage.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('File backup bukan JSON yang valid.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Isi backup bukan objek JSON.');
        }

        return $decoded;
    }

    /**
     * Validasi skema payload. Mengembalikan daftar error (kosong = valid).
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];

        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'Versi skema backup tidak didukung.';
        }

        if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
            $errors[] = 'Metadata (meta) backup hilang.';
        } elseif (($payload['meta']['workspace_id'] ?? null) === null) {
            $errors[] = 'Daftar workspace asal (meta.workspace_id) hilang.';
        }

        foreach (self::TABLES as $key => $table) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                $errors[] = "Bagian \"{$key}\" tidak ditemukan atau bukan daftar baris.";
            }
        }

        return $errors;
    }

    /*
     |--------------------------------------------------------------------------
     | Pengambilan baris saat generate
     |--------------------------------------------------------------------------
     */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workspaceRows(string $table, int $workspaceId): array
    {
        return DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workspaceRow(int $workspaceId): array
    {
        $row = DB::table('workspaces')->where('id', $workspaceId)->first();

        return $row === null ? [] : [(array) $row];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactionsInRange(Backup $backup): array
    {
        $from = $backup->from;
        $to = $backup->to;

        if ($from === null || $to === null) {
            throw new RuntimeException('Backup rentang wajib memuat tanggal mulai & akhir.');
        }

        return DB::table('transactions')
            ->where('workspace_id', $backup->workspace_id)
            ->whereBetween('occurred_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function debtPaymentsInRange(Backup $backup): array
    {
        $from = $backup->from;
        $to = $backup->to;

        if ($from === null || $to === null) {
            throw new RuntimeException('Backup rentang wajib memuat tanggal mulai & akhir.');
        }

        return DB::table('debt_payments')
            ->where('workspace_id', $backup->workspace_id)
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * Lampiran dibackup sebagai metadata saja — file fisik tidak ikut.
     *
     * @param  array<int, mixed>  $transactionIds
     * @return array<int, array<string, mixed>>
     */
    private function attachmentsFor(int $workspaceId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return DB::table('attachments')
            ->where('workspace_id', $workspaceId)
            ->whereIn('transaction_id', $transactionIds)
            ->orderBy('transaction_id')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function pivotRows(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->orderBy($column)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /*
     |--------------------------------------------------------------------------
     | Statistik diff
     |--------------------------------------------------------------------------
     */

    /**
     * @param  array<int, mixed>  $payloadRows
     * @return array{current: int, payload: int, create: int, replace: int, skip: int, retained: int}
     */
    private function sectionStats(string $table, int $workspaceId, array $payloadRows): array
    {
        $ids = [];

        foreach ($payloadRows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        $owners = [];

        if ($ids !== []) {
            foreach (DB::table($table)->whereIn('id', $ids)->get(['id', 'workspace_id']) as $row) {
                $owners[(int) $row->id] = $row->workspace_id === null ? null : (int) $row->workspace_id;
            }
        }

        $current = DB::table($table)->where('workspace_id', $workspaceId)->count();
        $create = 0;
        $replace = 0;
        $skip = 0;

        foreach ($ids as $id) {
            if (! array_key_exists($id, $owners)) {
                $create++;
            } elseif ($owners[$id] === null || $owners[$id] === $workspaceId) {
                $replace++;
            } else {
                $skip++;
            }
        }

        return [
            'current' => $current,
            'payload' => count($payloadRows),
            'create' => $create,
            'replace' => $replace,
            'skip' => $skip,
            'retained' => max(0, $current - $replace),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{current: int, payload: int, create: int, replace: int, skip: int, retained: int}
     */
    private function pivotStats(array $payload): array
    {
        /** @var array<int, mixed> $rows */
        $rows = $payload['transaction_tag'] ?? [];
        $existing = 0;
        $create = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $transactionId = (int) ($row['transaction_id'] ?? 0);
            $tagId = (int) ($row['tag_id'] ?? 0);

            if ($transactionId < 1 || $tagId < 1) {
                continue;
            }

            if (DB::table('transaction_tag')
                ->where('transaction_id', $transactionId)
                ->where('tag_id', $tagId)
                ->exists()
            ) {
                $existing++;
            } else {
                $create++;
            }
        }

        return [
            'current' => $existing,
            'payload' => count($rows),
            'create' => $create,
            'replace' => 0,
            'skip' => $existing,
            'retained' => $existing,
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | Penerapan restore
     |--------------------------------------------------------------------------
     */

    /**
     * @param  array<string, mixed>  $payload
     */
    private function restoreWorkspaceRow(array $payload, int $workspaceId): void
    {
        /** @var array<int, mixed> $workspace */
        $workspace = $payload['workspace'] ?? [];
        $name = is_array($workspace[0] ?? null) ? (string) ($workspace[0]['name'] ?? '') : '';

        if ($name !== '') {
            DB::table('workspaces')->where('id', $workspaceId)->update(['name' => $name]);
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function restoreTable(string $table, int $workspaceId, array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $id = (int) $row['id'];

            if ($id < 1) {
                continue;
            }

            $existing = DB::table($table)->where('id', $id)->first();

            // Bukan milik workspace aktif → timpa hanya jika id kosong.
            if ($existing === null) {
                $insert = $row;

                if (array_key_exists('workspace_id', $insert)) {
                    $insert['workspace_id'] = $workspaceId;
                }

                DB::table($table)->insert($insert);

                continue;
            }

            $owner = $existing->workspace_id ?? null;

            if ($owner !== null && (int) $owner !== $workspaceId) {
                continue;
            }

            $values = $row;
            unset($values['id']);

            if (array_key_exists('workspace_id', $values)) {
                $values['workspace_id'] = $workspaceId;
            }

            DB::table($table)->where('id', $id)->update($values);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function restorePivotRows(array $payload): void
    {
        /** @var array<int, mixed> $rows */
        $rows = $payload['transaction_tag'] ?? [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $transactionId = (int) ($row['transaction_id'] ?? 0);
            $tagId = (int) ($row['tag_id'] ?? 0);

            if ($transactionId < 1 || $tagId < 1) {
                continue;
            }

            // Pivot hanya dibuat ulang bila transaksi & tag yang dirujuk
            // benar-benar ada (transaksi yang di-skip/digagalkan dilewati).
            if (DB::table('transactions')->where('id', $transactionId)->doesntExist()) {
                continue;
            }

            if (DB::table('tags')->where('id', $tagId)->doesntExist()) {
                continue;
            }

            if (! DB::table('transaction_tag')
                ->where('transaction_id', $transactionId)
                ->where('tag_id', $tagId)
                ->exists()
            ) {
                DB::table('transaction_tag')->insert([
                    'transaction_id' => $transactionId,
                    'tag_id' => $tagId,
                ]);
            }
        }
    }

    /**
     * Saldo akun dibangun ulang dari data transaksi (bukan dari nilai
     * `cached_balance` yang ikut dibackup), supaya angka tetap konsisten
     * walau payloadnya berisi saldo lama atau transaksi di luar rentang.
     */
    private function recomputeAccountBalances(int $workspaceId): void
    {
        $accounts = DB::table('accounts')
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'initial_balance']);

        $balances = [];

        foreach ($accounts as $account) {
            $balances[(int) $account->id] = Money::toCents($account->initial_balance);
        }

        $transactions = DB::table('transactions')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'posted')
            ->get(['id', 'account_id', 'transfer_to_account_id', 'amount', 'type']);

        foreach ($transactions as $transaction) {
            $amount = Money::toCents($transaction->amount);
            $sourceId = (int) $transaction->account_id;
            $targetId = $transaction->transfer_to_account_id === null
                ? null
                : (int) $transaction->transfer_to_account_id;

            switch ((string) $transaction->type) {
                case 'income':
                    $balances[$sourceId] = ($balances[$sourceId] ?? 0) + $amount;
                    break;
                case 'expense':
                    $balances[$sourceId] = ($balances[$sourceId] ?? 0) - $amount;
                    break;
                case 'transfer':
                    // Transfer ke akun sendiri adalah no-op (sama seperti
                    // `Transaction::balanceDeltas()`).
                    if ($targetId !== null && $targetId !== $sourceId) {
                        $balances[$sourceId] = ($balances[$sourceId] ?? 0) - $amount;
                        $balances[$targetId] = ($balances[$targetId] ?? 0) + $amount;
                    }
                    break;
            }
        }

        foreach ($accounts as $account) {
            DB::table('accounts')
                ->where('id', (int) $account->id)
                ->update(['cached_balance' => Money::fromCents($balances[(int) $account->id] ?? 0)]);
        }
    }

    /**
     * Jadwalkan ulang agregat yang turut tersentuh restore.
     *
     * @param  array<string, mixed>  $payload
     */
    private function scheduleRecompute(int $workspaceId, array $payload): void
    {
        $months = [];

        /** @var array<int, mixed> $transactions */
        $transactions = $payload['transactions'] ?? [];

        foreach ($transactions as $transaction) {
            if (is_array($transaction) && isset($transaction['occurred_at']) && is_string($transaction['occurred_at'])) {
                $months[] = substr($transaction['occurred_at'], 0, 7);
            }
        }

        $months[] = CarbonImmutable::now()->format('Y-m');

        foreach (array_values(array_unique($months)) as $month) {
            RecomputeDashboardSnapshotJob::dispatch($workspaceId, $month);
            RecomputeBudgetProgressJob::dispatch($workspaceId, $month);
            RecomputeFinancialHealthJob::dispatch($workspaceId, $month);
            GenerateNetWorthSnapshotJob::dispatch($workspaceId, $month);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resultSummary(array $payload, int $workspaceId): array
    {
        $sections = [];

        foreach (self::TABLES as $key => $table) {
            $sections[$key] = $this->sectionStats($table, $workspaceId, $payload[$key] ?? []);
        }

        return [
            'schema_version' => $payload['schema_version'],
            'scope' => $payload['meta']['scope'] ?? null,
            'sections' => $sections,
        ];
    }
}
