<?php

namespace App\Http\Controllers;

use App\Enums\BackupStatus;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Http\Controllers\Concerns\AuthorizesWorkspaceData;
use App\Http\Requests\ReportFilterRequest;
use App\Http\Requests\StoreExportRequest;
use App\Jobs\ProcessExportJob;
use App\Models\Account;
use App\Models\Backup;
use App\Models\Category;
use App\Models\ExportJob;
use App\Models\Tag;
use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Halaman Ekspor & Backup (PRD.md §3.10–3.11).
 *
 * Satu halaman menampilkan dua daftar: riwayat ekspor dan riwayat backup.
 * Keduanya diproses di antrean; halaman hanya membuat permintaan lalu me-refresh
 * daftar status (download disediakan ketika statusnya `done`).
 */
class ExportController extends Controller
{
    use AuthorizesWorkspaceData;

    public function index(): Response
    {
        $this->authorizeViewData();

        $workspaceId = ActiveWorkspace::idOrFail();

        return Inertia::render('exports/Index', [
            'exports' => ExportJob::query()
                ->where('workspace_id', $workspaceId)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ExportJob $job): array => $this->presentExport($job))
                ->all(),
            'backups' => Backup::query()
                ->where('workspace_id', $workspaceId)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Backup $backup): array => $this->presentBackup($backup))
                ->all(),
            'export_options' => array_map(
                static fn (ExportType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'is_pdf' => $type->isPdf(),
                ],
                ExportType::cases(),
            ),
            'options' => [
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
                'tags' => Tag::allWorkspaces()
                    ->where('workspace_id', $workspaceId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Tag $tag): array => [
                        'id' => (int) $tag->id,
                        'name' => $tag->name,
                    ])
                    ->all(),
            ],
            'max_months' => ReportFilterRequest::MAX_MONTHS,
        ]);
    }

    public function store(StoreExportRequest $request): RedirectResponse
    {
        $this->authorizeEditData();

        $validated = $request->validated();
        $type = ExportType::from($validated['type']);
        $filters = $request->filters();

        $job = new ExportJob([
            'workspace_id' => ActiveWorkspace::idOrFail(),
            'user_id' => $request->user()?->id,
            'type' => $type,
            'status' => ExportStatus::Queued,
            'filters' => $filters === [] ? null : $filters,
        ]);

        $job->save();

        ProcessExportJob::dispatch($job->id)->delay(now()->addSeconds(1));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ekspor :label sedang diproses di antrean.', ['label' => $type->label()]),
        ]);

        return back();
    }

    /**
     * Unduh berkas ekspor yang sudah selesai.
     */
    public function download(Request $request, ExportJob $exportJob): StreamedResponse
    {
        $this->authorizeViewData();

        abort_unless($exportJob->workspace_id === ActiveWorkspace::idOrFail(), 404);

        if ($exportJob->status !== ExportStatus::Done || $exportJob->file_path === null) {
            throw new NotFoundHttpException('Ekspor belum siap diunduh.');
        }

        return Storage::disk('exports')->download($exportJob->file_path, $exportJob->file_name);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExport(ExportJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type->value,
            'label' => $job->type->label(),
            'is_pdf' => $job->type->isPdf(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'file_name' => $job->file_name,
            'size_bytes' => $job->size_bytes,
            'error' => $job->error,
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBackup(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'scope' => $backup->scope->value,
            'scope_label' => $backup->scope->label(),
            'from' => $backup->from?->format('Y-m-d'),
            'to' => $backup->to?->format('Y-m-d'),
            'status' => $backup->status->value,
            'status_label' => $backup->status->label(),
            'file_name' => $backup->file_name,
            'size_bytes' => $backup->size_bytes,
            'error' => $backup->error,
            'summary' => $backup->summary,
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'created_at' => $backup->created_at?->toIso8601String(),
            'can_restore' => $backup->status === BackupStatus::Done,
        ];
    }
}
