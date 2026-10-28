<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        'student_id', 'subject_code', 'term', 'score', 'enrollment_application_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
        ];
    }
}
