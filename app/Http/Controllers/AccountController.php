<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesWorkspaceData;

    /**
     * Halaman master akun. Arsip ditampilkan terpisah agar tidak memenuhi
     * daftar utama tapi tetap bisa dipulihkan.
     */
    public function index(Request $request): Response
    {
        $this->authorizeViewData();

        $includeArchived = $request->boolean('archived');

        $accounts = Account::query()
            ->when($includeArchived, fn ($query) => $query->archived(), fn ($query) => $query->active())
            ->orderBy('name')
            ->get();

        return Inertia::render('accounts/Index', [
            'accounts' => $accounts
                ->map(fn (Account $account): array => $this->present($account))
                ->values()
                ->all(),
            'filters' => [
                'archived' => $includeArchived,
            ],
            'totalBalance' => $this->sumBalance($accounts),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->accountAttributes();

        $account = new Account($attributes);
        $account->cached_balance = $attributes['initial_balance'];
        $account->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Akun :name berhasil ditambahkan.', ['name' => $account->name]),
        ]);

        return to_route('accounts.index');
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->authorizeEditData();

        $attributes = $request->accountAttributes();

        // Saldo terkini hanya boleh berubah lewat transaksi (Fase 3). Jadi
        // koreksi `initial_balance` ikut menggeser `cached_balance` sebesar
        // selisihnya, bukan menimpa saldo yang sudah berjalan.
        if ($attributes['initial_balance'] !== $account->initial_balance) {
            $account->cached_balance = Money::add(
                $account->cached_balance,
                Money::subtract($attributes['initial_balance'], $account->initial_balance),
            );
        }

        $account->fill($attributes)->save();
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Akun :name berhasil diperbarui.', ['name' => $account->name]),
        ]);

        return to_route('accounts.index');
    }

    /**
     * Arsipkan akun (PRD.md §3.2). Akun tidak pernah dihapus supaya histori
     * transaksi tetap utuh.
     */
    public function archive(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeEditData();

        $account->archive();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Akun :name diarsipkan.', ['name' => $account->name]),
        ]);

        return to_route('accounts.index');
    }

    public function restore(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeEditData();

        $account->restore();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Akun :name dipulihkan.', ['name' => $account->name]),
        ]);

        return to_route('accounts.index');
    }

    /**
     * Bentuk payload untuk Inertia. Nilai uang dikirim sebagai string desimal
     * agar presisi DECIMAL(15,2) tidak hilang di sisi JavaScript.
     *
     * @return array<string, mixed>
     */
    private function present(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'type_icon' => $account->type->icon(),
            'is_credit' => $account->isCredit(),
            'initial_balance' => $account->initial_balance,
            'cached_balance' => $account->cached_balance,
            'credit_limit' => $account->credit_limit,
            'available_credit' => $account->availableCredit(),
            'credit_usage_percent' => $account->creditUsagePercent(),
            'billing_day' => $account->billing_day,
            'due_day' => $account->due_day,
            'next_billing_date' => $account->nextBillingDate()?->toDateString(),
            'next_due_date' => $account->nextDueDate()?->toDateString(),
            'notes' => $account->notes,
            'archived_at' => $account->archived_at?->toDateString(),
            'is_archived' => $account->isArchived(),
        ];
    }

    /**
     * Total saldo seluruh akun aktif non-liabilitas.
     *
     * Ini bukan agregat berat: jumlahnya kecil dan sudah dimuat di memori
     * pada request yang sama, jadi aman dihitung langsung. Agregat berat
     * (dashboard, report) tidak pernah dihitung on-the-fly — itu dibaca
     * lewat cache di Fase 5/6.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function sumBalance(Collection $accounts): string
    {
        $total = Money::fromCents(0);

        foreach ($accounts as $account) {
            if ($account->isArchived() || $account->isCredit()) {
                continue;
            }

            $total = Money::add($total, $account->cached_balance);
        }

        return $total;
    }
}
