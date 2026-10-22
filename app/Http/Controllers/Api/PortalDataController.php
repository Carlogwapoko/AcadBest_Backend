<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Grade;
use App\Models\ServiceRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortalDataController extends Controller
{
    public function index(Request $request, StandingService $standing)
    {
        $user = $request->user()->load('role');
        $role = $user->role->slug;
        $student = $user->student;

        $subjectQuery = Subject::query();
        $studentQuery = Student::query()
            ->select(['id', 'user_id', 'school_id', 'enrollment_application_id', 'status', 'program', 'year_level', 'section', 'session'])
            ->with([
                'user:id,name,first_name,middle_name,last_name',
                'enrollmentApplication:id,academic_year',
                'subjects:code,name,units,days,meeting_time,start_time,end_time,room,program,year_level,faculty_id,session,semester,academic_year',
            ]);

        if ($role === 'student') {
            $subjectQuery->whereIn(
                'code',
                $student?->subjects()->pluck('subjects.code') ?? []
            );

            $studentQuery->whereKey($student?->id ?? 0);
        } elseif ($role === 'faculty') {
            $subjectQuery->where('faculty_id', $user->id);

            $subjectCodes = (clone $subjectQuery)->pluck('code');

            $studentQuery->whereHas(
                'subjects',
                fn ($query) => $query->whereIn(
                    'subjects.code',
                    $subjectCodes
                )
            );
        } elseif (! in_array($role, ['registrar', 'admin'], true)) {
            $studentQuery->whereKey(0);
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
            : Subject::whereIn('code', $gradeRows->pluck('subject_code')->unique())
                ->get(['code', 'name', 'units', 'days', 'meeting_time', 'start_time', 'end_time', 'room',
                    'program', 'year_level', 'faculty_id', 'session', 'semester', 'academic_year'])
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
                $recordSubjects = $record->subjects->concat(
                    $gradedSubjects->only($own->pluck('subject_code')->unique()->all())->values()
                )->unique('code');

                $summary = $standing->evaluate(
                    $record->subjects,
                    $own->groupBy('subject_code')
                );

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
                    'name' => $record->user
                        ? ($this->fullName($record->user->first_name, $record->user->middle_name, $record->user->last_name)
                            ?: $record->user->name)
                        : 'Unlinked student',
                    'first_name' => $record->user?->first_name,
                    'middle_name' => $record->user?->middle_name,
                    'last_name' => $record->user?->last_name,
                    'school_id' => $record->school_id,
                    'session' => $record->session,
                    'academic_year' => $record->enrollmentApplication?->academic_year,
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
                fn (User $record) => [
                    'id' => $record->id,
                    'name' => $this->fullName($record->first_name, $record->middle_name, $record->last_name)
                        ?: $record->name,
                    'first_name' => $record->first_name,
                    'middle_name' => $record->middle_name,
                    'last_name' => $record->last_name,
                    'email' => '',
                    'role' => $record->role?->name,
                    'act' => $record->is_active,
                    'phone' => $record->phone ?? '',
                    'sid' => null,
                    'avatar' => $record->avatar_path
                        ? asset('storage/'.$record->avatar_path)
                        : null,
                ]
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
                        'session' => $subject->session,
                        'semester' => $subject->semester,
                        'academic_year' => $subject->academic_year,

                        'y' => is_numeric($subject->year_level)
                            ? (int) $subject->year_level
                            : $subject->year_level,

                        'f' => $subject->faculty_id,
                    ],
                ]
            ),

            'students' => $studentRows,

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

    private function fullName(?string $first, ?string $middle, ?string $last): string
    {
        return implode(' ', array_filter(
            array_map(fn (?string $part): string => trim($part ?? ''), [$first, $middle, $last]),
            fn (string $part): bool => $part !== ''
        ));
    }
}
