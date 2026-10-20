<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        'student_id', 'subject_code', 'term', 'score',
        'written_works', 'performance_tasks', 'exams',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'written_works' => 'float',
            'performance_tasks' => 'float',
            'exams' => 'float',
        ];
    }
}
