<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'grade_weights' => '[]',
    ];

    protected $fillable = [
        'code', 'name', 'units', 'days', 'meeting_time', 'start_time', 'end_time',
        'room', 'program', 'major', 'year_level', 'session', 'semester', 'academic_year',
    ];

    protected function casts(): array
    {
        return ['units' => 'integer', 'grade_weights' => 'array'];
    }

    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'faculty_subject', 'subject_code', 'faculty_id', 'code')
            ->withTimestamps();
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)->withTimestamps();
    }
}
