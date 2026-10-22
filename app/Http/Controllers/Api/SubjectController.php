<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function store(Request $request)
    {
        $this->normalizeCode($request);
        $this->normalizeSchedule($request);
        $data = $this->validateSubject($request);
        $subject = Subject::create($this->map($data));

        return response()->json($subject, 201);
    }

    public function update(Request $request, Subject $subject)
    {
        if ($request->user()->role?->slug === 'faculty') {
            return response()->json(['message' => 'Faculty grades are entered by grading period.'], 403);
        }

        $this->normalizeCode($request);
        $this->normalizeSchedule($request);
        $data = $this->validateSubject($request, $subject);
        $subject->update($this->map($data, $subject));

        return response()->json($subject->fresh()->toArray());
    }

    public function destroy(Subject $subject)
    {
        $assignedToApplicant = EnrollmentApplication::query()->whereJsonContains('requested_subjects', $subject->code)->exists();
        if ($subject->students()->exists() || $assignedToApplicant
            || DB::table('grades')->where('subject_code', $subject->code)->exists()) {
            return response()->json(['message' => 'Remove this subject from student loads and grades before deleting it.'], 422);
        }
        $subject->delete();

        return response()->noContent();
    }

    public function assignFaculty(Request $request, User $user)
    {
        if ($user->role?->slug !== 'faculty') {
            throw ValidationException::withMessages(['user' => ['Subjects can only be assigned to a faculty account.']]);
        }
        $data = $request->validate(['codes' => ['present', 'array'], 'codes.*' => ['string', 'distinct', 'exists:subjects,code']]);
        $subjects = Subject::whereIn('code', $data['codes'])->get();
        if ($subjects->sum('units') > 24) {
            throw ValidationException::withMessages(['codes' => ['Faculty load cannot exceed 24 units.']]);
        }
        $this->assertNoScheduleClashes($subjects);
        Subject::where('faculty_id', $user->id)->update(['faculty_id' => null]);
        Subject::whereIn('code', $data['codes'])->update(['faculty_id' => $user->id]);

        return response()->noContent();
    }

    public function assignStudent(Request $request, Student $student)
    {
        $data = $request->validate(['codes' => ['required', 'array', 'min:1'], 'codes.*' => ['string', 'distinct', 'exists:subjects,code']]);
        $subjects = Subject::whereIn('code', $data['codes'])->get();
        if ($student->session && $subjects->contains(
            fn (Subject $subject) => $subject->session !== 'All' && $subject->session !== $student->session
        )) {
            throw ValidationException::withMessages([
                'codes' => ['A student can only be assigned subjects offered in their selected session.'],
            ]);
        }
        if ($subjects->sum('units') > 27) {
            throw ValidationException::withMessages(['codes' => ['Student course load cannot exceed 27 units.']]);
        }
        $this->assertNoScheduleClashes($subjects);
        $student->subjects()->sync($data['codes']);

        return response()->noContent();
    }

    private function validateSubject(Request $request, ?Subject $subject = null): array
    {
        $required = $subject ? ['sometimes', 'required'] : ['required'];

        return $request->validate([
            'code' => [...$required, 'string', 'regex:/^[A-Z]{2,4}\d{3}$/', 'max:10',
                Rule::unique('subjects', 'code')->ignore($subject?->code, 'code')],
            'name' => [...$required, 'string', 'min:3', 'max:255'],
            'units' => [...$required, 'integer', 'min:1', 'max:6'],
            'days' => [...$required, 'string', 'max:50'],
            'start_time' => [...$required, 'date_format:H:i'],
            'end_time' => [...$required, 'date_format:H:i'],
            'room' => [...$required, 'string', 'max:50'],
            'program' => [...$required, 'string', 'max:20'],
            'year_level' => [...$required, 'string', 'max:10'],
            'session' => ['sometimes', 'required', Rule::in(['All', 'Afternoon', 'Evening', 'Weekends'])],
            'semester' => ['sometimes', 'nullable', Rule::in(['1st Semester', '2nd Semester', 'Summer'])],
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
            'faculty_id' => ['nullable', 'exists:users,id'],
        ]);
    }

    private function normalizeCode(Request $request): void
    {
        $code = $request->input('code');
        if (is_string($code)) {
            $request->merge(['code' => strtoupper(trim($code))]);
        }
    }

    private function normalizeSchedule(Request $request): void
    {
        if ($request->filled('start_time') || $request->filled('end_time')
            || ! is_string($request->input('meeting_time'))) {
            return;
        }

        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)?\s*[–-]\s*(\d{1,2}):(\d{2})\s*(AM|PM)?\s*$/i',
            $request->input('meeting_time'), $matches)) {
            $startPeriod = strtoupper($matches[3] ?: $matches[6]);
            $endPeriod = strtoupper($matches[6] ?: $matches[3]);
            $start = $this->time24((int) $matches[1], (int) $matches[2], $startPeriod);
            $end = $this->time24((int) $matches[4], (int) $matches[5], $endPeriod);
            if ($start !== null && $end !== null) {
                $request->merge(['start_time' => $start, 'end_time' => $end]);
            }
        }
    }

    private function time24(int $hour, int $minute, string $period): ?string
    {
        if ($minute > 59 || ($period !== '' && ($hour < 1 || $hour > 12)) || ($period === '' && $hour > 23)) {
            return null;
        }
        if ($period !== '') {
            $hour = ($hour % 12) + ($period === 'PM' ? 12 : 0);
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function map(array $data, ?Subject $subject = null): array
    {
        $startTime = $data['start_time'] ?? $subject?->start_time;
        $endTime = $data['end_time'] ?? $subject?->end_time;
        if ($startTime === null || $endTime === null || $endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be later than start time.'],
            ]);
        }

        $facultyId = $data['faculty_id'] ?? $subject?->faculty_id;
        if (! empty($facultyId) && User::with('role')->find($facultyId)?->role?->slug !== 'faculty') {
            throw ValidationException::withMessages(['faculty_id' => ['Assign this subject to a faculty account.']]);
        }

        return [
            'name' => $data['name'] ?? $subject?->name,
            'units' => $data['units'] ?? $subject?->units,
            'days' => $data['days'] ?? $subject?->days,
            'start_time' => $startTime, 'end_time' => $endTime,
            'meeting_time' => $startTime.'-'.$endTime, 'room' => $data['room'] ?? $subject?->room,
            'program' => $data['program'] ?? $subject?->program,
            'year_level' => (string) ($data['year_level'] ?? $subject?->year_level),
            'session' => $data['session'] ?? $subject?->session ?? 'All',
            'semester' => array_key_exists('semester', $data) ? $data['semester'] : $subject?->semester,
            'academic_year' => array_key_exists('academic_year', $data)
                ? $data['academic_year']
                : $subject?->academic_year,
            'faculty_id' => $facultyId,
        ] + (isset($data['code']) ? ['code' => strtoupper($data['code'])] : []);
    }

    private function assertNoScheduleClashes(iterable $subjects): void
    {
        $subjects = collect($subjects)->values();
        for ($i = 0; $i < $subjects->count(); $i++) {
            for ($j = $i + 1; $j < $subjects->count(); $j++) {
                $first = $subjects[$i];
                $second = $subjects[$j];
                $sharedDays = array_intersect(explode('/', $first->days), explode('/', $second->days));
                if ($first->start_time < $second->end_time && $second->start_time < $first->end_time
                    && $sharedDays !== []) {
                    throw ValidationException::withMessages([
                        'codes' => ['Schedule conflict: '.$first->code.' and '.$second->code.' overlap.'],
                    ]);
                }
            }
        }
    }
}
