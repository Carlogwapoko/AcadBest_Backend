<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'subject_code' => ['required', 'exists:subjects,code'],
            'term' => ['required', Rule::in(['Prelim', 'Midterm', 'Final'])],
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

        DB::transaction(function () use ($data, $subject) {
            foreach ($data['grades'] as $entry) {
                $componentKeys = ['written_works', 'performance_tasks', 'exams'];
                $componentCount = collect($componentKeys)->filter(
                    fn ($key) => isset($entry[$key])
                )->count();
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
                $gradeValues = [];
                if ($componentCount === count($componentKeys)) {
                    $weights = $subject->component_weights ?? [30, 50, 20];
                    $gradeValues = [
                        'written_works' => $entry['written_works'],
                        'performance_tasks' => $entry['performance_tasks'],
                        'exams' => $entry['exams'],
                        'score' => round(
                            ($entry['written_works'] * $weights[0]
                                + $entry['performance_tasks'] * $weights[1]
                                + $entry['exams'] * $weights[2]) / 100,
                            2
                        ),
                    ];
                } else {
                    $gradeValues = [
                        'score' => $entry['score'],
                        'written_works' => null,
                        'performance_tasks' => null,
                        'exams' => null,
                    ];
                }
                Grade::updateOrCreate(
                    ['student_id' => $entry['student_id'], 'subject_code' => $subject->code, 'term' => $data['term']],
                    $gradeValues
                );
                DB::table('portal_notifications')->insert([
                    'student_id' => $entry['student_id'], 'type' => 'grade',
                    'title' => 'New grade posted',
                    'message' => $subject->code.' '.$data['term'].': '.$gradeValues['score'].'.',
                    'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $grades = Grade::where('student_id', $entry['student_id'])
                    ->where('subject_code', $subject->code)->get()->keyBy('term');
                $weights = $subject->grade_weights ?? [30, 30, 40];
                $weightedTotal = 0;
                $weightTotal = 0;
                foreach (['Prelim', 'Midterm', 'Final'] as $index => $term) {
                    $score = $grades->get($term)?->score;
                    if ($score !== null) {
                        $weightedTotal += $score * $weights[$index];
                        $weightTotal += $weights[$index];
                    }
                }
                $thresholds = DB::table('portal_settings')->where('key', 'standing_rules')->value('value');
                $passing = $thresholds ? (json_decode($thresholds, true)['pass'] ?? 75) : 75;
                if ($weightTotal && $weightedTotal / $weightTotal < $passing) {
                    DB::table('portal_notifications')->insert([
                        'student_id' => $entry['student_id'], 'type' => 'alert',
                        'title' => 'Grade alert',
                        'message' => 'Your current weighted average in '.$subject->code.' is '
                            .number_format($weightedTotal / $weightTotal, 1).'. Please consult your instructor.',
                        'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        return response()->noContent();
    }
}
