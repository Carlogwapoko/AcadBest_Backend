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
        $this->assertNoRoomClash($data);
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
        $this->assertNoRoomClash($data, $subject);
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
        $this->assertNoScheduleClashes($subjects);
        $user->subjects()->sync($data['codes']);

        return response()->noContent();
    }

    public function assignStudent(Request $request, Student $student)
    {
        $data = $request->validate(['codes' => ['required', 'array', 'min:1'], 'codes.*' => ['string', 'distinct', 'exists:subjects,code']]);
        $subjects = Subject::whereIn('code', $data['codes'])->get();
        $application = $student->enrollmentApplications()
            ->where('status', 'Enrolled')
            ->latest('id')
            ->first()
            ?? $student->enrollmentApplication()->where('status', 'Enrolled')->first();
        if (! $application) {
            throw ValidationException::withMessages([
                'student' => ['Subject loads can only be assigned to students with an enrolled application.'],
            ]);
        }
        if ($subjects->contains(fn (Subject $subject) => ! $this->matchesEnrollment($subject, $application))) {
            throw ValidationException::withMessages([
                'codes' => ['The subject load must match the student’s program, major, session, semester, and academic year.'],
            ]);
        }
        if ($subjects->sum('units') > 27) {
            throw ValidationException::withMessages(['codes' => ['Student course load cannot exceed 27 units.']]);
        }
        $this->assertNoScheduleClashes($subjects);
        $preservedCodes = $student->subjects->filter(
            fn (Subject $subject) => ! $this->matchesEnrollment($subject, $application)
        )->pluck('code')->all();
        $student->subjects()->sync(array_values(array_unique([...$data['codes'], ...$preservedCodes])));

        return response()->json(['codes' => $student->subjects()->pluck('subjects.code')->all()]);
    }

    private function matchesEnrollment(Subject $subject, EnrollmentApplication $application): bool
    {
        return ($subject->program === 'All' || $subject->program === $application->program)
            && ($subject->major === null || $subject->major === $application->major)
            && (! $application->session || $subject->session === 'All' || $subject->session === $application->session)
            && ($subject->semester === null || $subject->semester === $application->semester)
            && ($subject->academic_year === null || $subject->academic_year === $application->academic_year)
            && ($subject->year_level === 'All' || (string) $subject->year_level === (string) $application->year_level);
    }

    private function validateSubject(Request $request, ?Subject $subject = null): array
    {
        $required = $subject ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'code' => [...$required, 'string', 'regex:/^[A-Z]{2,4}\d{3}$/', 'max:10',
                Rule::unique('subjects', 'code')->ignore($subject?->code, 'code')],
            'name' => [...$required, 'string', 'min:3', 'max:255'],
            'units' => [...$required, 'integer', 'min:1', 'max:6'],
            'days' => [...$required, 'string', 'min:3', 'max:27'],
            'start_time' => [...$required, 'date_format:H:i'],
            'end_time' => [...$required, 'date_format:H:i'],
            'room' => [...$required, 'string', 'max:50'],
            'program' => [...$required, 'string', Rule::in([
                'All', 'BSIT', 'BSBA', 'ABSTM', 'BSTM', 'BSED', 'BEED', 'BSMA', 'BSHM', 'TVL',
                'BSE', 'STEM', 'ABM', 'HUMSS', 'GAS',
            ])],
            'major' => ['sometimes', 'nullable', 'string', Rule::in([
                'Financial Management', 'Human Resources Management', 'Filipino', 'English', 'Mathematics',
                'ICT – Computer Servicing', 'Home Economics', 'Automotive Servicing', 'Electricity Servicing',
            ])],
            'year_level' => [...$required, 'string', Rule::in(['All', '1', '2', '3', '4', '11', '12'])],
            'semester' => ['sometimes', 'nullable', Rule::in(['1st Semester', '2nd Semester', 'Summer'])],
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
        ]);
        $days = explode('/', $data['days'] ?? $subject?->days ?? '');
        $validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        if ($days === [] || count($days) !== count(array_unique($days))
            || array_diff($days, $validDays) !== []) {
            throw ValidationException::withMessages([
                'days' => ['Choose one or more valid weekdays without duplicates.'],
            ]);
        }

        $program = $request->input('program', $subject?->program);
        $major = $request->input('major', $subject?->major);
        $majors = match ($program) {
            'BSBA' => ['Financial Management', 'Human Resources Management'],
            'BSED' => ['Filipino', 'English', 'Mathematics'],
            'TVL' => ['ICT – Computer Servicing', 'Home Economics', 'Automotive Servicing', 'Electricity Servicing'],
            default => [],
        };
        if ((($subject === null || $request->has('major')
                || ($request->has('program') && $request->input('program') !== $subject?->program))
                && $majors !== [] && ! $major)
            || ($major !== null && ! in_array($major, $majors, true))) {
            throw ValidationException::withMessages([
                'major' => ['Choose a major offered by the selected program.'],
            ]);
        }

        return $data;
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

        return [
            'name' => $data['name'] ?? $subject?->name,
            'units' => $data['units'] ?? $subject?->units,
            'days' => $data['days'] ?? $subject?->days,
            'start_time' => $startTime, 'end_time' => $endTime,
            'meeting_time' => $startTime.'-'.$endTime, 'room' => $data['room'] ?? $subject?->room,
            'program' => $data['program'] ?? $subject?->program,
            'major' => array_key_exists('major', $data) ? $data['major'] : $subject?->major,
            'year_level' => (string) ($data['year_level'] ?? $subject?->year_level),
            'session' => $subject?->session ?? 'All',
            'semester' => array_key_exists('semester', $data) ? $data['semester'] : $subject?->semester,
            'academic_year' => array_key_exists('academic_year', $data)
                ? $data['academic_year']
                : $subject?->academic_year,
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
                $sameAcademicYear = ! $first->academic_year || ! $second->academic_year
                    || $first->academic_year === $second->academic_year;
                $sameSemester = ! $first->semester || ! $second->semester
                    || $first->semester === $second->semester;
                if ($sameAcademicYear && $sameSemester
                    && $first->start_time < $second->end_time && $second->start_time < $first->end_time
                    && $sharedDays !== []) {
                    throw ValidationException::withMessages([
                        'codes' => ['Schedule conflict: '.$first->code.' and '.$second->code.' overlap.'],
                    ]);
                }
            }
        }
    }

    private function assertNoRoomClash(array $data, ?Subject $subject = null): void
    {
        $academicYear = $data['academic_year'] ?? $subject?->academic_year;
        $semester = $data['semester'] ?? $subject?->semester;
        $roomSubjects = Subject::query()
            ->whereRaw('LOWER(TRIM(room)) = ?', [mb_strtolower(trim($data['room'] ?? $subject?->room ?? ''))])
            ->when($subject, fn ($query) => $query->where('code', '!=', $subject->code))
            ->when($academicYear, function ($query) use ($academicYear): void {
                $query->where(fn ($scope) => $scope->whereNull('academic_year')
                    ->orWhere('academic_year', $academicYear));
            })
            ->when($semester, function ($query) use ($semester): void {
                $query->where(fn ($scope) => $scope->whereNull('semester')
                    ->orWhere('semester', $semester));
            })
            ->get();
        $days = explode('/', $data['days'] ?? $subject?->days ?? '');
        $start = $data['start_time'] ?? $subject?->start_time;
        $end = $data['end_time'] ?? $subject?->end_time;

        foreach ($roomSubjects as $roomSubject) {
            $sharedDays = array_intersect($days, explode('/', $roomSubject->days));
            if ($sharedDays !== []
                && $start < $roomSubject->end_time && $roomSubject->start_time < $end) {
                throw ValidationException::withMessages([
                    'room' => ['This room is already booked during the selected day and time.'],
                ]);
            }
        }
    }
}
