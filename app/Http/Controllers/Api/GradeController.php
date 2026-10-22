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
            'grades.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'grades.*.score' => ['required', 'numeric', 'between:0,100'],
        ]);
        $subject = Subject::findOrFail($data['subject_code']);
        $actor = $request->user();
        if ($actor->role?->slug === 'faculty' && $subject->faculty_id !== $actor->id) {
            return response()->json(['message' => 'You may only enter grades for your assigned subjects.'], 403);
        }

        $touched = [];
        DB::transaction(function () use ($data, $subject, &$touched) {
            foreach ($data['grades'] as $entry) {
                if (! $subject->students()->whereKey($entry['student_id'])->exists()) {
                    throw ValidationException::withMessages(['grades' => ['Every student must be enrolled in this subject.']]);
                }

                $values = ['score' => $entry['score']];

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
