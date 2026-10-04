<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\TransactionFilterRequest;
use App\Http\Requests\TransactionRequest;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Debt;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\Debt\DebtPaymentManager;
use App\Services\Transactions\TransactionManager;
use App\Support\Money;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    use AuthorizesWorkspaceData;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly DebtPaymentManager $debtPayments,
    ) {}

    /**
     * Daftar transaksi + filter (PRD.md §3.3).
     *
     * Halaman ini butuh master data sekecil mungkin untuk merender baris
     * filter: nama akun, kategori, dan tag. Ketiganya diambil lewat query
     * kecil tanpa cache — cache agregat datang di Fase 5/6.
     */
    public function index(TransactionFilterRequest $request): Response
    {
        $this->authorizeViewData();

        $filters = [
            'from' => $request->from(),
            'to' => $request->to(),
            'account_id' => $request->accountId(),
            'category_id' => $request->categoryId(),
            'tag_id' => $request->tagId(),
            'type' => $request->type(),
            'status' => $request->status(),
            'amount_min' => $request->amountMin(),
            'amount_max' => $request->amountMax(),
            'search' => $request->search(),
        ];

        $transactions = Transaction::query()
            ->occurredBetween($filters['from'], $filters['to'])
            ->when($filters['account_id'] !== null, fn ($query) => $query->involvingAccount($filters['account_id']))
            ->ofCategory($filters['category_id'])
            ->taggedWith($filters['tag_id'])
            ->when($filters['type'] !== null, fn ($query) => $query->ofType($filters['type']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']->value))
            ->amountBetween($filters['amount_min'], $filters['amount_max'])
            ->searching($filters['search'])
            // Transaksi terbaru di atas; id sebagai tie-breaker supaya
            // halaman tidak bergetar saat dua transaksi punya tanggal sama.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            // Relasi yang dipakai `present()` di-hydrate sekarang: tanpa ini
            // setiap baris memicu lima query (akun, tujuan transfer, kategori,
            // tag, lampiran) sehingga satu halaman daftar jadi ratusan query
            // (ARCHITECTURE.md §2.1 butir 5: N+1 dilarang). Kolom yang
            // dipilih persis yang dipakai payload; `tags` boleh tanpa kolom
            // pivot karena `BelongsToMany` menambahkannya sendiri.
            // `category.parent` menambah satu query untuk seluruh halaman,
            // bukan satu per baris, supaya tabel bisa menulis
            // "Kategori / Sub" tanpa memicu N+1 baru.
            ->with([
                'account:id,workspace_id,name,type',
                'transferToAccount:id,workspace_id,name,type',
                'category:id,workspace_id,parent_id,name,color',
                'category.parent:id,parent_id,name',
                'tags:id,workspace_id,name',
                'attachments:id,workspace_id,transaction_id,original_name,mime_type,size',
                'adminFee:id,workspace_id,parent_transaction_id,amount,category_id',
                'debtPayment:id,workspace_id,debt_id,transaction_id,amount,paid_at',
                'debtPayment.debt:id,workspace_id,counterparty',
            ])
            ->paginate($request->perPage())
            ->withQueryString();

        return Inertia::render('transactions/Index', [
            'transactions' => $this->presentCollection($transactions->getCollection()),
            'pagination' => $this->presentPagination($transactions),
            'filters' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'account_id' => $filters['account_id'],
                'category_id' => $filters['category_id'],
                'tag_id' => $filters['tag_id'],
                'type' => $filters['type']?->value,
                'status' => $filters['status']?->value,
                'amount_min' => $filters['amount_min'],
                'amount_max' => $filters['amount_max'],
                'search' => $filters['search'],
            ],
            'summary' => $this->summary($filters),
            'balances' => $this->accountBalances(),
            'options' => $this->formOptions(),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeViewData();

        return Inertia::render('transactions/Create', [
            'options' => $this->formOptions(),
        ]);
    }

    public function store(TransactionRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $transaction = $this->transactions->create(
            $request->transactionAttributes(),
            $request->user()?->id,
            $request->tagIds(),
            $request->file('attachment'),
            $request->adminFeeCents(),
            $request->adminFeeCategoryId(),
            $request->debtId(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaksi berhasil disimpan.'),
        ]);

        return to_route('transactions.index');
    }

    public function edit(Transaction $transaction): Response
    {
        $this->authorizeViewData();

        // `present()` membaca beberapa relasi; dimuat eksplisit supaya form edit
        // tidak memicu query tambahan. `category.parent` menambah satu query
        // supaya select kategori utama/sub terisi benar saat transaksi yang
        // disimpan memakai sub-kategori.
        $transaction->load([
            'account',
            'transferToAccount',
            'category',
            'category.parent',
            'tags',
            'attachments',
            'adminFee',
            'debtPayment.debt',
        ]);

        return Inertia::render('transactions/Edit', [
            'transaction' => $this->present($transaction),
            // Utang yang sudah tertaut ikut dikirim sebagai opsi walau sudah
            // lunas, supaya form edit menampilkannya dan bisa dilepas.
            'options' => $this->formOptions($transaction->debtPayment?->debt),
        ]);
    }

    public function update(TransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeEditData();

        $this->transactions->update(
            $transaction,
            $request->transactionAttributes(),
            $request->user()?->id,
            $request->tagIds(),
            $request->file('attachment'),
            $request->shouldRemoveAttachment(),
            $request->adminFeeCents(),
            $request->adminFeeCategoryId(),
            $request->debtId(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaksi berhasil diperbarui.'),
        ]);

        return to_route('transactions.index');
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeEditData();

        $this->transactions->delete($transaction);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaksi berhasil dihapus.'),
        ]);

        return to_route('transactions.index');
    }

    /**
     * Konfirmasi instance transaksi berulang yang berstatus pending.
     *
     * Baru di titik ini saldo ikut berubah — sebelumnya instance pending
     * memang selalu bebas efek.
     */
    public function confirm(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeEditData();

        $this->transactions->confirm($transaction, $request->user()?->id);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaksi berulang dikonfirmasi.'),
        ]);

        return to_route('transactions.index');
    }

    /**
     * Buang instance pending tanpa menyentuh saldo.
     */
    public function discard(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorizeEditData();

        $this->transactions->discard($transaction);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Transaksi berulang dibuang.'),
        ]);

        return to_route('transactions.index');
    }

    /**
     * Unduh lampiran bukti. Row di-scope ke workspace aktif, jadi lampiran
     * milik workspace lain tidak bisa diunduh meski tahu ID-nya.
     */
    public function download(Attachment $attachment): HttpResponse|StreamedResponse
    {
        $this->authorizeViewData();

        $stream = $attachment->readStream();

        abort_if($stream === null, 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'Content-Length' => (string) $attachment->size,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /*
     |--------------------------------------------------------------------------
     | Ringkasan & opsi
     |--------------------------------------------------------------------------
     */

    /**
     * Total income / expense pada rentang filter yang sedang aktif, plus
     * saldo akhir tiap akun yang muncul di hasil filter.
     *
     * Dihitung dari himpunan baris yang sudah difilter, bukan dari
     * `accounts.cached_balance`, supaya angkanya konsisten dengan tabel yang
     * sedang dilihat pengguna.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $totals = $this->filteredQuery($filters)
            ->posted()
            ->selectRaw('type, COUNT(*) as total_rows, SUM(amount) as total_amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $income = (string) ($totals[TransactionType::Income->value]->total_amount ?? '0');
        $expense = (string) ($totals[TransactionType::Expense->value]->total_amount ?? '0');

        return [
            'count' => (int) $totals->sum('total_rows'),
            'income' => Money::fromCents(Money::toCents($income)),
            'expense' => Money::fromCents(Money::toCents($expense)),
            // Transfer tidak boleh dihitung sebagai income; saldo transfer
            // masuk dan keluar saling meniadakan di total workspace.
            'net' => Money::fromCents(Money::toCents($income) - Money::toCents($expense)),
            'pending_count' => $this->filteredQuery($filters)
                ->pending()
                ->count(),
        ];
    }

    /**
     * Query transaksi yang sama persis dengan yang dipakai tabel, minus
     * sorting/paginasi — dipakai ulang oleh {@see summary()}.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function filteredQuery(array $filters): Builder
    {
        return Transaction::query()
            ->occurredBetween($filters['from'], $filters['to'])
            ->when($filters['account_id'] !== null, fn ($query) => $query->involvingAccount($filters['account_id']))
            ->ofCategory($filters['category_id'])
            ->taggedWith($filters['tag_id'])
            ->when($filters['type'] !== null, fn ($query) => $query->ofType($filters['type']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']->value))
            ->amountBetween($filters['amount_min'], $filters['amount_max'])
            ->searching($filters['search']);
    }

    /**
     * Saldo terkini semua akun aktif untuk panel "Saldo Akun" di halaman ini.
     *
     * Satu query kecil tanpa cache: daftar akun itu master data yang sudah
     * dimuat form filter, dan `accounts.cached_balance` memang sudah
     * dipelihara TransactionManager setiap kali saldo berubah — bukan angka
     * yang harus dihitung ulang dari transaksi. Kartu kredit (saldanya negatif
     * = utang) dan tabungan (sudah disisihkan) tetap ikut dikirim supaya panel
     * ini menampilkan semua akun, tapi keduanya tidak ikut dijumlahkan ke
     * total — sama seperti halaman Akun dan Dashboard.
     *
     * @return array{accounts: array<int, array<string, mixed>>, total: string}
     */
    private function accountBalances(): array
    {
        $accounts = Account::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'cached_balance', 'credit_limit']);

        $total = Money::fromCents(0);

        $items = $accounts->map(function (Account $account) use (&$total): array {
            if ($account->type->countsInTotalBalance()) {
                $total = Money::add($total, $account->cached_balance);
            }

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'type_icon' => $account->type->icon(),
                'is_credit' => $account->isCredit(),
                'balance' => $account->cached_balance,
                'credit_limit' => $account->credit_limit,
                'available_credit' => $account->availableCredit(),
                'credit_usage_percent' => $account->creditUsagePercent(),
            ];
        })->values();

        return [
            'accounts' => $items->all(),
            'total' => $total,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountOptions(): array
    {
        return Account::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
            ])
            ->all();
    }

    /**
     * Kategori untuk select form transaksi.
     *
     * Semua kategori ikut, kategori utama lebih dulu lalu sub-kategorinya
     * tepat setelah induknya supaya urutannya terbaca sebagai pohon di
     * dropdown. Level ditandai lewat `parent_id` supaya form bisa memisahkan
     * select kategori utama dan sub-kategori tanpa query tambahan. Bentuk
     * payload-nya sama dengan `BudgetController::formOptions()`.
     *
     * @return array<int, array{id: int, name: string, color: string, parent_id: int|null}>
     */
    private function categoryOptions(): array
    {
        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'color']);

        $childrenByParent = [];

        foreach ($categories as $category) {
            if ($category->parent_id !== null) {
                $childrenByParent[(int) $category->parent_id][] = $category;
            }
        }

        return $categories
            ->filter(fn (Category $category): bool => $category->isRoot())
            ->flatMap(function (Category $root) use ($childrenByParent): array {
                $options = [$this->categoryOption($root)];

                foreach ($childrenByParent[(int) $root->id] ?? [] as $child) {
                    $options[] = $this->categoryOption($child);
                }

                return $options;
            })
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, color: string, parent_id: int|null}
     */
    private function categoryOption(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'color' => $category->color,
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
        ];
    }

    /**
     * Opsi master data untuk form transaksi: akun, kategori, tag, dan daftar
     * utang yang harus dibayar.
     *
     * Utang ikut dikirim karena select "Bayar utang" hanya perlu menampilkan
     * utang yang masih punya sisa — bukan seluruh riwayat utang yang sudah
     * lunas.
     *
     * @return array{accounts: array<int, mixed>, categories: array<int, mixed>, tags: array<int, mixed>, debts: array<int, mixed>}
     */
    private function formOptions(?Debt $linkedDebt = null): array
    {
        return [
            'accounts' => $this->accountOptions(),
            'categories' => $this->categoryOptions(),
            'tags' => $this->tagOptions(),
            'debts' => $this->debtOptions($linkedDebt),
        ];
    }

    /**
     * Utang yang harus dibayar, untuk select "Bayar utang".
     *
     * @return array<int, array{id: int, counterparty: string, remaining: string, due_date: string|null, status_label: string}>
     */
    private function debtOptions(?Debt $linkedDebt = null): array
    {
        $workspace = ActiveWorkspace::workspace();

        abort_if($workspace === null, 403);

        return $this->debtPayments->payableOptions($workspace->id, $linkedDebt)->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tagOptions(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])
            ->values()
            ->all();
    }

    /*
     |--------------------------------------------------------------------------
     | Payload Inertia
     |--------------------------------------------------------------------------
     */

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array<string, mixed>>
     */
    private function presentCollection(Collection $transactions): array
    {
        return $transactions
            ->map(fn (Transaction $transaction): array => $this->present($transaction))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction): array
    {
        $payment = $transaction->debtPayment;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'type_label' => $transaction->type->label(),
            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'is_pending' => $transaction->isPending(),
            'is_transfer' => $transaction->isTransfer(),
            'amount' => $transaction->amount,
            // Nominal bertanda supaya tabel bisa menampilkan "+"/"−" tanpa
            // perlu tahu aturan tipe di sisi JavaScript.
            'signed_amount' => $this->signedAmount($transaction),
            'note' => $transaction->note,
            'occurred_at' => $transaction->occurred_at->toDateString(),
            'occurred_at_iso' => $transaction->occurred_at->toIso8601String(),
            'account' => $transaction->account?->only(['id', 'name', 'type']),
            'transfer_to_account' => $transaction->transferToAccount?->only(['id', 'name', 'type']),
            'category' => $this->presentCategory($transaction),
            'tags' => $transaction->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ])
                ->values()
                ->all(),
            'tag_ids' => $transaction->tags->pluck('id')->all(),
            'attachments' => $transaction->attachments
                ->map(fn (Attachment $attachment): array => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'human_size' => $attachment->humanSize(),
                    'url' => route('transactions.attachments.download', $attachment),
                ])
                ->values()
                ->all(),
            'is_recurring_instance' => $transaction->recurring_rule_id !== null,
            // Nominal potongan admin transfer ini (untuk mengisi form edit) dan
            // penanda baris yang memang hasil potongan admin (untuk badge di
            // daftar). Keduanya sudah ter-eager-load, jadi tidak menambah query.
            'admin_fee' => $transaction->adminFee?->amount,
            'admin_fee_category_id' => $transaction->adminFee?->category_id,
            'is_admin_fee' => $transaction->isAdminFee(),
            // Utang yang dilunasi expense ini, untuk mengisi select "Bayar
            // utang" di form edit dan badge di daftar.
            'debt_id' => $payment?->debt_id,
            'debt' => $payment?->debt?->only(['id', 'counterparty']),
            'is_debt_payment' => $payment !== null,
        ];
    }

    /**
     * Kategori milik transaksi, lengkap dengan nama induknya supaya tabel
     * bisa menulis "Kategori / Sub" dan form edit bisa mengisi dua select
     * (kategori utama lalu sub-kategori) tanpa menebak induk dari nama.
     *
     * @return array{id: int, name: string, color: string, parent_id: int|null, parent_name: string|null}|null
     */
    private function presentCategory(Transaction $transaction): ?array
    {
        $category = $transaction->category;

        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'color' => $category->color,
            'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
            'parent_name' => $category->parent?->name,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Transaction>  $paginator
     * @return array<string, mixed>
     */
    private function presentPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * Nominal bertanda dari sudut pandang akun sumber.
     */
    private function signedAmount(Transaction $transaction): string
    {
        $cents = Money::toCents($transaction->amount) * $transaction->type->sourceSign();

        return Money::fromCents($cents);
    }
}
