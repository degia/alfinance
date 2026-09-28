<?php

namespace App\Services\Exports;

use App\Enums\ExportType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exports\TabularExport;
use App\Http\Requests\ReportFilterRequest;
use App\Models\ExportJob;
use App\Models\Transaction;
use App\Models\Workspace;
use App\Services\Reports\ReportService;
use App\Support\Money;
use App\Support\MonthPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use RuntimeException;

/**
 * Membangun berkas ekspor (PRD.md §3.10).
 *
 * Semua ekspor dipanggil dari antrean (`ProcessExportJob`), bukan dari jalur
 * request, sesuai ARCHITECTURE.md §2.1 butir 4. Laporan membaca payload yang
 * sudah di-cache oleh {`ReportService`} — tidak ada agregasi baru di sini.
 * Transaksi memakai scope + filter yang sama persis dengan halaman daftar.
 */
class ExportService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Bangun berkas ekspor untuk satu job dan kembalikan metadata file.
     *
     * @return array{file_path: string, file_name: string, size_bytes: int}
     */
    public function build(ExportJob $job): array
    {
        return $job->type->isSpreadsheet()
            ? $this->buildSpreadsheet($job)
            : $this->buildPdf($job);
    }

    /**
     * @return array{file_path: string, file_name: string, size_bytes: int}
     */
    private function buildSpreadsheet(ExportJob $job): array
    {
        $workspaceId = (int) $job->workspace_id;
        $filters = $job->filters ?? [];

        [$headings, $rows] = $job->type->reportKind() !== null
            ? $this->reportRowsForSpreadsheet($job->type, $workspaceId, $filters)
            : $this->transactionRows($workspaceId, $filters);

        $name = $this->fileName($job);
        $path = 'exports/'.$workspaceId.'/'.$name;

        $disk = Storage::disk('exports');
        $content = ExcelFacade::raw(
            new TabularExport($rows, $headings),
            $job->type->isCsv() ? Excel::CSV : Excel::XLSX,
        );

        if (! $disk->put($path, $content)) {
            throw new RuntimeException('File ekspor tidak bisa ditulis ke storage.');
        }

        return [
            'file_path' => $path,
            'file_name' => $name,
            'size_bytes' => strlen($content),
        ];
    }

    /**
     * @return array{file_path: string, file_name: string, size_bytes: int}
     */
    private function buildPdf(ExportJob $job): array
    {
        $workspaceId = (int) $job->workspace_id;
        $filters = $job->filters ?? [];
        $view = $this->reportView($job->type);
        $bounds = $this->bounds($filters);

        $workspaceName = (string) (Workspace::query()
            ->where('id', $workspaceId)
            ->value('name') ?? $workspaceId);

        $html = View::make($view['view'], [
            'workspace' => $workspaceName,
            'period' => [
                'from' => $bounds['from']->format('Y-m'),
                'to' => $bounds['to']->format('Y-m'),
            ],
            'filters' => $filters,
            'payload' => $this->reportPayload($job->type, $workspaceId, $filters),
            'generated_at' => CarbonImmutable::now()->format('d-m-Y H:i'),
        ])->render();

        $content = Pdf::loadHTML($html)
            ->setPaper('a4', $view['orientation'])
            ->output();

        $name = $this->fileName($job);
        $path = 'exports/'.$workspaceId.'/'.$name;

        $disk = Storage::disk('exports');

        if (! $disk->put($path, $content)) {
            throw new RuntimeException('File PDF tidak bisa ditulis ke storage.');
        }

        return [
            'file_path' => $path,
            'file_name' => $name,
            'size_bytes' => strlen($content),
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | Transaksi
     |--------------------------------------------------------------------------
     */

    /**
     * Baris transaksi + heading, memakai scope filter yang identik dengan
     * halaman daftar (TransactionFilterRequest).
     *
     * @param  array<string, mixed>  $filters
     * @return array{array<int, string>, array<int, array<int|string, mixed>>}
     */
    private function transactionRows(int $workspaceId, array $filters): array
    {
        $from = $this->stringOrNull($filters['from'] ?? null);
        $to = $this->stringOrNull($filters['to'] ?? null);
        $accountId = $this->intOrNull($filters['account_id'] ?? null);
        $categoryId = $this->intOrNull($filters['category_id'] ?? null);
        $tagId = $this->intOrNull($filters['tag_id'] ?? null);
        $txType = $this->transactionTypeOrNull($filters['tx_type'] ?? null);
        $status = $this->transactionStatusOrNull($filters['tx_status'] ?? null);
        $amountMin = $this->stringOrNull($filters['amount_min'] ?? null);
        $amountMax = $this->stringOrNull($filters['amount_max'] ?? null);
        $search = $this->stringOrNull($filters['search'] ?? null);

        $transactions = Transaction::allWorkspaces()
            ->with(['account:id,name', 'transferToAccount:id,name', 'category:id,name', 'tags:id,name'])
            ->where('workspace_id', $workspaceId)
            ->occurredBetween($from, $to)
            ->when($accountId !== null, fn ($query) => $query->involvingAccount($accountId))
            ->ofCategory($categoryId)
            ->taggedWith($tagId)
            ->when($txType !== null, fn ($query) => $query->ofType($txType))
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->amountBetween($amountMin, $amountMax)
            ->searching($search)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $headings = ['ID', 'Tanggal', 'Tipe', 'Status', 'Akun', 'Transfer ke', 'Kategori', 'Catatan', 'Tag', 'Nominal'];
        $rows = [];

        foreach ($transactions as $transaction) {
            $rows[] = [
                $transaction->id,
                $transaction->occurred_at->toDateString(),
                $transaction->type->label(),
                $transaction->status->label(),
                $transaction->account?->name,
                $transaction->transferToAccount?->name,
                $transaction->category?->name,
                $transaction->note,
                $transaction->tags->pluck('name')->implode(', '),
                Money::fromCents(Money::toCents($transaction->amount) * $transaction->type->sourceSign()),
            ];
        }

        return [$headings, $rows];
    }

    /*
     |--------------------------------------------------------------------------
     | Laporan
     |--------------------------------------------------------------------------
     */

    /**
     * Payload laporan yang sudah di-cache {`ReportService`}.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function reportPayload(ExportType $type, int $workspaceId, array $filters): array
    {
        $bounds = $this->bounds($filters);
        $accountId = $this->intOrNull($filters['account_id'] ?? null);
        $categoryId = $this->intOrNull($filters['category_id'] ?? null);

        return match ($type->reportKind()) {
            'cash-flow' => $this->reports->cashFlow($workspaceId, $bounds['from'], $bounds['to'], $accountId),
            'budget-vs-actual' => $this->reports->budgetVsActual($workspaceId, $bounds['from'], $bounds['to'], $categoryId),
            'expense-breakdown' => $this->reports->expenseBreakdown($workspaceId, $bounds['from'], $bounds['to'], $categoryId),
            default => throw new RuntimeException('Jenis laporan tidak dikenal.'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{array<int, string>, array<int, array<int|string, mixed>>}
     */
    private function reportRowsForSpreadsheet(ExportType $type, int $workspaceId, array $filters): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->reportPayload($type, $workspaceId, $filters);

        return match ($type->reportKind()) {
            'cash-flow' => $this->cashFlowRows($payload),
            'budget-vs-actual' => $this->budgetRows($payload),
            'expense-breakdown' => $this->expenseRows($payload),
            default => throw new RuntimeException('Jenis laporan tidak dikenal.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<int, string>, array<int, array<int|string, mixed>>}
     */
    private function cashFlowRows(array $payload): array
    {
        /** @var array<int, array<string, mixed>> $points */
        $points = $payload['points'] ?? [];
        /** @var array<string, mixed> $totals */
        $totals = $payload['totals'] ?? [];

        $headings = ['Bulan', 'Pemasukan', 'Pengeluaran', 'Arus bersih', 'Transfer', 'Jumlah transaksi'];
        $rows = [];

        foreach ($points as $point) {
            $rows[] = [
                $point['month'] ?? '',
                $point['income'] ?? '0.00',
                $point['expense'] ?? '0.00',
                $point['net_cash_flow'] ?? '0.00',
                $point['total_transfer'] ?? '0.00',
                $point['transaction_count'] ?? 0,
            ];
        }

        $rows[] = [
            'Total',
            $totals['income'] ?? '0.00',
            $totals['expense'] ?? '0.00',
            $totals['net_cash_flow'] ?? '0.00',
            $totals['total_transfer'] ?? '0.00',
            '',
        ];

        return [$headings, $rows];
    }

    /**
     * Bentuk panjang (long format): satu baris per kategori per bulan.
     *
     * @param  array<string, mixed>  $payload
     * @return array{array<int, string>, array<int, array<int|string, mixed>>}
     */
    private function budgetRows(array $payload): array
    {
        /** @var array<int, array<string, mixed>> $categories */
        $categories = $payload['categories'] ?? [];
        /** @var array<int, string> $months */
        $months = $payload['months'] ?? [];

        $headings = ['Kategori', 'Bulan', 'Limit', 'Terpakai', 'Sisa', 'Persen', 'Melebihi'];
        $rows = [];

        foreach ($categories as $category) {
            /** @var array<string, mixed> $cells */
            $cells = $category['cells'] ?? [];
            $name = (string) ($category['name'] ?? '');

            foreach ($months as $month) {
                /** @var array<string, mixed> $cell */
                $cell = $cells[$month] ?? [];
                $rows[] = [
                    $name,
                    $month,
                    $cell['limit'] ?? '0.00',
                    $cell['used'] ?? '0.00',
                    $cell['remaining'] ?? '0.00',
                    $cell['percent'] ?? null,
                    isset($cell['is_over']) && $cell['is_over'] ? 'Ya' : 'Tidak',
                ];
            }
        }

        return [$headings, $rows];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<int, string>, array<int, array<int|string, mixed>>}
     */
    private function expenseRows(array $payload): array
    {
        /** @var array<int, string> $months */
        $months = $payload['months'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $items = $payload['items'] ?? [];

        $headings = array_merge(['Kategori', 'Total', 'Persen'], $months);
        $rows = [];

        foreach ($items as $item) {
            /** @var array<string, mixed> $byMonth */
            $byMonth = $item['by_month'] ?? [];
            $row = [
                (string) ($item['name'] ?? ''),
                $item['total'] ?? '0.00',
                $item['percent'] ?? null,
            ];

            foreach ($months as $month) {
                $row[] = $byMonth[$month] ?? '0.00';
            }

            $rows[] = $row;
        }

        return [$headings, $rows];
    }

    /*
     |--------------------------------------------------------------------------
     | Pemetaan
     |--------------------------------------------------------------------------
     */

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function transactionTypeOrNull(mixed $value): ?TransactionType
    {
        return is_string($value) && $value !== ''
            ? TransactionType::tryFrom($value)
            : null;
    }

    private function transactionStatusOrNull(mixed $value): ?TransactionStatus
    {
        return is_string($value) && $value !== ''
            ? TransactionStatus::tryFrom($value)
            : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private function bounds(array $filters): array
    {
        $now = CarbonImmutable::now()->startOfMonth();
        $to = $this->stringOrNull($filters['to'] ?? null) !== null
            ? MonthPeriod::from($this->stringOrNull($filters['to'] ?? null))
            : $now;
        $from = $this->stringOrNull($filters['from'] ?? null) !== null
            ? MonthPeriod::from($this->stringOrNull($filters['from'] ?? null))
            : $to->subMonthsNoOverflow(ReportFilterRequest::DEFAULT_MONTHS - 1);

        if ($from->greaterThan($to)) {
            $from = $to;
        }

        if (($from->diffInMonths($to) + 1) > ReportFilterRequest::MAX_MONTHS) {
            $from = $to->subMonthsNoOverflow(ReportFilterRequest::MAX_MONTHS - 1);
        }

        return compact('from', 'to');
    }

    /**
     * @return array{view: 'pdf.reports.cash-flow'|'pdf.reports.budget-vs-actual'|'pdf.reports.expense-breakdown', orientation: 'portrait'|'landscape'}
     */
    private function reportView(ExportType $type): array
    {
        return match ($type) {
            ExportType::CashFlowPdf => ['view' => 'pdf.reports.cash-flow', 'orientation' => 'portrait'],
            ExportType::BudgetPdf => ['view' => 'pdf.reports.budget-vs-actual', 'orientation' => 'landscape'],
            ExportType::ExpensePdf => ['view' => 'pdf.reports.expense-breakdown', 'orientation' => 'landscape'],
            default => throw new RuntimeException('Bukan jenis ekspor PDF.'),
        };
    }

    /**
     * Nama file ekspor: `{slug}-{timestamp}.{ext}`.
     */
    private function fileName(ExportJob $job): string
    {
        return sprintf(
            '%s-%s.%s',
            $job->type->slug(),
            CarbonImmutable::now()->format('Ymd-His'),
            $job->type->extension(),
        );
    }
}
