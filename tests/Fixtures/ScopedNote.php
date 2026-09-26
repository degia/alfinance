<?php

namespace Tests\Fixtures;

use App\Models\WorkspaceScopedModel;

/**
 * Model tenant-aware minimal untuk memverifikasi global scope sebelum tabel
 * domain asli (accounts, transactions, dst.) dibuat pada fase berikutnya.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 */
class ScopedNote extends WorkspaceScopedModel
{
    /**
     * @var string
     */
    protected $table = 'scoped_notes';

    /**
     * @var list<string>
     */
    protected $fillable = ['workspace_id', 'name'];
}
