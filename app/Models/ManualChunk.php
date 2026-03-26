<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualChunk extends Model
{
    protected $fillable = [
        'source_path',
        'source_hash',
        'chunk_order',
        'content',
    ];
}
