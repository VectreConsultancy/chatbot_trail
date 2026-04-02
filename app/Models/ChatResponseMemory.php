<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatResponseMemory extends Model
{
    protected $fillable = [
        'question',
        'normalized_question',
        'answer',
        'helpful',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
