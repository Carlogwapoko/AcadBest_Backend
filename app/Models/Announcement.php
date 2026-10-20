<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $fillable = [
        'title', 'message', 'priority', 'publish_date', 'valid_until',
        'program', 'year_level', 'section', 'author_id',
    ];

    protected function casts(): array
    {
        return ['publish_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
