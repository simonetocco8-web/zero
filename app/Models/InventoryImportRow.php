<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cells' => 'array', 'payload' => 'array', 'errors' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
