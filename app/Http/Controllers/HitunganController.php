<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\HitunganPlanRequest;
use App\Models\HitunganPlan;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Dashboard\DashboardService;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Hitungan": kalkulator rencana anggaran bulanan.
 *
 * Perhitungan (saldo aktual, limit harian, total rencana) dihitung ulang di
 * browser, mirip spreadsheet "BUDGET PLAN" pada referensi. Yang disimpan ke
 * database hanya input rencananya — satu baris per workspace — supaya user
 * bisa menutup halaman lalu melanjutkannya nanti.
 *
 * `balance` tetap tidak disimpan: selalu dihitung ulang dari total saldo akun
 * aktif (kas/bank/e-wallet; tabungan dan kartu kredit tidak ikut), sama seperti
 * definisi "saldo total" di dashboard — lihat {@see DashboardService::totalBalance()}.
 * Tanggal mulai juga tidak disimpan karena selalu hari ini, begitu pula saldo
 * minimum yang merupakan hitungan limit harian × jumlah hari.
 */
class HitunganController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function index(): Response
    {
        $this->authorizeViewData();

        return Inertia::render('hitungan/Index', [
            'balance' => $this->dashboard->totalBalance(ActiveWorkspace::idOrFail()),
            'expenses_today' => $this->dashboard->expensesToday(ActiveWorkspace::idOrFail()),
            'today' => CarbonImmutable::now()->toDateString(),
            'plan' => $this->planPayload(),
        ]);
    }

    /**
     * Simpan (atau timpa) rencana milik workspace aktif.
     *
     * Satu workspace hanya punya satu rencana, jadi tidak ada id di URL:
     * pemanggil menulis baris yang ada atau membuat baris baru.
     */
    public function save(HitunganPlanRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $plan = $this->plan();
        $plan->fill($request->planAttributes());
        $plan->updated_by = $request->user()?->id;

        if (! $plan->exists) {
            $plan->created_by = $request->user()?->id;
        }

        $plan->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Perhitungan berhasil disimpan.'),
        ]);

        return to_route('hitungan.index');
    }

    /**
     * Baris rencana workspace aktif, atau instance kosong bila belum pernah
     * disimpan. Global scope {@see WorkspaceScope} sudah
     * menyaring ke workspace aktif, jadi `first()` cukup.
     */
    private function plan(): HitunganPlan
    {
        return HitunganPlan::query()->firstOrNew([]);
    }

    /**
     * Payload rencana untuk mengisi form, atau null bila belum ada.
     *
     * @return array<string, mixed>|null
     */
    private function planPayload(): ?array
    {
        $plan = HitunganPlan::query()->first();

        if ($plan === null) {
            return null;
        }

        return [
            'daily_limit' => $plan->daily_limit,
            'end_date' => $plan->end_date === null ? null : $plan->end_date->toDateString(),
            'rows' => $plan->rows ?? [],
            'saved_at' => $plan->updated_at->toIso8601String(),
        ];
    }
}
