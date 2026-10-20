<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name', 'units', 'days', 'meeting_time', 'start_time', 'end_time',
        'room', 'program', 'year_level', 'grade_weights', 'component_weights', 'faculty_id',
    ];

    protected function casts(): array
    {
        return ['grade_weights' => 'array', 'component_weights' => 'array', 'units' => 'integer'];
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withTimestamps();
    }
}
