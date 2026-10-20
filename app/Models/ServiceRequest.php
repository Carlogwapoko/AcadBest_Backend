<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
{
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    protected $fillable = [
        'reference', 'student_id', 'type', 'purpose', 'copies', 'date_needed',
        'remarks', 'status', 'history',
    ];

    protected function casts(): array
    {
        return ['date_needed' => 'date:Y-m-d', 'history' => 'array'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
