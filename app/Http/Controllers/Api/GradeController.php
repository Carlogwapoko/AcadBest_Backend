<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Services\StandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    public function storeBatch(Request $request, StandingService $standing)
    {
        $data = $request->validate([
            'subject_code' => ['required', 'exists:subjects,code'],
            'term' => ['required', Rule::in(StandingService::TERMS)],
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'grades.*.score' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'grades.*.written_works' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'grades.*.performance_tasks' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'grades.*.exams' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
        ]);
        $subject = Subject::findOrFail($data['subject_code']);
        $actor = $request->user();
        if ($actor->role?->slug === 'faculty' && $subject->faculty_id !== $actor->id) {
            return response()->json(['message' => 'You may only enter grades for your assigned subjects.'], 403);
        }

        $touched = [];
        DB::transaction(function () use ($data, $subject, &$touched) {
            $componentKeys = ['written_works', 'performance_tasks', 'exams'];
            foreach ($data['grades'] as $entry) {
                $componentCount = collect($componentKeys)->filter(fn ($k) => isset($entry[$k]))->count();
                if ($componentCount !== 0 && $componentCount !== count($componentKeys)) {
                    throw ValidationException::withMessages([
                        'grades' => ['Enter a score for Written Works, Performance Tasks, and Exams.'],
                    ]);
                }
                if ($componentCount === 0 && ! isset($entry['score'])) {
                    throw ValidationException::withMessages([
                        'grades' => ['Enter component scores or a legacy period score.'],
                    ]);
                }
                if (! $subject->students()->whereKey($entry['student_id'])->exists()) {
                    throw ValidationException::withMessages(['grades' => ['Every student must be enrolled in this subject.']]);
                }

                if ($componentCount === count($componentKeys)) {
                    $w = $subject->component_weights ?? [30, 50, 20];
                    $values = [
                        'written_works' => $entry['written_works'],
                        'performance_tasks' => $entry['performance_tasks'],
                        'exams' => $entry['exams'],
                        'score' => round(($entry['written_works'] * $w[0]
                            + $entry['performance_tasks'] * $w[1]
                            + $entry['exams'] * $w[2]) / 100, 2),
                    ];
                } else {
                    $values = [
                        'score' => $entry['score'],
                        'written_works' => null, 'performance_tasks' => null, 'exams' => null,
                    ];
                }

                $grade = Grade::updateOrCreate(
                    ['student_id' => $entry['student_id'], 'subject_code' => $subject->code, 'term' => $data['term']],
                    $values
                );

                // Notify only when the grade is new or really changed.
                if ($grade->wasRecentlyCreated || $grade->wasChanged('score')) {
                    DB::table('portal_notifications')->insert([
                        'student_id' => $entry['student_id'], 'type' => 'grade',
                        'title' => 'New grade posted',
                        'message' => $subject->code.' '.$data['term'].': '.$values['score'].'.',
                        'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $touched[$entry['student_id']] = true;
            }
        });

        // Grades are saved first, then the standing check runs (as described in the paper).
        $updatedStandings = [];
        foreach (array_keys($touched) as $studentId) {
            $result = $standing->refresh(Student::findOrFail($studentId));
            $updatedStandings[$studentId] = [
                'gwa' => $result['gwa'],
                'status' => $result['status'],
                'declining' => $result['declining'],
            ];
        }

        return response()->json(['students' => $updatedStandings]);
    }
}
