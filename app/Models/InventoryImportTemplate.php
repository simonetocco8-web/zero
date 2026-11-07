<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryImportTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
