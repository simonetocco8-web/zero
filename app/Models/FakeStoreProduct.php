<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FakeStoreProduct extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'revision', 'payload', 'published', 'archived'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'published' => 'boolean', 'archived' => 'boolean', 'revision' => 'integer'];
    }
}
