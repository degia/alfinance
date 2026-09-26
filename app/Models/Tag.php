<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Tag bebas untuk melabeli transaksi (PRD.md §3.4).
 *
 * Many-to-many ke transaksi dibangun di Fase 3 lewat tabel `transaction_tag`.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['name'])]
class Tag extends WorkspaceScopedModel
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * Pencarian untuk autocomplete di form transaksi.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where('name', 'like', '%'.$term.'%')->orderBy('name');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
        ];
    }
}
