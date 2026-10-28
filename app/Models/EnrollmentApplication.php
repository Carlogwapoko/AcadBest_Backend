<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnrollmentApplication extends Model
{
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    protected $fillable = [
        'reference', 'name', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'date_of_birth', 'sex', 'address',
        'program', 'major', 'year_level', 'session', 'semester', 'academic_year', 'application_type', 'previous_school',
        'guardian_name', 'guardian_phone', 'mother_first_name', 'mother_middle_name', 'mother_last_name',
        'father_first_name', 'father_middle_name', 'father_last_name', 'status', 'student_status', 'reviewer_note',
        'account_issued', 'requested_subjects', 'history', 'user_id', 'student_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'account_issued' => 'boolean',
            'requested_subjects' => 'array',
            'history' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'enrollment_subject')
            ->withTimestamps();
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
