<?php

namespace Database\Seeders;

use App\Enums\CategoryIcon;
use App\Enums\RecurringFrequency;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Transactions\TransactionManager;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Database\Seeder;

/**
 * Mengisi satu workspace yang sudah ada dengan data contoh Fase 3.
 *
 * Berbeda dengan seeder untuk instalasi baru, seeder ini menyasar workspace
 * milik user yang sudah terdaftar dan hanya boleh berjalan sekali. Semua
 * transaksi dibuat lewat {@see TransactionManager} supaya `cached_balance`
 * benar-benar dihitung oleh sistem, bukan diisi manual.
 *
 * Cara pakai:
 *   php artisan db:seed --class=WorkspaceDemoSeeder
 *
 * Target workspace diambil dari `config/demo.php` (env `DEMO_WORKSPACE_EMAIL`).
 */
class WorkspaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = $this->target();

        if ($workspace === null) {
            return;
        }

        $previous = ActiveWorkspace::workspace();
        ActiveWorkspace::set($workspace);

        try {
            $this->seed($workspace);
        } finally {
            ActiveWorkspace::set($previous);
        }
    }

    private function target(): ?Workspace
    {
        $email = config('demo.workspace_email');

        if (! is_string($email) || $email === '') {
            $this->command->error('config demo.workspace_email belum diisi.');

            return null;
        }

        $user = User::where('email', $email)->first();
        $workspace = $user?->workspaces()->first();

        if ($workspace === null) {
            $this->command->error("User {$email} tidak punya workspace.");

            return null;
        }

        return $workspace;
    }

    private function seed(Workspace $workspace): void
    {
        // Pengecekan dilakukan setelah workspace aktif supaya global scope
        // tidak mengembalikan nol baris (fail-closed).
        if (Account::query()->exists() || Transaction::query()->exists()) {
            $this->command->warn("Workspace #{$workspace->id} sudah punya akun atau transaksi — seed dilewati.");

            return;
        }

        $user = $workspace->users()->wherePivot('role', 'owner')->first()
            ?? $workspace->users()->first();

        if ($user === null) {
            $this->command->error("Workspace #{$workspace->id} tidak punya anggota.");

            return;
        }

        $accounts = $this->accounts($workspace);
        $categories = $this->categories($workspace);
        $tags = $this->tags($workspace);
        $manager = app(TransactionManager::class);
        $month = now()->startOfMonth();

        $rows = [
            ['type' => TransactionType::Income, 'account' => 'bank', 'category' => 'gaji', 'amount' => '8500000.00', 'note' => 'Gaji bulanan', 'tags' => ['rutin'], 'day' => 1],
            ['type' => TransactionType::Expense, 'account' => 'bank', 'category' => 'makan', 'amount' => '85000.00', 'note' => 'Makan siang kantor', 'tags' => ['rutin'], 'day' => 2],
            ['type' => TransactionType::Expense, 'account' => 'ewallet', 'category' => 'makan', 'amount' => '32500.00', 'note' => 'Kopi dan roti', 'tags' => [], 'day' => 3],
            ['type' => TransactionType::Expense, 'account' => 'bank', 'category' => 'transport', 'amount' => '125000.00', 'note' => 'Bensin pertamax', 'tags' => [], 'day' => 5],
            ['type' => TransactionType::Expense, 'account' => 'card', 'category' => 'belanja', 'amount' => '479000.00', 'note' => 'Belanja supermarket', 'tags' => ['bulanan'], 'day' => 6],
            ['type' => TransactionType::Expense, 'account' => 'cash', 'category' => 'hiburan', 'amount' => '90000.00', 'note' => 'Nonton bioskop', 'tags' => [], 'day' => 8],
            ['type' => TransactionType::Expense, 'account' => 'bank', 'category' => 'tagihan', 'amount' => '650000.00', 'note' => 'Listrik dan air', 'tags' => ['tagihan'], 'day' => 10],
            ['type' => TransactionType::Expense, 'account' => 'card', 'category' => 'tagihan', 'amount' => '350000.00', 'note' => 'Internet dan hosting', 'tags' => ['tagihan'], 'day' => 12],
            ['type' => TransactionType::Income, 'account' => 'ewallet', 'category' => 'lainnya', 'amount' => '150000.00', 'note' => 'Cashback e-wallet', 'tags' => [], 'day' => 14],
            ['type' => TransactionType::Expense, 'account' => 'cash', 'category' => 'makan', 'amount' => '275000.00', 'note' => 'Makan malam keluarga', 'tags' => ['keluarga'], 'day' => 16],
            ['type' => TransactionType::Expense, 'account' => 'bank', 'category' => 'kesehatan', 'amount' => '185000.00', 'note' => 'Check-up dokter', 'tags' => [], 'day' => 18],
            ['type' => TransactionType::Expense, 'account' => 'card', 'category' => 'belanja', 'amount' => '299000.00', 'note' => 'Baju dan sepatu', 'tags' => [], 'day' => 20],
            ['type' => TransactionType::Income, 'account' => 'bank', 'category' => 'lainnya', 'amount' => '1250000.00', 'note' => 'Proyek desain freelance', 'tags' => ['sampingan'], 'day' => 22],
            ['type' => TransactionType::Expense, 'account' => 'bank', 'category' => 'lainnya', 'amount' => '750000.00', 'note' => 'Donasi', 'tags' => [], 'day' => 24],
            ['type' => TransactionType::Expense, 'account' => 'ewallet', 'category' => 'hiburan', 'amount' => '119000.00', 'note' => 'Langganan musik', 'tags' => ['langganan'], 'day' => 26],
        ];

        $transfers = [
            ['from' => 'bank', 'to' => 'cash', 'amount' => '500000.00', 'note' => 'Tarik tunai', 'day' => 7],
            ['from' => 'bank', 'to' => 'ewallet', 'amount' => '1000000.00', 'note' => 'Top up e-wallet', 'day' => 15],
            ['from' => 'bank', 'to' => 'saving', 'amount' => '1000000.00', 'note' => 'Setor tabungan', 'day' => 20],
            // Transfer ke kartu kredit berarti membayar tagihannya: saldo kartu
            // (yang negatif = utang) naik mendekati nol. Nominalnya 1.000.000
            // supaya dari 1.128.000 belanja kartu tersisa 128.000 outstanding —
            // angka yang membuat kartu terlihat benar-benar punya utang, bukan
            // saldo lebih.
            ['from' => 'bank', 'to' => 'card', 'amount' => '1000000.00', 'note' => 'Bayar tagihan kartu', 'day' => 25],
        ];

        foreach ($rows as $row) {
            $manager->create([
                'account_id' => $accounts[$row['account']]->id,
                'category_id' => $categories[$row['category']]->id,
                'type' => $row['type'],
                'amount' => $row['amount'],
                'note' => $row['note'],
                'occurred_at' => $month->copy()->addDays($row['day'] - 1)->toDateString(),
            ], $user->id, $this->tagIds($tags, $row['tags']));
        }

        foreach ($transfers as $transfer) {
            $manager->create([
                'account_id' => $accounts[$transfer['from']]->id,
                'transfer_to_account_id' => $accounts[$transfer['to']]->id,
                'type' => TransactionType::Transfer,
                'amount' => $transfer['amount'],
                'note' => $transfer['note'],
                'occurred_at' => $month->copy()->addDays($transfer['day'] - 1)->toDateString(),
            ], $user->id);
        }

        RecurringRule::create([
            'account_id' => $accounts['bank']->id,
            'category_id' => $categories['tagihan']->id,
            'type' => TransactionType::Expense,
            'amount' => '2500000.00',
            'tag_ids' => $this->tagIds($tags, ['tagihan']),
            'note' => 'Sewa apartemen',
            'frequency' => RecurringFrequency::Monthly,
            'next_run_at' => $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(8, 0),
            'requires_confirmation' => true,
            'is_active' => true,
        ]);

        $this->command->info(sprintf(
            'Seed selesai untuk workspace #%d (%s): %d akun, %d kategori, %d tag, %d transaksi, %d aturan berulang.',
            $workspace->id,
            $workspace->name,
            count($accounts),
            count($categories),
            count($tags),
            Transaction::query()->count(),
            RecurringRule::query()->count(),
        ));
    }

    /**
     * @return array<string, Account>
     */
    private function accounts(Workspace $workspace): array
    {
        return [
            'cash' => Account::factory()->forWorkspace($workspace)->cash('Dompet Tunai', '450000.00')->create(),
            'bank' => Account::factory()->forWorkspace($workspace)->bank('BCA Tabungan', '12500000.00')->create(),
            'ewallet' => Account::factory()->forWorkspace($workspace)->ewallet('GoPay', '650000.00')->create(),
            'saving' => Account::factory()->forWorkspace($workspace)->saving('Tabungan', '3000000.00')->create(),
            'card' => Account::factory()->forWorkspace($workspace)->creditCard('BCA Credit Card', '15000000.00')->create(),
        ];
    }

    /**
     * @return array<string, Category>
     */
    private function categories(Workspace $workspace): array
    {
        $definitions = [
            'gaji' => ['Gaji', CategoryIcon::Salary],
            'makan' => ['Makan dan Minum', CategoryIcon::Food],
            'transport' => ['Transportasi', CategoryIcon::Transport],
            'belanja' => ['Belanja', CategoryIcon::Shopping],
            'tagihan' => ['Tagihan', CategoryIcon::Utilities],
            'hiburan' => ['Hiburan', CategoryIcon::Entertainment],
            'kesehatan' => ['Kesehatan', CategoryIcon::Healthcare],
            'lainnya' => ['Lainnya', CategoryIcon::Other],
        ];

        $categories = [];

        foreach ($definitions as $key => [$name, $icon]) {
            $categories[$key] = Category::factory()->forWorkspace($workspace)->create([
                'name' => $name,
                'icon' => $icon->value,
                'color' => '#2563eb',
            ]);
        }

        return $categories;
    }

    /**
     * @return array<string, Tag>
     */
    private function tags(Workspace $workspace): array
    {
        $tags = [];

        foreach (['rutin', 'tagihan', 'langganan', 'keluarga', 'bulanan', 'sampingan'] as $name) {
            $tags[$name] = Tag::factory()->forWorkspace($workspace)->create(['name' => ucfirst($name)]);
        }

        return $tags;
    }

    /**
     * @param  array<string, Tag>  $tags
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function tagIds(array $tags, array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            if (isset($tags[$name])) {
                $ids[] = $tags[$name]->id;
            }
        }

        return $ids;
    }
}
