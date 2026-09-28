<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Category;
use App\Models\Workspace;
use App\Support\MonthPeriod;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi query filter halaman laporan (PRD.md §3.8).
 *
 * Rentang tanggal dinormalkan ke batas bulan karena laporan membaca tabel
 * agregat bulanan (`dashboard_snapshots`, `budget_progress_cache`): rentang
 * "2026-09-15".."2026-11-03" berarti September sampai November. Bulannya
 * sendiri masuk ke cache key, jadi setiap rentang punya cache terpisah dan
 * tidak ada yang perlu di-recompute manual saat filter berubah.
 */
class ReportFilterRequest extends FormRequest
{
    /**
     * Panjang maksimum rentang yang diizinkan, dalam bulan. Protect query &
     * cache dari rentang yang absurd; 36 bulan (3 tahun) masih wajar untuk
     * laporan keuangan pribadi.
     */
    public const MAX_MONTHS = 36;

    /**
     * Rentang default saat form dibuka tanpa query string: 12 bulan ke
     * belakang sampai bulan berjalan. Cukup untuk merender tabel 12 kolom
     * tanpa lebar yang berlebihan di laptop.
     */
    public const DEFAULT_MONTHS = 12;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workspaceId = $this->workspaceId();

        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => [
                'nullable',
                Rule::exists('accounts', 'id')->where('workspace_id', $workspaceId),
            ],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('workspace_id', $workspaceId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('Tanggal akhir tidak boleh sebelum tanggal mulai.'),
            'account_id.exists' => __('Akun tidak ditemukan di workspace ini.'),
            'category_id.exists' => __('Kategori tidak ditemukan di workspace ini.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => __('tanggal mulai'),
            'to' => __('tanggal akhir'),
            'account_id' => __('akun'),
            'category_id' => __('kategori'),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function bounds(): array
    {
        $inputFrom = $this->validated('from');
        $inputTo = $this->validated('to');

        $end = is_string($inputTo) && $inputTo !== ''
            ? MonthPeriod::from($inputTo)
            : CarbonImmutable::now()->startOfMonth();

        $start = is_string($inputFrom) && $inputFrom !== ''
            ? MonthPeriod::from($inputFrom)
            : $end->subMonthsNoOverflow(self::DEFAULT_MONTHS - 1);

        // Rentang dibalik bukan berarti error fatal: laporan lebih baik
        // menampilkan satu bulan daripada membiarkan user terjebak form kosong.
        if ($start->greaterThan($end)) {
            $start = $end;
        }

        $months = $start->diffInMonths($end) + 1;

        if ($months > self::MAX_MONTHS) {
            $start = $end->subMonthsNoOverflow(self::MAX_MONTHS - 1);
        }

        return [
            'from' => MonthPeriod::key($start),
            'to' => MonthPeriod::key($end),
        ];
    }

    public function fromMonth(): CarbonImmutable
    {
        return MonthPeriod::from($this->bounds()['from']);
    }

    public function toMonth(): CarbonImmutable
    {
        return MonthPeriod::from($this->bounds()['to']);
    }

    public function accountId(): ?int
    {
        return $this->filled('account_id') ? (int) $this->validated('account_id') : null;
    }

    public function categoryId(): ?int
    {
        return $this->filled('category_id') ? (int) $this->validated('category_id') : null;
    }

    /**
     * Opsi filter untuk form: akun aktif + kategori induk workspace ini.
     *
     * @return array{accounts: array<int, array{id: int, name: string}>, categories: array<int, array{id: int, name: string}>}
     */
    public function options(): array
    {
        $workspaceId = $this->workspaceId();

        return [
            'accounts' => Account::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Account $account): array => [
                    'id' => (int) $account->id,
                    'name' => $account->name,
                ])
                ->all(),
            'categories' => Category::allWorkspaces()
                ->where('workspace_id', $workspaceId)
                ->roots()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                ])
                ->all(),
        ];
    }

    private function workspaceId(): int
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if(! $workspace instanceof Workspace, 403);

        return $workspace->id;
    }
}
