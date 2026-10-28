<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\EnrollmentApplication;
use App\Models\Grade;
use App\Models\ServiceRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AcademicYearService;
use App\Services\StandingService;
use App\Support\NameParts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortalDataController extends Controller
{
    public function index(Request $request, StandingService $standing, AcademicYearService $academicYear)
    {
        $user = $request->user()->load('role');
        $role = $user->role->slug;
        $student = $user->student;

        $subjectQuery = Subject::query()->with('faculty:id');
        $studentQuery = Student::query()
            ->select(['id', 'user_id', 'school_id', 'enrollment_application_id', 'status', 'program', 'major',
                'year_level', 'section', 'session', 'semester'])
            ->with([
                'user:id,name,first_name,middle_name,last_name',
                'enrollmentApplication:id,academic_year',
                'enrollmentApplications' => fn ($query) => $query->where('status', 'Enrolled')
                    ->latest('id')->select(['id', 'student_id', 'status', 'academic_year', 'semester', 'program', 'major', 'session']),
                'enrollmentApplications.subjects:code,name,units,days,meeting_time,start_time,end_time,room,program,major,year_level,session,semester,academic_year',
                'subjects:code,name,units,days,meeting_time,start_time,end_time,room,program,major,year_level,session,semester,academic_year',
                'subjects.faculty:id',
            ]);

        if ($role === 'student') {
            $legacyCodes = $student?->subjects()->pluck('subjects.code') ?? collect();
            $enrollmentCodes = $student
                ? DB::table('enrollment_subject')
                    ->join('enrollment_applications', 'enrollment_applications.id', '=', 'enrollment_subject.enrollment_application_id')
                    ->where('enrollment_applications.student_id', $student->id)
                    ->where('enrollment_applications.status', 'Enrolled')
                    ->pluck('enrollment_subject.subject_code')
                : collect();
            $subjectQuery->whereIn('code', $legacyCodes->merge($enrollmentCodes)->unique());

            $studentQuery->whereKey($student?->id ?? 0);
        } elseif ($role === 'faculty') {
            $subjectQuery->whereHas('faculty', fn ($query) => $query->whereKey($user->id));

            $subjectCodes = (clone $subjectQuery)->pluck('code');

            $studentQuery->whereHas(
                'subjects',
                fn ($query) => $query->whereIn('subjects.code', $subjectCodes)
            );
        } elseif (! in_array($role, ['registrar', 'admin'], true)) {
            $studentQuery->whereKey(0);
        }
        if (in_array($role, ['registrar', 'admin', 'faculty'], true)) {
            $studentQuery->whereHas('enrollmentApplications', fn ($query) => $query->where('status', 'Enrolled'));
        }

        $students = $studentQuery->get();
        $subjects = $subjectQuery->get();
        $subjectCodes = $subjects->pluck('code');

        $facultyUsers = $role === 'registrar'
            ? User::whereHas(
                'role',
                fn ($query) => $query->where('slug', 'faculty')
            )
                ->with('role')
                ->orderBy('name')
                ->get()
            : collect();

        // Announcements
        $announcementQuery = Announcement::with('author')
            ->whereDate('publish_date', '<=', now()->toDateString());

        if ($role === 'student' && $student) {
            $announcementQuery
                ->where(function ($query) use ($student) {
                    $query->where('program', 'All')
                        ->orWhere('program', $student->program);
                })
                ->where(function ($query) use ($student) {
                    $query->where('year_level', 'All')
                        ->orWhere(
                            'year_level',
                            (string) $student->year_level
                        );
                })
                ->where(function ($query) use ($student) {
                    $query->where('section', 'All')
                        ->orWhere('section', $student->section);
                })
                ->where(function ($query) {
                    $query->whereNull('valid_until')
                        ->orWhereDate(
                            'valid_until',
                            '>=',
                            now()->toDateString()
                        );
                });
        }

        // Retrieve grades once
        $gradeRows = $students->isEmpty() || ($role !== 'student' && $subjectCodes->isEmpty())
            ? collect()
            : Grade::query()
                ->whereIn('student_id', $students->modelKeys())
                ->when($role !== 'student', fn ($query) => $query->whereIn('subject_code', $subjectCodes))
                ->get(['student_id', 'subject_code', 'term', 'score']);

        // Group grades by student
        $gradesByStudent = $gradeRows->groupBy('student_id');
        $gradedSubjects = $gradeRows->isEmpty()
            ? collect()
            : Subject::with('faculty:id')
                ->whereIn('code', $gradeRows->pluck('subject_code')->unique())
                ->get(['code', 'name', 'units', 'days', 'meeting_time', 'start_time', 'end_time', 'room',
                    'program', 'major', 'year_level', 'session', 'semester', 'academic_year'])
                ->keyBy('code');
        if ($role === 'student') {
            $subjects = $subjects->concat($gradedSubjects->values())->unique('code');
        }

        // Student grades and academic standing
        $studentRows = $students->map(
            function (Student $record) use (
                $gradesByStudent,
                $gradedSubjects,
                $standing
            ) {
                $own = $gradesByStudent->get(
                    $record->id,
                    collect()
                );
                $gradesByTerm = $own->keyBy(
                    fn (Grade $grade) => $grade->subject_code.'|'.$grade->term
                );
                $recordEnrollmentSubjects = $record->enrollmentApplications
                    ->flatMap(fn (EnrollmentApplication $application) => $application->subjects);
                $summary = $standing->evaluate(
                    $record->subjects->concat($recordEnrollmentSubjects)->unique('code'),
                    $own->groupBy('subject_code')
                );
                $recordSubjects = $record->subjects->concat($recordEnrollmentSubjects)->concat(
                    $gradedSubjects->only($own->pluck('subject_code')->unique()->all())->values()
                )->unique('code');
                $enrollment = $record->enrollmentApplications->first();

                $user = $record->user;
                $nameParts = $user
                    ? NameParts::resolve($user->name, $user->first_name, $user->middle_name, $user->last_name)
                    : ['first_name' => '', 'middle_name' => '', 'last_name' => ''];
                $grades = [];
                foreach ($recordSubjects as $subject) {
                    $grades[$subject->code] = collect([
                        'Prelim',
                        'Midterm',
                        'Final',
                    ])->map(
                        fn ($term) => $gradesByTerm->get($subject->code.'|'.$term)?->score
                    )->all();

                }

                return [
                    'id' => $record->id,
                    'name' => $user
                        ? NameParts::displayName(
                            $user->name,
                            $nameParts['first_name'],
                            $nameParts['middle_name'],
                            $nameParts['last_name'],
                            true
                        )
                        : 'Unlinked student',
                    'first_name' => $nameParts['first_name'] ?: null,
                    'middle_name' => $nameParts['middle_name'] ?: null,
                    'last_name' => $nameParts['last_name'] ?: null,
                    'school_id' => $record->school_id,
                    'session' => $enrollment?->session ?? $record->session,
                    'major' => $enrollment?->major ?? $record->major,
                    'semester' => $enrollment?->semester ?? $record->semester,
                    'academic_year' => $enrollment?->academic_year ?? $record->enrollmentApplication?->academic_year,
                    'academic_years' => $record->enrollmentApplications
                        ->pluck('academic_year')
                        ->filter()
                        ->unique()
                        ->sort()
                        ->values(),
                    'enrollment_status' => $enrollment?->status,
                    'subject_codes' => $record->subjects->pluck('code')->values(),
                    'status' => $record->status,
                    'prog' => $record->program,
                    'yr' => $record->year_level,
                    'sec' => $record->section,
                    'g' => $grades,
                    'gwa' => $summary['gwa'],
                    'st' => $summary['status'],
                    'declining' => $summary['declining'],
                ];
            }
        );
        $eligibleEnrollments = in_array($role, ['registrar', 'admin'], true)
            ? $this->eligibleEnrollmentRows()
            : [];
        if ($role === 'faculty') {
            $studentRows = collect($this->facultyEnrollmentRows($user));
        }

        // Portal settings
        $settings = DB::table('portal_settings')
            ->pluck('value', 'key');

        $disabled = DB::table('module_access')
            ->where('enabled', false)
            ->join('roles', 'roles.id', '=', 'module_access.role_id')
            ->get(['roles.slug', 'module_access.module_key'])
            ->map(
                fn ($row) => $row->slug.':'.$row->module_key
            )
            ->values();

        // Final portal response
        return response()->json([
            'today' => now()->toDateString(),

            'rules' => array_intersect_key(
                $standing->rules(),
                ['pass' => 1, 'safe' => 1]
            ),

            'alerts' => $role === 'faculty'
                ? DB::table('faculty_alerts')
                    ->where('faculty_id', $user->id)
                    ->latest()
                    ->limit(50)
                    ->get()
                : [],

            'disabled' => $disabled,

            'users' => $facultyUsers->map(
                function (User $record): array {
                    $parts = NameParts::resolve($record->name, $record->first_name, $record->middle_name, $record->last_name);

                    return [
                        'id' => $record->id,
                        'name' => NameParts::displayName(
                            $record->name,
                            $parts['first_name'],
                            $parts['middle_name'],
                            $parts['last_name'],
                            true
                        ),
                        'first_name' => $parts['first_name'] ?: null,
                        'middle_name' => $parts['middle_name'] ?: null,
                        'last_name' => $parts['last_name'] ?: null,
                        'email' => $record->email,
                        'role' => $record->role?->name,
                        'act' => $record->is_active,
                        'phone' => $record->phone ?? '',
                        'sid' => null,
                        'avatar' => $record->avatar_path
                            ? asset('storage/'.$record->avatar_path)
                            : null,
                    ];
                }
            ),

            'subjects' => $subjects->mapWithKeys(
                fn (Subject $subject) => [
                    $subject->code => [
                        'n' => $subject->name,
                        'u' => $subject->units,
                        'd' => $subject->days,

                        'tm' => $subject->start_time
                            && $subject->end_time
                                ? $subject->start_time
                                    .'–'
                                    .$subject->end_time
                                : $subject->meeting_time,

                        'start' => $subject->start_time,
                        'end' => $subject->end_time,
                        'r' => $subject->room,
                        'p' => $subject->program,
                        'major' => $subject->major,
                        'session' => $subject->session,
                        'semester' => $subject->semester,
                        'academic_year' => $subject->academic_year,
                        'grade_weights' => $subject->grade_weights ?? [],

                        'y' => is_numeric($subject->year_level)
                            ? (int) $subject->year_level
                            : $subject->year_level,

                        'faculty_ids' => collect($subject->faculty->modelKeys())->unique()->values(),
                        'faculty_assignments' => $subject->faculty->map(fn (User $faculty) => [
                            'id' => $faculty->pivot->id,
                            'faculty_id' => $faculty->id,
                            'semester' => $faculty->pivot->semester,
                            'academic_year' => $faculty->pivot->academic_year,
                            'section' => $faculty->pivot->section,
                            'session' => $faculty->pivot->session
                                ?? ($subject->session !== 'All' ? $subject->session : 'Afternoon'),
                            'days' => $faculty->pivot->days ?? $subject->days,
                            'start_time' => $faculty->pivot->start_time ?? $subject->start_time,
                            'end_time' => $faculty->pivot->end_time ?? $subject->end_time,
                            'room' => $faculty->pivot->room ?? $subject->room,
                        ])->values(),
                        'f' => collect($subject->faculty->modelKeys())->unique()->first(),
                    ],
                ]
            ),

            'students' => $studentRows,
            'eligible_enrollments' => $eligibleEnrollments,
            'academic_year' => $academicYear->current(),

            'open_request_count' => $student
                ? ServiceRequest::where('student_id', $student->id)
                    ->whereNotIn('status', ['Completed', 'Rejected'])
                    ->count()
                : 0,

            'announcements' => $announcementQuery
                ->latest('publish_date')
                ->limit(50)
                ->get()
                ->map(
                    fn (Announcement $announcement) => [
                        'id' => $announcement->id,
                        't' => $announcement->title,
                        'm' => $announcement->message,

                        'aud' => $announcement->program === 'All'
                            ? 'All programs'
                            : $announcement->program,

                        'pr' => $announcement->priority,
                        'by' => $announcement->author?->name ?? 'Registrar',

                        'd' => $announcement->publish_date->format('M d'),
                        'dt' => $announcement->publish_date->format('Y-m-d'),

                        'end' => $announcement->valid_until?->format('Y-m-d'),
                        'prog' => $announcement->program,

                        'yr' => is_numeric($announcement->year_level)
                            ? (int) $announcement->year_level
                            : $announcement->year_level,

                        'sec' => $announcement->section,
                    ]
                ),

            'notifications' => $student
                ? DB::table('portal_notifications')
                    ->where('student_id', $student->id)
                    ->latest()
                    ->limit(50)
                    ->get()
                    ->map(
                        fn ($notification) => [
                            'id' => $notification->id,
                            'sid' => $notification->student_id,
                            'type' => $notification->type,
                            't' => $notification->title,
                            'm' => $notification->message,

                            'd' => Carbon::parse(
                                $notification->created_at
                            )->format('M d'),

                            'read' => (bool) $notification->is_read,
                        ]
                    )
                : [],

            'requestTypes' => [
                'Certificate of Enrollment',
                'Transcript of Records',
                'Good Moral Certificate',
                'Clearance',
                'Copy of Grades',
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligibleEnrollmentRows(): array
    {
        return EnrollmentApplication::query()
            ->where('status', 'Enrolled')
            ->whereNotNull('student_id')
            ->whereNotNull('semester')
            ->whereNotNull('academic_year')
            ->with(['student.user', 'student.subjects', 'subjects', 'grades'])
            ->latest('id')
            ->get()
            ->filter(fn (EnrollmentApplication $application) => $this->isEligibleStudentRecord($application))
            ->map(fn (EnrollmentApplication $application) => $this->enrollmentRow(
                $application,
                $this->enrolledSubjectCodes($application)
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facultyEnrollmentRows(User $faculty): array
    {
        $assignments = DB::table('faculty_subject')
            ->where('faculty_id', $faculty->id)
            ->get(['subject_code', 'semester', 'academic_year', 'section', 'session'])
            ->groupBy('subject_code');

        if ($assignments->isEmpty()) {
            return [];
        }

        return EnrollmentApplication::query()
            ->where('status', 'Enrolled')
            ->whereNotNull('student_id')
            ->whereNotNull('semester')
            ->whereNotNull('academic_year')
            ->with(['student.user', 'student.subjects', 'subjects', 'grades'])
            ->latest('id')
            ->get()
            ->filter(fn (EnrollmentApplication $application) => $this->isEligibleStudentRecord($application))
            ->map(function (EnrollmentApplication $application) use ($assignments): ?array {
                $section = $application->student->section;
                $codes = collect($this->enrolledSubjectCodes($application))
                    ->filter(function (string $code) use ($application, $assignments, $section): bool {
                        return $assignments->get($code, collect())->contains(
                            fn (object $assignment): bool => $assignment->semester === $application->semester
                                && $assignment->academic_year === $application->academic_year
                                && in_array($assignment->session, [$application->session ?: 'Afternoon', 'All'], true)
                                && ($assignment->section === 'All' || $assignment->section === $section)
                        );
                    })
                    ->values()
                    ->all();

                return $codes ? $this->enrollmentRow($application, $codes) : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function isEligibleStudentRecord(EnrollmentApplication $application): bool
    {
        if (! $application->student
            || in_array(strtolower((string) $application->student_status), [
                'applicant', 'pending', 'rejected', 'withdrawn', 'not enrolled',
            ], true)) {
            return false;
        }

        return ! in_array(
            strtolower((string) $application->student->status),
            ['applicant', 'pending', 'rejected', 'withdrawn', 'not enrolled'],
            true
        );
    }

    /**
     * Reconcile current application subject links with legacy student loads.
     * Legacy links are included only when the subject matches this enrollment's scope.
     *
     * @return list<string>
     */
    private function enrolledSubjectCodes(EnrollmentApplication $application): array
    {
        return $application->subjects
            ->concat($application->student?->subjects ?? collect())
            ->filter(fn (Subject $subject): bool => $this->matchesEnrollmentScope($subject, $application))
            ->pluck('code')
            ->unique()
            ->values()
            ->all();
    }

    private function matchesEnrollmentScope(Subject $subject, EnrollmentApplication $application): bool
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

    /**
     * @param  list<string>|null  $subjectCodes
     * @return array<string, mixed>
     */
    private function enrollmentRow(EnrollmentApplication $application, ?array $subjectCodes = null): array
    {
        $student = $application->student;
        $user = $student?->user;
        $parts = NameParts::resolve(
            $application->name,
            $application->first_name ?: $user?->first_name,
            $application->middle_name ?: $user?->middle_name,
            $application->last_name ?: $user?->last_name
        );
        $enrolledCodes = $subjectCodes ?? $application->subjects->pluck('code')->values()->all();
        $grades = $application->grades->keyBy(fn (Grade $grade) => $grade->subject_code.'|'.$grade->term);
        foreach (Grade::query()
            ->whereNull('enrollment_application_id')
            ->where('student_id', $student->id)
            ->whereIn('subject_code', $enrolledCodes)
            ->get() as $legacyGrade) {
            $grades->put($legacyGrade->subject_code.'|'.$legacyGrade->term, $legacyGrade);
        }
        $bySubject = [];
        foreach ($enrolledCodes as $code) {
            $bySubject[$code] = collect(['Prelim', 'Midterm', 'Final'])
                ->map(fn (string $term) => $grades->get($code.'|'.$term)?->score)
                ->all();
        }

        return [
            'id' => $student->id,
            'enrollment_id' => $application->id,
            'enrollment_ref' => $application->reference,
            'name' => NameParts::displayName(
                $application->name,
                $parts['first_name'],
                $parts['middle_name'],
                $parts['last_name'],
                true
            ),
            'first_name' => $parts['first_name'] ?: null,
            'middle_name' => $parts['middle_name'] ?: null,
            'last_name' => $parts['last_name'] ?: null,
            'school_id' => $student->school_id,
            'session' => $application->session,
            'academic_year' => $application->academic_year,
            'major' => $application->major,
            'semester' => $application->semester,
            'enrollment_status' => $application->status,
            'subject_codes' => $enrolledCodes,
            'status' => $student->status,
            'prog' => $application->program,
            'yr' => $application->year_level,
            'sec' => $student->section,
            'g' => $bySubject,
        ];
    }
}
