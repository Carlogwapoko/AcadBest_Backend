<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'user_id', 'school_id', 'enrollment_application_id', 'program', 'year_level', 'section', 'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollmentApplication(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    public function assignSchoolId(): void
    {
        if ($this->school_id !== null) {
            return;
        }
        if ($this->id > 999999) {
            throw new \RuntimeException('A 10-digit school ID cannot be generated for this student record.');
        }

        $this->update([
            'school_id' => sprintf('%04d%06d', (int) now()->format('Y'), $this->id),
        ]);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class)->withTimestamps();
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
