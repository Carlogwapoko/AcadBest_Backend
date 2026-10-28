<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
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
            'term' => ['sometimes', 'required', Rule::in(StandingService::TERMS)],
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'grades.*.enrollment_application_id' => ['sometimes', 'required', 'integer', 'exists:enrollment_applications,id'],
            'grades.*.score' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'grades.*.scores' => ['sometimes', 'required', 'array:Prelim,Midterm,Final', 'min:1'],
            'grades.*.scores.Prelim' => ['sometimes', 'numeric', 'between:0,100'],
            'grades.*.scores.Midterm' => ['sometimes', 'numeric', 'between:0,100'],
            'grades.*.scores.Final' => ['sometimes', 'numeric', 'between:0,100'],
        ]);
        foreach ($data['grades'] as $entry) {
            if (isset($data['term']) && (isset($entry['scores']) || ! array_key_exists('score', $entry))) {
                throw ValidationException::withMessages([
                    'grades' => ['Provide a score for the selected grading period.'],
                ]);
            }
            if (! isset($data['term']) && (isset($entry['score']) || empty($entry['scores']))) {
                throw ValidationException::withMessages([
                    'grades' => ['Provide at least one grading-period score for each student.'],
                ]);
            }
        }
        $subject = Subject::findOrFail($data['subject_code']);
        $actor = $request->user();

        $touched = [];
        DB::transaction(function () use ($actor, $data, $subject, &$touched) {
            foreach ($data['grades'] as $entry) {
                $applicationQuery = EnrollmentApplication::query()
                    ->where('status', 'Enrolled')
                    ->where('student_id', $entry['student_id'])
                    ->where(function ($query) use ($subject): void {
                        $query->whereHas('subjects', fn ($subjects) => $subjects->where('subjects.code', $subject->code))
                            ->orWhereHas('student.subjects', fn ($subjects) => $subjects->where('subjects.code', $subject->code));
                    })
                    ->with(['student.subjects', 'subjects']);
                if (isset($entry['enrollment_application_id'])) {
                    $applicationQuery->whereKey($entry['enrollment_application_id']);
                }
                $application = $applicationQuery->latest('id')->get()
                    ->first(fn (EnrollmentApplication $candidate): bool => $this->applicationHasSubject($candidate, $subject));
                if (! $application || ! $application->student
                    || in_array(strtolower((string) $application->student_status), [
                        'applicant', 'pending', 'rejected', 'withdrawn', 'not enrolled',
                    ], true)
                    || in_array(strtolower((string) $application->student->status), [
                        'applicant', 'pending', 'rejected', 'withdrawn', 'not enrolled',
                    ], true)) {
                    throw ValidationException::withMessages([
                        'grades' => ['Every student must be officially enrolled in this subject for the selected term.'],
                    ]);
                }

                if ($actor->role?->slug === 'faculty') {
                    $assigned = DB::table('faculty_subject')
                        ->where('faculty_id', $actor->id)
                        ->where('subject_code', $subject->code)
                        ->where('semester', $application->semester)
                        ->where('academic_year', $application->academic_year)
                        ->whereIn('session', [$application->session ?: 'Afternoon', 'All'])
                        ->where(function ($query) use ($application): void {
                            $query->where('section', 'All')
                                ->orWhere('section', $application->student->section);
                        })
                        ->exists();
                    if (! $assigned) {
                        throw ValidationException::withMessages([
                            'subject_code' => ['You may only enter grades for your assigned subject, section, semester, and academic year.'],
                        ]);
                    }
                }

                $scores = isset($data['term'])
                    ? [$data['term'] => $entry['score']]
                    : $entry['scores'];
                foreach ($scores as $term => $score) {
                    $values = ['score' => $score];
                    $grade = Grade::updateOrCreate(
                        [
                            'student_id' => $entry['student_id'],
                            'subject_code' => $subject->code,
                            'term' => $term,
                            'enrollment_application_id' => $application->id,
                        ],
                        $values
                    );

                    if ($grade->wasRecentlyCreated || $grade->wasChanged('score')) {
                        DB::table('portal_notifications')->insert([
                            'student_id' => $entry['student_id'], 'type' => 'grade',
                            'title' => 'New grade posted',
                            'message' => $subject->code.' '.$term.': '.$values['score'].'.',
                            'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
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

    private function applicationHasSubject(EnrollmentApplication $application, Subject $subject): bool
    {
        $matchesScope = fn (Subject $assigned): bool => $assigned->code === $subject->code
            && ($assigned->program === 'All' || $assigned->program === $application->program)
            && ($assigned->major === null || $assigned->major === $application->major)
            && $this->matchesEnrollmentSession($assigned, $application)
            && ($assigned->semester === null || $assigned->semester === $application->semester)
            && ($assigned->academic_year === null || $assigned->academic_year === $application->academic_year);

        return $application->subjects->contains($matchesScope)
            || ($application->student?->subjects->contains($matchesScope) ?? false);
    }

    private function matchesEnrollmentSession(Subject $subject, EnrollmentApplication $application): bool
    {
        if (! $application->session || $subject->session === 'All' || $subject->session === $application->session) {
            return true;
        }

        return DB::table('faculty_subject')
            ->where('subject_code', $subject->code)
            ->where('semester', $application->semester)
            ->where('academic_year', $application->academic_year)
            ->whereIn('session', [$application->session, 'All'])
            ->where(fn ($query) => $query->where('section', 'All')
                ->orWhere('section', $application->student?->section ?? ''))
            ->exists();
    }
}
