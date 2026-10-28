<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\ProgramCatalog;
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
            if (! $subject->faculty()->whereKey($request->user()->id)->exists()) {
                return response()->json(['message' => 'You may only configure grading for your assigned subjects.'], 403);
            }

            $data = $request->validate([
                'grade_weights' => ['required', 'array:Prelim,Midterm,Final'],
                'grade_weights.Prelim' => ['required', 'numeric', 'between:0,100'],
                'grade_weights.Midterm' => ['required', 'numeric', 'between:0,100'],
                'grade_weights.Final' => ['required', 'numeric', 'between:0,100'],
            ]);
            if (abs(array_sum($data['grade_weights']) - 100) > 0.001) {
                throw ValidationException::withMessages([
                    'grade_weights' => ['Grading period weights must add up to 100%.'],
                ]);
            }

            $subject->update(['grade_weights' => $data['grade_weights']]);

            return response()->json($subject->fresh()->toArray());
        }

        $this->normalizeCode($request);
        $this->normalizeSchedule($request);
        $data = $this->validateSubject($request, $subject);
        $this->assertNoRoomClash($data, $subject);
        $mapped = $this->map($data, $subject);
        $semesterChanged = array_key_exists('semester', $mapped)
            && $mapped['semester'] !== $subject->semester;

        DB::transaction(function () use ($subject, $mapped, $semesterChanged): void {
            $lockedSubject = Subject::query()->whereKey($subject->code)->lockForUpdate()->firstOrFail();
            if ($semesterChanged) {
                $this->removeInvalidStudentLoads($lockedSubject, $mapped['semester']);
            }
            $lockedSubject->update($mapped);
        });

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
        $sessionOptions = ['Afternoon', 'Evening', 'Weekends', 'All'];
        $submittedSchedules = $request->input('schedules', []);
        if (is_array($submittedSchedules)) {
            foreach ($submittedSchedules as $code => $schedule) {
                if (! is_array($schedule)
                    || ! array_intersect(['session', 'days', 'start_time', 'end_time', 'room'], array_keys($schedule))) {
                    continue;
                }
                $subjectSession = Subject::find($code)?->session;
                $session = in_array($subjectSession, $sessionOptions, true) ? $subjectSession : 'All';
                $submittedSchedules[$code] = [[...$schedule, 'session' => $schedule['session'] ?? $session]];
            }
            $request->merge(['schedules' => $submittedSchedules]);
        }

        $data = $request->validate([
            'codes' => ['present', 'array'],
            'codes.*' => ['string', 'distinct', 'exists:subjects,code'],
            'semester' => ['required', Rule::in(['1st Semester', '2nd Semester', 'Summer'])],
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
            'section' => ['sometimes', 'nullable', Rule::in(['All', 'A', 'B'])],
            'schedules' => ['sometimes', 'array'],
            'schedules.*' => ['array'],
            'schedules.*.*' => ['array:session,days,start_time,end_time,room,section'],
            'schedules.*.*.session' => ['required', Rule::in($sessionOptions)],
            'schedules.*.*.days' => ['nullable', 'string', 'max:27'],
            'schedules.*.*.start_time' => ['nullable', 'date_format:H:i'],
            'schedules.*.*.end_time' => ['nullable', 'date_format:H:i'],
            'schedules.*.*.room' => ['nullable', 'string', 'max:50'],
            'schedules.*.*.section' => ['nullable', Rule::in(['All', 'A', 'B'])],
        ]);
        $data['section'] = $data['section'] ?? 'All';
        $subjects = Subject::whereIn('code', $data['codes'])->get();
        if ($subjects->contains(fn (Subject $subject) => ! $this->matchesAssignmentScope($subject, $data))) {
            throw ValidationException::withMessages([
                'codes' => ['Every subject must match the selected semester and academic year.'],
            ]);
        }
        $schedules = $subjects->mapWithKeys(function (Subject $subject) use ($data, $sessionOptions): array {
            $inputs = $data['schedules'][$subject->code] ?? [[
                'session' => in_array($subject->session, $sessionOptions, true) ? $subject->session : 'All',
                'days' => $subject->days,
                'start_time' => $subject->start_time,
                'end_time' => $subject->end_time,
                'room' => $subject->room,
            ]];
            if ($inputs === []) {
                throw ValidationException::withMessages([
                    'schedules.'.$subject->code => ['Add at least one session schedule for each assigned subject.'],
                ]);
            }
            $seenSessions = [];
            $subjectSchedules = [];
            foreach ($inputs as $input) {
                $session = $input['session'];
                if (in_array($session, $seenSessions, true)) {
                    throw ValidationException::withMessages([
                        'schedules.'.$subject->code => ['Each session can have only one schedule per subject, section, and term.'],
                    ]);
                }
                $seenSessions[] = $session;
                $schedule = [
                    'session' => $session,
                    'section' => $input['section'] ?? $data['section'],
                    'days' => $input['days'] ?? null,
                    'start_time' => $input['start_time'] ?? null,
                    'end_time' => $input['end_time'] ?? null,
                    'room' => $input['room'] ?? null,
                ];
                $hasAnyTime = $schedule['days'] || $schedule['start_time'] || $schedule['end_time'];
                if ($hasAnyTime) {
                    $days = explode('/', (string) $schedule['days']);
                    $validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    if (! $schedule['days'] || ! $schedule['start_time'] || ! $schedule['end_time']
                        || count($days) !== count(array_unique($days))
                        || array_diff($days, $validDays) !== []
                        || $schedule['end_time'] <= $schedule['start_time']) {
                        throw ValidationException::withMessages([
                            'schedules.'.$subject->code => ['Choose valid days and a start/end time for every scheduled session.'],
                        ]);
                    }
                }
                $subjectSchedules[] = $schedule;
            }

            return [$subject->code => $subjectSchedules];
        })->all();
        $this->assertNoFacultyScheduleClashes($schedules, $data, $user);

        DB::transaction(function () use ($data, $subjects, $schedules, $user): void {
            Subject::query()->whereIn('code', $subjects->pluck('code'))
                ->orderBy('code')->lockForUpdate()->get();

            foreach ($subjects as $subject) {
                foreach ($schedules[$subject->code] as $schedule) {
                    $conflict = DB::table('faculty_subject')
                        ->where('subject_code', $subject->code)
                        ->where('semester', $data['semester'])
                        ->where('academic_year', $data['academic_year'])
                        ->where('session', $schedule['session'])
                        ->where(function ($query) use ($schedule, $user): void {
                            $query->where('faculty_id', '!=', $user->id)
                                ->orWhere('section', '!=', ($schedule['section'] ?: ''));
                        })
                        ->when(($schedule['section'] ?: 'All') !== 'All', function ($query) use ($schedule): void {
                            $query->where(fn ($sections) => $sections->where('section', 'All')
                                ->orWhere('section', $schedule['section']));
                        })
                        ->exists();
                    if ($conflict) {
                        throw ValidationException::withMessages([
                            'codes' => [$subject->code.' is already assigned to another faculty member for this session, section, and term.'],
                        ]);
                    }
                }
            }

            DB::table('faculty_subject')
                ->where('faculty_id', $user->id)
                ->where('semester', $data['semester'])
                ->where('academic_year', $data['academic_year'])
                ->where('section', $data['section'])
                ->delete();

            foreach ($subjects as $subject) {
                foreach ($schedules[$subject->code] as $schedule) {
                    DB::table('faculty_subject')->insert([
                        'faculty_id' => $user->id,
                        'subject_code' => $subject->code,
                        'semester' => $data['semester'],
                        'academic_year' => $data['academic_year'],
                        'section' => $schedule['section'],
                        ...$schedule,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

            }
        });

        return response()->noContent();
    }

    private function removeInvalidStudentLoads(Subject $subject, ?string $newSemester): void
    {
        $applications = DB::table('enrollment_subject')
            ->join('enrollment_applications', 'enrollment_applications.id', '=', 'enrollment_subject.enrollment_application_id')
            ->where('enrollment_subject.subject_code', $subject->code)
            ->where(function ($query) use ($newSemester): void {
                if ($newSemester === null) {
                    $query->whereNotNull('enrollment_applications.semester');
                } else {
                    $query->whereNull('enrollment_applications.semester')
                        ->orWhere('enrollment_applications.semester', '!=', $newSemester);
                }
            })
            ->pluck('enrollment_subject.enrollment_application_id');

        if ($applications->isEmpty()) {
            return;
        }

        DB::table('enrollment_subject')
            ->where('subject_code', $subject->code)
            ->whereIn('enrollment_application_id', $applications)
            ->delete();

        $studentIds = DB::table('enrollment_applications')
            ->whereIn('id', $applications)
            ->whereNotNull('student_id')
            ->pluck('student_id');

        foreach ($studentIds as $studentId) {
            $stillEnrolled = DB::table('enrollment_subject')
                ->join('enrollment_applications', 'enrollment_applications.id', '=', 'enrollment_subject.enrollment_application_id')
                ->where('enrollment_subject.subject_code', $subject->code)
                ->where('enrollment_applications.student_id', $studentId)
                ->exists();
            if (! $stillEnrolled) {
                DB::table('student_subject')
                    ->where('student_id', $studentId)
                    ->where('subject_code', $subject->code)
                    ->delete();
            }
        }
    }

    private function assertNoFacultyScheduleClashes(array $schedules, array $scope, User $faculty): void
    {
        $existing = DB::table('faculty_subject')
            ->where('faculty_id', $faculty->id)
            ->where('semester', $scope['semester'])
            ->where('academic_year', $scope['academic_year'])
            ->where('section', '!=', $scope['section'])
            ->get(['subject_code', 'section', 'days', 'start_time', 'end_time']);
        $existingRooms = DB::table('faculty_subject')
            ->where('semester', $scope['semester'])
            ->where('academic_year', $scope['academic_year'])
            ->whereNotNull('room')
            ->where(function ($query) use ($scope, $faculty): void {
                $query->where('faculty_id', '!=', $faculty->id)
                    ->orWhere('section', '!=', $scope['section']);
            })
            ->get(['subject_code', 'days', 'start_time', 'end_time', 'room']);
        $scheduled = [];

        foreach ($schedules as $code => $subjectSchedules) {
            foreach ($subjectSchedules as $schedule) {
                if (! $schedule['days'] || ! $schedule['start_time'] || ! $schedule['end_time']) {
                    continue;
                }
                $scheduled[] = ['code' => $code, ...$schedule];
            }
        }

        for ($i = 0; $i < count($scheduled); $i++) {
            for ($j = $i + 1; $j < count($scheduled); $j++) {
                if ($this->schedulesOverlap($scheduled[$i], $scheduled[$j])) {
                    throw ValidationException::withMessages([
                        'schedules' => ['Schedule conflict: '.$scheduled[$i]['code'].' and '.$scheduled[$j]['code'].' overlap.'],
                    ]);
                }
                if ($this->roomsOverlap($scheduled[$i], $scheduled[$j])) {
                    throw ValidationException::withMessages([
                        'schedules' => ['Room conflict: '.$scheduled[$i]['code'].' and '.$scheduled[$j]['code'].' overlap in '.$scheduled[$i]['room'].'.'],
                    ]);
                }
            }

            foreach ($existing as $assignment) {
                if ($assignment->days && $assignment->start_time && $assignment->end_time
                    && $this->schedulesOverlap($scheduled[$i], (array) $assignment)) {
                    throw ValidationException::withMessages([
                        'schedules' => ['Schedule conflict: '.$scheduled[$i]['code'].' overlaps '.$assignment->subject_code.' in section '.$assignment->section.'.'],
                    ]);
                }
            }
            foreach ($existingRooms as $assignment) {
                if ($assignment->subject_code !== $scheduled[$i]['code']
                    && $assignment->days && $assignment->start_time && $assignment->end_time
                    && $this->roomsOverlap($scheduled[$i], (array) $assignment)) {
                    throw ValidationException::withMessages([
                        'schedules' => ['Room conflict: '.$scheduled[$i]['code'].' overlaps '.$assignment->subject_code.' in '.$assignment->room.'.'],
                    ]);
                }
            }
        }
    }

    private function schedulesOverlap(array $first, array $second): bool
    {
        return array_intersect(explode('/', $first['days']), explode('/', $second['days'])) !== []
            && $first['start_time'] < $second['end_time']
            && $second['start_time'] < $first['end_time'];
    }

    private function roomsOverlap(array $first, array $second): bool
    {
        return isset($first['room'], $second['room'])
            && trim((string) $first['room']) !== ''
            && mb_strtolower(trim((string) $first['room'])) === mb_strtolower(trim((string) $second['room']))
            && $this->schedulesOverlap($first, $second);
    }

    public function assignStudent(Request $request, Student $student)
    {
        $data = $request->validate([
            'codes' => ['present', 'array'],
            'codes.*' => ['string', 'distinct', 'exists:subjects,code'],
            'enrollment_reference' => ['sometimes', 'required', 'exists:enrollment_applications,reference'],
        ]);
        $subjects = Subject::whereIn('code', $data['codes'])->get();
        $applicationQuery = $student->enrollmentApplications()->where('status', 'Enrolled');
        if (isset($data['enrollment_reference'])) {
            $applicationQuery->where('reference', $data['enrollment_reference']);
        }
        $application = $applicationQuery->latest('id')->first()
            ?? (! isset($data['enrollment_reference'])
                ? $student->enrollmentApplication()->where('status', 'Enrolled')->first()
                : null);
        if (! $application || (int) $application->student_id !== (int) $student->id) {
            throw ValidationException::withMessages([
                'student' => ['Subject loads can only be assigned to students with an enrolled application.'],
            ]);
        }
        if ($subjects->contains(fn (Subject $subject) => ! $this->matchesEnrollment($subject, $application))) {
            throw ValidationException::withMessages([
                'codes' => ['The subject load must match the student’s program, major, session, semester, and academic year.'],
            ]);
        }
        $this->assertNoScheduleClashes($subjects);
        DB::transaction(function () use ($application, $data, $student): void {
            $lockedApplication = EnrollmentApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedStudent = Student::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            if ($lockedApplication->status !== 'Enrolled'
                || (int) $lockedApplication->student_id !== (int) $lockedStudent->id) {
                throw ValidationException::withMessages([
                    'student' => ['Subject loads can only be assigned to students with an enrolled application.'],
                ]);
            }

            $legacyCodes = $lockedStudent->subjects()->get()
                ->filter(fn (Subject $subject) => $this->matchesEnrollment($subject, $lockedApplication))
                ->pluck('code');
            $previousCodes = collect($lockedApplication->subjects()->pluck('subjects.code'))
                ->merge($lockedApplication->requested_subjects ?? [])
                ->merge($legacyCodes)
                ->unique()
                ->values();
            $removedCodes = $previousCodes->diff($data['codes']);

            $lockedApplication->subjects()->sync($data['codes']);
            $lockedApplication->update(['requested_subjects' => $data['codes']]);
            $lockedStudent->subjects()->syncWithoutDetaching($data['codes']);

            if ($removedCodes->isNotEmpty()) {
                $otherApplications = EnrollmentApplication::query()
                    ->where('student_id', $lockedStudent->id)
                    ->where('id', '!=', $lockedApplication->id)
                    ->where('status', 'Enrolled')
                    ->with('subjects:code')
                    ->get(['id', 'requested_subjects']);

                foreach ($removedCodes as $code) {
                    $usedByAnotherEnrollment = $otherApplications->contains(
                        fn (EnrollmentApplication $other) => in_array($code, $other->requested_subjects ?? [], true)
                            || $other->subjects->contains('code', $code)
                    );
                    if (! $usedByAnotherEnrollment) {
                        $lockedStudent->subjects()->detach($code);
                    }
                }
            }
        });

        return response()->json(['codes' => $application->subjects()->pluck('subjects.code')->all()]);
    }

    private function matchesAssignmentScope(Subject $subject, array $scope): bool
    {
        return ($subject->semester === null || $subject->semester === $scope['semester'])
            && ($subject->academic_year === null || $subject->academic_year === $scope['academic_year']);
    }

    private function matchesEnrollment(Subject $subject, EnrollmentApplication $application): bool
    {
        return ($subject->program === 'All' || $subject->program === $application->program)
            && ($subject->major === null || $subject->major === $application->major)
            && $this->matchesEnrollmentSession($subject, $application)
            && ($subject->semester === null || $subject->semester === $application->semester)
            && ($subject->academic_year === null || $subject->academic_year === $application->academic_year);
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

    private function validateSubject(Request $request, ?Subject $subject = null): array
    {
        $required = $subject ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'code' => [...$required, 'string', 'regex:/^[A-Z]{2,4}\d{3}$/', 'max:10',
                Rule::unique('subjects', 'code')->ignore($subject?->code, 'code')],
            'name' => [...$required, 'string', 'min:3', 'max:255'],
            'units' => [...$required, 'integer', 'min:1', 'max:6'],
            'days' => ['sometimes', 'nullable', 'string', 'max:27'],
            'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:50'],
            'program' => [...$required, 'string', Rule::in(array_merge(
                ['All'],
                ProgramCatalog::PROGRAMS,
                $subject?->program === 'ABSTM' ? ['ABSTM'] : []
            ))],
            'major' => ['sometimes', 'nullable', 'string', Rule::in(ProgramCatalog::allMajors())],
            'year_level' => [...$required, 'string', Rule::in(['All', '1', '2', '3', '4', '11', '12'])],
            'semester' => ['sometimes', 'nullable', Rule::in(['1st Semester', '2nd Semester', 'Summer'])],
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
        ]);
        if ($data['days'] ?? $subject?->days) {
            $days = explode('/', $data['days'] ?? $subject?->days ?? '');
            $validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            if (count($days) !== count(array_unique($days))
                || array_diff($days, $validDays) !== []) {
                throw ValidationException::withMessages([
                    'days' => ['Choose one or more valid weekdays without duplicates.'],
                ]);
            }
        }

        $program = $request->input('program', $subject?->program);
        $major = $request->input('major', $subject?->major);
        $majors = ProgramCatalog::majorsFor((string) $program);
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
        if (($startTime === null) !== ($endTime === null)
            || ($startTime !== null && $endTime !== null && $endTime <= $startTime)) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be later than start time.'],
            ]);
        }

        return [
            'name' => $data['name'] ?? $subject?->name,
            'units' => $data['units'] ?? $subject?->units,
            'days' => $data['days'] ?? $subject?->days ?? '',
            'start_time' => $startTime, 'end_time' => $endTime,
            'meeting_time' => $startTime !== null && $endTime !== null
                ? $startTime.'-'.$endTime
                : ($subject?->meeting_time ?? ''),
            'room' => $data['room'] ?? $subject?->room ?? '',
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
        $room = trim((string) ($data['room'] ?? $subject?->room ?? ''));
        if ($room === '' || ! $start || ! $end || ! ($data['days'] ?? $subject?->days)) {
            return;
        }

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
