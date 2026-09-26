<?php

namespace App\Models;

use App\Enums\CategoryIcon;
use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori pengeluaran/pemasukan, dua level (PRD.md §3.4).
 *
 * Kategori induk punya `parent_id` null; sub-kategori menunjuk induknya.
 * `icon` dan `color` dipakai ulang di badge, chart, dan budget matrix.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $parent_id
 * @property string $name
 * @property CategoryIcon $icon
 * @property string $color
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['parent_id', 'name', 'icon', 'color'])]
class Category extends WorkspaceScopedModel
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * Hanya kategori induk.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'icon' => CategoryIcon::class,
            'parent_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }
}
