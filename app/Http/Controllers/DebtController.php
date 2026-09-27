<?php

namespace App\Http\Controllers;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\DebtPaymentRequest;
use App\Http\Requests\DebtRequest;
use App\Models\Category;
use App\Models\Debt;
use App\Services\Debt\DebtService;
use App\Support\Workspace\ActiveWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman utang & piutang (PRD.md §3.7).
 *
 * Utang tidak pernah dihapus kalau sudah ada pembayaran: status `settled` yang
 * menandai akhir, supaya riwayat cicilan tetap punya induk yang jelas. Hapus
 * hanya berlaku untuk daftar yang masih kosong pembayaran.
 */
class DebtController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(
        private readonly DebtService $debts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $status = $this->resolveStatus($request->query('status'));
        $workspaceId = $this->workspaceId();
        $today = CarbonImmutable::today();

        return Inertia::render('debts/Index', [
            'debts' => $this->rows($workspaceId, $status, $today),
            'status' => $status->value,
            'statuses' => array_map(
                static fn (DebtStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                DebtStatus::cases(),
            ),
            'summary' => $this->debts->summary($workspaceId, $status, $today),
            'options' => $this->formOptions($workspaceId),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeEditData();

        return Inertia::render('debts/Create', [
            'options' => $this->formOptions($this->workspaceId()),
        ]);
    }

    public function store(DebtRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $this->debts->create($request->debtAttributes(), $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Utang berhasil dicatat.'),
        ]);

        return to_route('debts.index');
    }

    public function show(Debt $debt): Response
    {
        $this->authorizeViewData();

        return Inertia::render('debts/Show', [
            'debt' => $this->present($debt),
            'payments' => $this->debts->payments($debt)
                ->map(fn ($payment): array => [
                    'id' => (int) $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'note' => $payment->note,
                    'transaction_id' => $payment->transaction_id,
                ])
                ->values()
                ->all(),
            'options' => $this->formOptions($this->workspaceId()),
        ]);
    }

    public function edit(Debt $debt): Response
    {
        $this->authorizeEditData();

        return Inertia::render('debts/Edit', [
            'debt' => $this->present($debt),
            'options' => $this->formOptions($this->workspaceId()),
        ]);
    }

    public function update(DebtRequest $request, Debt $debt): RedirectResponse
    {
        $this->authorizeEditData();

        $this->debts->update($debt, $request->debtAttributes(), $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Utang berhasil diperbarui.'),
        ]);

        return to_route('debts.show', $debt);
    }

    /**
     * Catat satu cicilan. Opsinya membuat expense/income di modul Transaksi.
     */
    public function recordPayment(DebtPaymentRequest $request, Debt $debt): RedirectResponse
    {
        $this->authorizeEditData();

        $this->debts->recordPayment($debt, $request->paymentAttributes(), $request->user()->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cicilan tercatat.'),
        ]);

        return to_route('debts.show', $debt);
    }

    public function destroy(Debt $debt): RedirectResponse
    {
        $this->authorizeEditData();

        abort_if(
            $this->debts->hasPayments($debt),
            422,
            __('Utang yang sudah punya riwayat pembayaran tidak bisa dihapus. Tandai lunas dengan mencatat pembayaran terakhir.'),
        );

        $debt->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Utang dihapus.'),
        ]);

        return to_route('debts.index');
    }

    /*
    |--------------------------------------------------------------------------
    | Payload Inertia
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $workspaceId, DebtStatus $status, CarbonImmutable $today): array
    {
        return $this->debts->filtered($workspaceId, $status, $today)
            ->map(fn (Debt $debt): array => $this->present($debt))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Debt $debt): array
    {
        $status = $debt->currentStatus();

        return [
            'id' => (int) $debt->id,
            'direction' => $debt->direction->value,
            'direction_label' => $debt->direction->label(),
            'counterparty' => $debt->counterparty,
            'principal' => $debt->principal,
            'remaining' => $debt->remaining,
            'paid' => $debt->paidAmount(),
            'paid_percent' => $debt->paidPercent(),
            'interest_rate' => $debt->interest_rate,
            'start_date' => $debt->start_date?->toDateString(),
            'due_date' => $debt->due_date?->toDateString(),
            'days_until_due' => $debt->daysUntilDue(),
            'term_count' => $debt->term_count,
            'installment_amount' => $debt->installmentAmount(),
            'paid_term_count' => $debt->paidTermCount(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'include_in_net_worth' => (bool) $debt->include_in_net_worth,
            'note' => $debt->note,
            'account' => $debt->account?->only(['id', 'name']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(int $workspaceId): array
    {
        return [
            'directions' => array_map(
                static fn (DebtDirection $direction): array => [
                    'value' => $direction->value,
                    'label' => $direction->label(),
                ],
                DebtDirection::cases(),
            ),
            'accounts' => $this->debts->paymentAccounts($workspaceId)
                ->map(fn ($account): array => [
                    'id' => (int) $account->id,
                    'name' => $account->name,
                ])
                ->values()
                ->all(),
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                ])
                ->values()
                ->all(),
        ];
    }

    private function resolveStatus(mixed $value): DebtStatus
    {
        return is_string($value) ? (DebtStatus::tryFrom($value) ?? DebtStatus::Ongoing) : DebtStatus::Ongoing;
    }

    private function workspaceId(): int
    {
        return ActiveWorkspace::idOrFail();
    }
}
