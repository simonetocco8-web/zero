<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FakeStoreProduct extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'revision', 'payload', 'published'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'published' => 'boolean', 'revision' => 'integer'];
    }
}
