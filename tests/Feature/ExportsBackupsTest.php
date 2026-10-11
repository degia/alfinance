<?php

namespace Tests\Feature;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ExportJob;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Alur Ekspor & Backup (PRD.md §3.10–3.11): permintaan dari halaman,
 * pembuatan berkas oleh job antrean, lalu unduh / pratinjau / pemulihan.
 */
class ExportsBackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_exports_backups_page_renders(): void
    {
        $this->signedIn();

        $this->get(route('exports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('exports/Index')
                ->has('exports')
                ->has('backups')
                ->has('export_options')
                ->has('options.accounts')
                ->has('options.categories')
                ->has('options.tags')
                ->has('max_months'));
    }

    public function test_transaction_export_is_queued_and_built(): void
    {
        Storage::fake('exports');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->count(3)->from($account)->create();

        $this->post(route('exports.store'), [
            'type' => ExportType::TransactionsCsv->value,
        ])->assertRedirect();

        $job = ExportJob::query()->sole();

        $this->assertSame(ExportStatus::Done, $job->status, (string) $job->error);
        $this->assertNotNull($job->file_path);
        Storage::disk('exports')->assertExists($job->file_path);

        $this->get(route('exports.download', $job))->assertOk();
    }

    public function test_report_pdf_export_is_built(): void
    {
        Storage::fake('exports');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->count(2)->from($account)->income()->create();

        $this->post(route('exports.store'), [
            'type' => ExportType::CashFlowPdf->value,
        ])->assertRedirect();

        $job = ExportJob::query()->sole();

        $this->assertSame(ExportStatus::Done, $job->status, (string) $job->error);
        Storage::disk('exports')->assertExists((string) $job->file_path);
    }

    public function test_report_excel_export_is_built(): void
    {
        Storage::fake('exports');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $category = Category::factory()->forWorkspace($workspace)->create();
        Budget::factory()->forCategory($category)->limit('1000000.00')->create();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->from($account)->expense()->create([
            'category_id' => $category->id,
        ]);

        $this->post(route('exports.store'), [
            'type' => ExportType::BudgetExcel->value,
        ])->assertRedirect();

        $job = ExportJob::query()->sole();

        $this->assertSame(ExportStatus::Done, $job->status, (string) $job->error);
        Storage::disk('exports')->assertExists((string) $job->file_path);
    }

    public function test_backup_generates_file_and_is_downloadable(): void
    {
        Storage::fake('backups');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->count(2)->from($account)->create();

        $this->post(route('backups.store'), [
            'scope' => BackupScope::Full->value,
        ])->assertRedirect();

        $backup = Backup::query()->sole();

        $this->assertSame(BackupStatus::Done, $backup->status, (string) $backup->error);
        Storage::disk('backups')->assertExists((string) $backup->file_path);

        $this->get(route('backups.download', $backup))->assertOk();
    }

    public function test_backup_can_be_previewed_and_restored(): void
    {
        Storage::fake('backups');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->count(2)->from($account)->create();

        $this->post(route('backups.store'), ['scope' => BackupScope::Full->value]);

        $backup = Backup::query()->sole();
        $this->assertSame(BackupStatus::Done, $backup->status, (string) $backup->error);

        // Hapus seluruh transaksi langsung di tabel (melewati global scope),
        // lalu pastikan backup memulihkannya lewat jalur HTTP.
        DB::table('transactions')->delete();
        $this->assertSame(0, DB::table('transactions')->count());

        $this->post(route('backups.preview', $backup))->assertRedirect();
        $this->post(route('backups.restore', $backup))->assertRedirect();

        $this->assertSame(2, DB::table('transactions')->count());
        $this->assertDatabaseHas('transactions', ['workspace_id' => $workspace->id]);
    }

    public function test_range_backup_only_captures_transactions_in_range(): void
    {
        Storage::fake('backups');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        Transaction::factory()->from($account)->on('2026-01-10')->create();
        Transaction::factory()->from($account)->on('2026-05-10')->create();

        $this->post(route('backups.store'), [
            'scope' => BackupScope::Range->value,
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ])->assertRedirect();

        $backup = Backup::query()->sole();

        $this->assertSame(BackupStatus::Done, $backup->status, (string) $backup->error);
        $this->assertSame(1, $backup->summary['transactions'] ?? null);
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
