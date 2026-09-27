<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Enums\WorkspaceRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lampiran bukti: privat per tenant, tervalidasi, dan ikut terhapus bersama
 * transaksinya supaya tidak ada file yatim.
 */
class TransactionAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_attachment_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash()->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('bukti-struk.png', 80, 80),
        ])->assertRedirect(route('transactions.index'));

        $transaction = Transaction::allWorkspaces()->sole();
        $attachment = Attachment::allWorkspaces()->sole();

        $this->assertSame($transaction->id, $attachment->transaction_id);
        $this->assertSame($workspace->id, $attachment->workspace_id);
        $this->assertSame('bukti-struk.png', $attachment->original_name);

        // Nama berkas di disk tidak boleh memakai nama kiriman client.
        $expectedPath = Attachment::storagePathFor($workspace->id, $transaction->id, basename($attachment->file_path));

        $this->assertSame($expectedPath, $attachment->file_path);
        $this->assertStringStartsWith("workspaces/{$workspace->id}/transactions/{$transaction->id}/", $attachment->file_path);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_the_list_exposes_a_download_link(): void
    {
        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('bukti.jpg'),
        ]);

        $this->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('transactions.0.attachments', 1)
                ->where('transactions.0.attachments.0.original_name', 'bukti.jpg'));
    }

    public function test_the_owner_can_download_the_file(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('bukti.jpg'),
        ]);

        $attachment = Attachment::allWorkspaces()->sole();

        $this->get(route('transactions.attachments.download', $attachment))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=bukti.jpg');
    }

    public function test_a_viewer_can_download_but_not_upload(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, WorkspaceRole::Owner);

        $viewer = User::factory()->create();
        $workspace->addUser($viewer, WorkspaceRole::Viewer);

        $account = Account::factory()->forWorkspace($workspace)->cash()->create();
        $transaction = Transaction::factory()->from($account)->create();
        $attachment = Attachment::factory()
            ->forTransaction($transaction)
            ->withRealFile()
            ->create();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->get(route('transactions.attachments.download', $attachment))
            ->assertOk();

        $this->actingAs($viewer)
            ->withSession(['workspace_id' => $workspace->id])
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '10000.00',
                'occurred_at' => '2026-09-27',
                'attachment' => UploadedFile::fake()->image('bukti.jpg'),
            ])
            ->assertForbidden();

        // Tetap satu transaksi & satu lampiran: yang dibuat di awal, tidak ada
        // baru dari viewer.
        $this->assertSame(1, Transaction::allWorkspaces()->count());
        $this->assertSame(1, Attachment::allWorkspaces()->count());
    }

    public function test_attachments_from_another_workspace_are_not_reachable(): void
    {
        Storage::fake('local');

        $this->signedIn();

        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $foreignOwner->id]);
        $foreignAccount = Account::factory()->forWorkspace($foreignWorkspace)->cash()->create();
        $foreignTransaction = Transaction::factory()->from($foreignAccount)->create();
        $foreignAttachment = Attachment::factory()
            ->forTransaction($foreignTransaction)
            ->withRealFile()
            ->create();

        $this->get(route('transactions.attachments.download', $foreignAttachment))
            ->assertNotFound();
    }

    public function test_guests_cannot_download_attachments(): void
    {
        Storage::fake('local');

        $attachment = Attachment::factory()->withRealFile()->create();

        $this->get(route('transactions.attachments.download', $attachment))
            ->assertRedirect(route('login'));
    }

    public function test_unsupported_file_types_are_rejected(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '75000.00',
                'occurred_at' => '2026-09-27',
                'attachment' => UploadedFile::fake()->create('program.exe', 10, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_attachments_larger_than_two_megabytes_are_rejected(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'account_id' => $account->id,
                'type' => TransactionType::Expense->value,
                'amount' => '75000.00',
                'occurred_at' => '2026-09-27',
                'attachment' => UploadedFile::fake()->create('besar.pdf', 3000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, Transaction::allWorkspaces()->count());
    }

    public function test_uploading_a_new_file_replaces_the_old_one_on_disk(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('lama.png'),
        ]);

        $old = Attachment::allWorkspaces()->sole();

        $transaction = Transaction::allWorkspaces()->sole();

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('baru.png'),
        ]);

        $new = Attachment::allWorkspaces()->sole();

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('baru.png', $new->original_name);

        // Berkas lama harus hilang dari disk, bukan cuma dari database.
        Storage::disk('local')->assertMissing($old->file_path);
        Storage::disk('local')->assertExists($new->file_path);
    }

    public function test_an_attachment_can_be_removed_without_uploading_a_new_one(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('lama.png'),
        ]);

        $old = Attachment::allWorkspaces()->sole();
        $transaction = Transaction::allWorkspaces()->sole();

        $this->put(route('transactions.update', $transaction), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '75000.00',
            'occurred_at' => '2026-09-27',
            'remove_attachment' => '1',
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Attachment::allWorkspaces()->count());
        Storage::disk('local')->assertMissing($old->file_path);
    }

    public function test_deleting_a_transaction_removes_its_files(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $workspace = $user->workspaces()->sole();
        $account = Account::factory()->forWorkspace($workspace)->cash('Dompet', '100000.00')->create();

        $this->post(route('transactions.store'), [
            'account_id' => $account->id,
            'type' => TransactionType::Expense->value,
            'amount' => '50000.00',
            'occurred_at' => '2026-09-27',
            'attachment' => UploadedFile::fake()->image('struk.png'),
        ]);

        $attachment = Attachment::allWorkspaces()->sole();
        $transaction = Transaction::allWorkspaces()->sole();

        $this->delete(route('transactions.destroy', $transaction));

        $this->assertSame(0, Attachment::allWorkspaces()->count());
        Storage::disk('local')->assertMissing($attachment->file_path);
        $this->assertSame('100000.00', $account->fresh()->cached_balance);
    }

    public function test_a_missing_file_returns_not_found_instead_of_a_broken_stream(): void
    {
        Storage::fake('local');

        $user = $this->signedIn();
        $account = Account::factory()->forWorkspace($user->workspaces()->sole())->cash()->create();
        $transaction = Transaction::factory()->from($account)->create();

        $attachment = Attachment::factory()
            ->forTransaction($transaction)
            ->withRealFile()
            ->create();

        Storage::disk('local')->delete($attachment->file_path);

        $this->get(route('transactions.attachments.download', $attachment))
            ->assertNotFound();
    }

    private function signedIn(): User
    {
        $user = User::factory()->withWorkspace('Rumah Tangga', WorkspaceRole::Owner)->create();

        $this->actingAs($user)
            ->withSession(['workspace_id' => $user->workspaces()->sole()->id]);

        return $user;
    }
}
