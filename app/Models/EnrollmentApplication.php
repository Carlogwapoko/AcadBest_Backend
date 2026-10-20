<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentApplication extends Model
{
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    protected $fillable = [
        'reference', 'name', 'middle_name', 'email', 'phone', 'date_of_birth', 'sex', 'address',
        'program', 'year_level', 'session', 'application_type', 'previous_school',
        'guardian_name', 'guardian_phone', 'status', 'student_status', 'reviewer_note',
        'account_issued', 'requested_subjects', 'history', 'user_id',
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
}
