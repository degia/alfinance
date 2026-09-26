<?php

namespace App\Models\Scopes;

use App\Support\Workspace\ActiveWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope yang memfilter setiap query ke workspace aktif.
 *
 * Prinsip: kalau workspace aktif tidak diketahui, hasil query harus kosong
 * (bukan "semua data") supaya tidak pernah ada kebocoran data antar workspace —
 * termasuk di jalur web, console, dan job. Ini juga membuat `workspace
 * switching` aman: data workspace lain tidak pernah ikut terbawa.
 *
 * Kode lintas workspace (queue agregat, export, backup) wajib opted-out secara
 * eksplisit lewat {@see ActiveWorkspace::withoutScope()}, lalu melakukan filter
 * workspace manual. Tidak ada jalur pembuka otomatis.
 *
 * @implements Scope<Model>
 */
class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (ActiveWorkspace::isUnscoped()) {
            return;
        }

        $column = $model->qualifyColumn('workspace_id');

        if (ActiveWorkspace::has()) {
            $builder->where($column, ActiveWorkspace::id());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
