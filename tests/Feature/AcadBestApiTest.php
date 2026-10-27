<?php

namespace Tests\Feature;

use App\Mail\PortalNotice;
use App\Models\EnrollmentApplication;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcadBestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_demo_account_authenticates_with_a_hashed_password(): void
    {
        $user = User::where('email', 'student@aces.edu.ph')->firstOrFail();

        $this->assertNotSame('AcadBest-Demo-2026!', $user->password);
        $this->postJson('/api/auth/login', [
            'email' => 'student@aces.edu.ph',
            'password' => 'AcadBest-Demo-2026!',
        ])->assertOk()->assertJsonPath('user.role', 'Student')->assertJsonStructure(['token'])
            ->assertJsonPath('user.first_name', 'Maria')
            ->assertJsonPath('user.middle_name', null)
            ->assertJsonPath('user.last_name', 'Santos')
            ->assertJsonPath('user.name', 'Maria Santos');

        $this->postJson('/api/auth/login', [
            'email' => 'student@aces.edu.ph',
            'password' => 'incorrect',
        ])->assertUnprocessable();
    }

    public function test_populated_name_parts_are_returned_for_every_user_role(): void
    {
        $accounts = [
            'student@aces.edu.ph',
            'faculty@aces.edu.ph',
            'registrar@aces.edu.ph',
            'admin@aces.edu.ph',
        ];
        $adminToken = $this->token('admin@aces.edu.ph');
        $userList = $this->withToken($adminToken)->getJson('/api/users')->assertOk();

        foreach ($accounts as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $expectedParts = [
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
            ];

            $this->withToken($this->token($email))->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('user.first_name', $expectedParts['first_name'])
                ->assertJsonPath('user.middle_name', $expectedParts['middle_name'])
                ->assertJsonPath('user.last_name', $expectedParts['last_name']);

            $listedUser = collect($userList->json('data'))->firstWhere('id', $user->id);
            $this->assertNotNull($listedUser);
            $this->assertSame($expectedParts['first_name'], $listedUser['first_name']);
            $this->assertSame($expectedParts['middle_name'], $listedUser['middle_name']);
            $this->assertSame($expectedParts['last_name'], $listedUser['last_name']);
        }
    }

    public function test_standing_rules_are_read_once_per_service_instance(): void
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'portal_settings')) {
                $queries++;
            }
        });

        $standing = app(StandingService::class);
        $first = $standing->rules();
        $second = $standing->rules();

        $this->assertSame($first, $second);
        $this->assertSame(1, $queries);
    }

    public function test_logout_revokes_token_and_protected_routes_reject_unauthenticated_requests(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => 'student@aces.edu.ph',
            'password' => 'AcadBest-Demo-2026!',
        ])->assertOk();
        $token = $login->json('token');

        $this->getJson('/api/portal')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/portal')->assertUnauthorized();
    }

    public function test_server_enforces_roles_and_enrollment_creates_real_student_records(): void
    {
        $application = $this->submitApplication();
        $facultyToken = $this->token('faculty@aces.edu.ph');
        $this->withToken($facultyToken)->postJson('/api/users', [])->assertForbidden();

        $registrarToken = $this->token('registrar@aces.edu.ph');
        $approval = $this->withToken($registrarToken)->putJson('/api/enrollments/'.$application->reference, [
            'status' => 'Enrolled',
        ])->assertOk()->assertJsonPath('status', 'Enrolled');
        $approvedSchoolId = $approval->json('school_id');
        $this->assertMatchesRegularExpression('/^\d{10}$/', $approvedSchoolId);
        $this->assertDatabaseHas('enrollment_applications', [
            'reference' => $application->reference, 'student_status' => 'New Student',
        ]);
        $enrolledStudent = Student::where('enrollment_application_id', $application->id)->firstOrFail();
        $this->assertSame($approvedSchoolId, $enrolledStudent->school_id);

        $adminToken = $this->token('admin@aces.edu.ph');
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $this->withToken($adminToken)->putJson('/api/users/'.$faculty->id, [
            'role' => 'Student',
            'program' => 'BSIT',
            'year_level' => 1,
        ])->assertUnprocessable();
        $this->withToken($adminToken)->postJson('/api/users', [
            'name' => $application->name,
            'email' => 'issued.student@aces.edu.ph',
            'role' => 'Student',
            'password' => 'Issued-Student-2026!',
            'application_ref' => $application->reference,
        ])->assertCreated()->assertJsonPath('role', 'Student')
            ->assertJsonPath('first_name', 'Test')
            ->assertJsonPath('middle_name', 'Marie')
            ->assertJsonPath('last_name', 'Student')
            ->assertJsonPath('school_id', $approvedSchoolId);

        $student = User::with('student')->where('email', 'issued.student@aces.edu.ph')->firstOrFail();
        $this->assertSame($approvedSchoolId, $student->student->school_id);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $student->student->school_id);
        $this->assertDatabaseHas('students', [
            'id' => $student->student->id,
            'school_id' => $student->student->school_id,
        ]);
        $this->assertSame(1, DB::table('students')->where('school_id', $student->student->school_id)->count());
        $this->assertSame('Marie', $student->middle_name);
        $this->assertSame('New Student', $student->student->status);
        $this->assertInstanceOf(Student::class, $student->student);
        $this->withToken($adminToken)->putJson('/api/users/'.$student->id, [
            'program' => 'BSED',
            'middle_name' => 'Marie Louise',
        ])->assertOk();
        $this->assertDatabaseHas('students', [
            'id' => $student->student->id,
            'program' => 'BSED',
            'year_level' => $application->year_level,
        ]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'middle_name' => 'Marie Louise']);
        $this->assertDatabaseHas('enrollment_applications', [
            'reference' => $application->reference,
            'academic_year' => $application->academic_year,
            'account_issued' => true,
            'user_id' => $student->id,
        ]);

        $studentToken = $this->token('issued.student@aces.edu.ph', 'Issued-Student-2026!');
        $this->withToken($studentToken)->getJson('/api/portal')
            ->assertOk()->assertJsonPath('students.0.name', 'Test Marie Louise Student')
            ->assertJsonPath('students.0.middle_name', 'Marie Louise')
            ->assertJsonPath('students.0.academic_year', $application->academic_year)
            ->assertJsonPath('students.0.school_id', $student->student->school_id)
            ->assertJsonPath('students.0.status', 'New Student');
        $this->withToken($studentToken)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.school_id', $student->student->school_id);
        $this->withToken($registrarToken)->getJson('/api/enrollments')
            ->assertOk()->assertJsonPath('data.0.ref', $application->reference)
            ->assertJsonPath('data.0.session', 'Weekends')
            ->assertJsonPath('data.0.middle_name', 'Marie')
            ->assertJsonPath('data.0.school_id', $student->student->school_id)
            ->assertJsonPath('data.0.student_status', 'New Student');
        $this->withToken($adminToken)->getJson('/api/users')
            ->assertOk()->assertJsonPath('total', 5)
            ->assertJsonFragment(['school_id' => $student->student->school_id]);
    }

    public function test_school_ids_are_not_created_for_pending_or_rejected_applications(): void
    {
        $application = $this->submitApplication();
        $registrarToken = $this->token('registrar@aces.edu.ph');
        $adminToken = $this->token('admin@aces.edu.ph');
        $initialStudentCount = DB::table('students')->count();
        $account = [
            'name' => $application->name,
            'email' => 'test.applicant@example.com',
            'role' => 'Student',
            'password' => 'Issued-Student-2026!',
            'application_ref' => $application->reference,
        ];

        $this->withToken($adminToken)->postJson('/api/users', $account)->assertUnprocessable();
        $this->assertSame($initialStudentCount, DB::table('students')->count());

        $this->withToken($registrarToken)->putJson('/api/enrollments/'.$application->reference, [
            'status' => 'Rejected',
        ])->assertOk();
        $this->withToken($adminToken)->postJson('/api/users', $account)->assertUnprocessable();
        $this->assertSame($initialStudentCount, DB::table('students')->count());
    }

    public function test_semester_and_major_are_saved_and_reenrollment_reuses_the_student_and_school_id(): void
    {
        $email = 'semester.student@example.com';
        $application = $this->submitApplication(null, [
            'email' => $email,
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'semester' => '1st Semester',
        ]);
        $registrarToken = $this->token('registrar@aces.edu.ph');

        $this->withToken($registrarToken)->getJson('/api/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.major', 'Financial Management')
            ->assertJsonPath('data.0.semester', '1st Semester');
        $firstApproval = $this->withToken($registrarToken)->putJson(
            '/api/enrollments/'.$application->reference,
            ['status' => 'Enrolled']
        )->assertOk();
        $schoolId = $firstApproval->json('school_id');
        $adminToken = $this->token('admin@aces.edu.ph');
        $this->withToken($adminToken)->postJson('/api/users', [
            'first_name' => $application->first_name,
            'middle_name' => $application->middle_name,
            'last_name' => $application->last_name,
            'email' => $email,
            'role' => 'Student',
            'password' => 'Semester-Student-2026!',
            'application_ref' => $application->reference,
        ])->assertCreated()->assertJsonPath('school_id', $schoolId);

        $this->withToken($adminToken)->putJson('/api/settings/academic-year', [
            'academic_year' => '2028-2029',
        ])->assertOk();
        $secondApplication = $this->submitApplication(null, [
            'email' => $email,
            'program' => 'BSBA',
            'major' => 'Human Resources Management',
            'semester' => '2nd Semester',
            'year_level' => 2,
        ]);
        $this->withToken($registrarToken)->putJson(
            '/api/enrollments/'.$secondApplication->reference,
            ['status' => 'Enrolled']
        )->assertOk()->assertJsonPath('school_id', $schoolId);

        $student = User::where('email', $email)->firstOrFail()->student;
        $this->assertSame($schoolId, $student->school_id);
        $this->assertSame('Human Resources Management', $student->major);
        $this->assertSame('2nd Semester', $student->semester);
        $this->assertSame(1, Student::where('user_id', $student->user_id)->count());
        $this->assertDatabaseHas('enrollment_applications', [
            'id' => $secondApplication->id,
            'student_id' => $student->id,
            'major' => 'Human Resources Management',
            'semester' => '2nd Semester',
            'academic_year' => '2028-2029',
        ]);
        $this->assertSame($schoolId, $application->fresh()->student->school_id);
        $this->withToken($this->token($email, 'Semester-Student-2026!'))->getJson('/api/portal')
            ->assertOk()->assertJsonPath('students.0.school_id', $schoolId)
            ->assertJsonPath('students.0.major', 'Human Resources Management')
            ->assertJsonPath('students.0.semester', '2nd Semester');
    }

    public function test_enrollment_requires_a_program_matching_major_and_a_supported_semester(): void
    {
        $payload = [
            'first_name' => 'Test',
            'last_name' => 'Applicant',
            'email' => 'major.validation@example.com',
            'phone' => '09175550123',
            'date_of_birth' => '2007-03-12',
            'sex' => 'Others',
            'address' => 'Tagum City',
            'program' => 'BSBA',
            'year_level' => 1,
            'semester' => '1st Semester',
            'application_type' => 'New Student',
            'previous_school' => 'Tagum National High School',
            'guardian_name' => 'Test Guardian',
            'guardian_phone' => '09175550124',
            'certified' => true,
        ];

        $this->postJson('/api/enrollments', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('major');
        $payload['major'] = 'Filipino';
        $this->postJson('/api/enrollments', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('major');
        $payload['major'] = 'Financial Management';
        $payload['semester'] = 'Summer';
        $this->postJson('/api/enrollments', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('semester');
    }

    public function test_student_account_creation_keeps_existing_student_and_historical_grades(): void
    {
        $application = $this->submitApplication();
        $this->withToken($this->token('registrar@aces.edu.ph'))->putJson(
            '/api/enrollments/'.$application->reference, ['status' => 'Enrolled']
        )->assertOk();

        $student = Student::where('enrollment_application_id', $application->id)->firstOrFail();
        Subject::create([
            'code' => 'IT777',
            'name' => 'Historical Systems',
            'units' => 3,
            'days' => 'Sat',
            'meeting_time' => '09:00-10:00',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => 'Lab 1',
            'program' => 'All',
            'year_level' => 'All',
            'session' => 'Weekends',
            'semester' => null,
            'academic_year' => null,
        ]);
        $student->subjects()->attach('IT777');
        Grade::create([
            'student_id' => $student->id,
            'subject_code' => 'IT777',
            'term' => 'Prelim',
            'score' => 88,
        ]);

        $this->withToken($this->token('admin@aces.edu.ph'))->postJson('/api/users', [
            'first_name' => $application->first_name,
            'middle_name' => $application->middle_name,
            'last_name' => $application->last_name,
            'email' => 'history.student@aces.edu.ph',
            'role' => 'Student',
            'password' => 'History-Student-2026!',
            'application_ref' => $application->reference,
        ])->assertCreated()->assertJsonPath('student_id', $student->id);

        $this->assertDatabaseHas('students', ['id' => $student->id, 'user_id' => User::where('email', 'history.student@aces.edu.ph')->value('id')]);
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id,
            'subject_code' => 'IT777',
            'term' => 'Prelim',
            'score' => 88,
        ]);

        $studentToken = $this->token('history.student@aces.edu.ph', 'History-Student-2026!');
        $this->withToken($studentToken)->getJson('/api/portal')
            ->assertOk()->assertJsonPath('students.0.id', $student->id)
            ->assertJsonPath('students.0.g.IT777.0', 88)
            ->assertJsonPath('subjects.IT777.semester', null);
    }

    public function test_faculty_grade_entry_is_saved_and_returned_to_the_student(): void
    {
        $application = $this->submitApplication();
        $this->withToken($this->token('registrar@aces.edu.ph'))->putJson(
            '/api/enrollments/'.$application->reference, ['status' => 'Enrolled']
        )->assertOk();
        $this->withToken($this->token('admin@aces.edu.ph'))->postJson('/api/users', [
            'name' => $application->name,
            'email' => 'grade.student@aces.edu.ph',
            'role' => 'Student',
            'password' => 'Grade-Student-2026!',
            'application_ref' => $application->reference,
        ])->assertCreated();

        $student = User::where('email', 'grade.student@aces.edu.ph')->firstOrFail()->student;
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $registrarToken = $this->token('registrar@aces.edu.ph');
        $facultyToken = $this->token('faculty@aces.edu.ph');
        $this->withToken($registrarToken)->postJson('/api/subjects', [
            'code' => 'IT301', 'name' => 'Integrative Programming', 'units' => 3,
            'days' => 'Mon/Wed', 'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'Lab 2',
            'program' => 'BSIT', 'year_level' => '1',
            'semester' => $application->semester, 'academic_year' => $application->academic_year,
        ])->assertCreated();
        $this->withToken($registrarToken)->putJson('/api/faculty/'.$faculty->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertNoContent();
        $this->withToken($registrarToken)->putJson('/api/students/'.$student->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertOk()->assertJsonPath('codes.0', 'IT301');

        foreach (['Prelim' => 85, 'Midterm' => 90, 'Final' => 95] as $term => $score) {
            $this->withToken($facultyToken)->putJson('/api/grades', [
                'subject_code' => 'IT301',
                'term' => $term,
                'grades' => [['student_id' => $student->id, 'score' => $score]],
            ])->assertOk()->assertJsonStructure(['students' => [(string) $student->id => [
                'gwa', 'status', 'declining',
            ]]]);
        }

        $this->assertSame(90.0, app(StandingService::class)->subjectAverage(
            Grade::where('student_id', $student->id)
                ->where('subject_code', 'IT301')->get()->keyBy('term')
        ));
        $this->withToken($facultyToken)->putJson('/api/grades', [
            'subject_code' => 'IT301', 'term' => 'Midterm',
            'grades' => [['student_id' => $student->id, 'score' => 100]],
        ])->assertOk();
        $this->assertSame(93.33, app(StandingService::class)->subjectAverage(
            Grade::where('student_id', $student->id)
                ->where('subject_code', 'IT301')->get()->keyBy('term')
        ));
        $this->assertSame(3, Grade::where('student_id', $student->id)
            ->where('subject_code', 'IT301')->count());
        $this->withToken($facultyToken)->putJson('/api/grades', [
            'subject_code' => 'IT301', 'term' => 'Quarter 1',
            'grades' => [['student_id' => $student->id, 'score' => 88]],
        ])->assertUnprocessable();
        $this->withToken($facultyToken)->putJson('/api/grades', [
            'subject_code' => 'IT301', 'term' => 'Final',
            'grades' => [['student_id' => $student->id]],
        ])->assertUnprocessable();
        $this->withToken($facultyToken)->putJson('/api/grades', [
            'subject_code' => 'IT301', 'term' => 'Final',
            'grades' => [['student_id' => $student->id, 'score' => 101]],
        ])->assertUnprocessable();
        $this->withToken($facultyToken)->putJson('/api/grades', [
            'subject_code' => 'IT301', 'term' => 'Midterm',
            'grades' => [['student_id' => $student->id, 'score' => 90]],
        ])->assertOk();

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_code' => 'IT301', 'term' => 'Prelim',
            'score' => 85,
        ]);
        $this->withToken($this->token('grade.student@aces.edu.ph', 'Grade-Student-2026!'))
            ->getJson('/api/portal')->assertOk()->assertJsonPath('students.0.g.IT301.0', 85)
            ->assertJsonPath('students.0.g.IT301.1', 90)
            ->assertJsonPath('students.0.g.IT301.2', 95)
            ->assertJsonPath('students.0.session', 'Weekends')
            ->assertJsonPath('subjects.IT301.session', 'All')
            ->assertJsonPath('subjects.IT301.semester', $application->semester)
            ->assertJsonPath('subjects.IT301.academic_year', '2026-2027')
            ->assertJsonMissingPath('students.0.gc')
            ->assertJsonMissingPath('subjects.IT301.cw');
    }

    public function test_student_requests_and_announcements_are_persisted_and_role_scoped(): void
    {
        $studentToken = $this->token('student@aces.edu.ph');
        $requestResponse = $this->withToken($studentToken)->postJson('/api/requests', [
            'type' => 'Certificate of Enrollment',
            'purpose' => 'Scholarship application',
            'copies' => 2,
            'date_needed' => now()->addDays(4)->toDateString(),
            'remarks' => '',
        ])->assertCreated();
        $reference = $requestResponse->json('reference');

        $registrarToken = $this->token('registrar@aces.edu.ph');
        $announcementResponse = $this->withToken($registrarToken)->postJson('/api/announcements', [
            'title' => 'Important enrollment notice',
            'message' => 'Enrollment services are available at the registrar office this week.',
            'priority' => 'Normal',
            'publish_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'program' => 'All',
            'year_level' => 'All',
            'section' => 'All',
        ])->assertCreated();
        $this->assertDatabaseHas('announcements', [
            'id' => $announcementResponse->json('id'),
            'title' => 'Important enrollment notice',
            'program' => 'All',
            'author_id' => User::where('email', 'registrar@aces.edu.ph')->value('id'),
        ]);
        $this->withToken($registrarToken)->postJson('/api/announcements', [
            'title' => 'STEM Grade 11 notice',
            'message' => 'This notice is for senior high school students.',
            'priority' => 'Normal',
            'publish_date' => now()->toDateString(),
            'program' => 'STEM',
            'year_level' => '11',
            'section' => 'All',
        ])->assertCreated();
        $this->withToken($registrarToken)->putJson('/api/requests/'.$reference, [
            'status' => 'Processing',
        ])->assertNoContent();

        $this->withToken($studentToken)->getJson('/api/requests')
            ->assertOk()->assertJsonPath('data.0.id', $reference)
            ->assertJsonPath('data.0.st', 'Processing')
            ->assertJsonPath('data.0.first_name', 'Maria')
            ->assertJsonPath('data.0.last_name', 'Santos')
            ->assertJsonPath('total', 1);
        $this->withToken($registrarToken)->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Maria')
            ->assertJsonPath('data.0.middle_name', null)
            ->assertJsonPath('data.0.last_name', 'Santos');
        $this->withToken($this->token('faculty@aces.edu.ph'))->getJson('/api/requests')->assertForbidden();

        $this->withToken($studentToken)->getJson('/api/portal')
            ->assertOk()->assertJsonMissingPath('requests')
            ->assertJsonPath('announcements.0.t', 'Important enrollment notice');

        $this->withToken($registrarToken)->putJson('/api/requests/'.$reference, [
            'status' => 'Completed',
        ])->assertUnprocessable();
        $this->withToken($registrarToken)->putJson('/api/requests/'.$reference, [
            'status' => 'Ready for pick-up',
        ])->assertNoContent();
        $this->withToken($registrarToken)->putJson('/api/requests/'.$reference, [
            'status' => 'Completed',
        ])->assertNoContent();
    }

    public function test_admin_can_assign_an_image_to_an_account(): void
    {
        Storage::fake('public');
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+c2ioAAAAASUVORK5CYII=';

        $response = $this->withToken($this->token('admin@aces.edu.ph'))->putJson('/api/users/'.$faculty->id, [
            'avatar_data' => $png,
        ])->assertOk();
        $this->assertStringContainsString('/storage/avatars/', $response->json('avatar'));

        $this->assertCount(1, Storage::disk('public')->allFiles('avatars'));
    }

    public function test_registrar_can_create_a_unique_subject_and_validation_errors_are_reported(): void
    {
        $token = $this->token('registrar@aces.edu.ph');
        $subject = [
            'code' => ' it305 ',
            'name' => 'Applied Database Systems',
            'units' => 3,
            'days' => 'Mon/Wed',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'room' => 'Lab 2',
            'program' => 'BSIT',
            'year_level' => '3',
            'semester' => '1st Semester',
            'academic_year' => '2026-2027',
        ];
        $created = $this->withToken($token)->postJson('/api/subjects', $subject)
            ->assertCreated()->assertJsonPath('code', 'IT305')
            ->assertJsonPath('start_time', '08:00')->assertJsonPath('end_time', '09:30')
            ->assertJsonPath('program', 'BSIT')
            ->assertJsonPath('grade_weights', [])
            ->assertJsonPath('semester', '1st Semester')
            ->assertJsonPath('academic_year', '2026-2027')
            ->assertJsonPath('session', 'All');
        $this->assertDatabaseHas('subjects', [
            'code' => 'IT305', 'name' => 'Applied Database Systems',
        ]);
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'AB101',
            'program' => 'ABSTM',
            'room' => 'Room ABSTM',
            'days' => 'Tue/Thu',
            'academic_year' => null,
        ]))->assertCreated()->assertJsonPath('program', 'ABSTM');
        $this->withToken($token)->postJson('/api/subjects', $subject)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', ['code' => 'BAD'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT307', 'semester' => 'Unspecified',
        ]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT308', 'days' => 'Mon/Funday',
        ]))->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT309', 'days' => 'Mon/Tue/Wed/Thu/Fri/Sat/Sun',
            'start_time' => '11:00', 'end_time' => '12:00',
        ]))->assertCreated()->assertJsonPath('days', 'Mon/Tue/Wed/Thu/Fri/Sat/Sun');
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT310', 'days' => 'Wed/Sun',
        ]))->assertUnprocessable()->assertJsonValidationErrors('room');
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT306', 'start_time' => '10:00', 'end_time' => '09:00',
        ]))->assertUnprocessable();
        Subject::where('code', 'IT305')->update(['session' => 'Weekends']);
        $this->withToken($token)->putJson('/api/subjects/IT305', [
            'start_time' => '09:00',
            'end_time' => '10:30',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'session' => 'Invalid legacy input',
        ])->assertOk()->assertJsonPath('start_time', '09:00')->assertJsonPath('end_time', '10:30')
            ->assertJsonPath('program', 'BSBA')->assertJsonPath('major', 'Financial Management')
            ->assertJsonPath('session', 'Weekends');
        $this->assertDatabaseHas('subjects', [
            'code' => $created->json('code'), 'program' => 'BSBA', 'major' => 'Financial Management',
            'session' => 'Weekends',
        ]);
        $this->withToken($token)->putJson('/api/subjects/IT305', ['program' => 'UNKNOWN'])
            ->assertUnprocessable()->assertJsonValidationErrors('program');
    }

    public function test_subject_major_is_saved_and_must_match_its_program(): void
    {
        $token = $this->token('registrar@aces.edu.ph');
        $subject = [
            'code' => 'BA201',
            'name' => 'Financial Accounting',
            'units' => 3,
            'days' => 'Mon/Wed',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'room' => 'Room 1',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'year_level' => '2',
        ];

        $this->withToken($token)->postJson('/api/subjects', $subject)
            ->assertCreated()->assertJsonPath('major', 'Financial Management');
        $this->assertDatabaseHas('subjects', [
            'code' => 'BA201',
            'program' => 'BSBA',
            'major' => 'Financial Management',
        ]);
        $this->withToken($token)->putJson('/api/subjects/BA201', [
            'major' => 'Filipino',
        ])->assertUnprocessable()->assertJsonValidationErrors('major');
    }

    public function test_faculty_subject_assignment_has_no_unit_limit(): void
    {
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $codes = [];
        foreach (range(1, 9) as $index) {
            $code = sprintf('TL%03d', $index);
            $start = now()->setTime(8 + $index - 1, 0)->format('H:i');
            $end = now()->setTime(8 + $index - 1, 30)->format('H:i');
            Subject::create([
                'code' => $code,
                'name' => 'Teaching Load '.$index,
                'units' => 3,
                'days' => 'Mon/Wed',
                'meeting_time' => $start.'-'.$end,
                'start_time' => $start,
                'end_time' => $end,
                'room' => 'Room '.$index,
                'program' => 'All',
                'year_level' => 'All',
                'session' => 'All',
                'semester' => null,
                'academic_year' => null,
            ]);
            $codes[] = $code;
        }

        $this->withToken($this->token('registrar@aces.edu.ph'))
            ->putJson('/api/faculty/'.$faculty->id.'/subjects', ['codes' => $codes])
            ->assertNoContent();
        $this->assertSame(9, $faculty->subjects()->count());
        $this->assertSame(27, Subject::whereIn('code', $codes)->sum('units'));
    }

    public function test_faculty_load_conflicts_are_checked_within_the_same_offering_term(): void
    {
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        foreach (['FC101', 'FC102'] as $code) {
            Subject::create([
                'code' => $code,
                'name' => 'Faculty Conflict '.$code,
                'units' => 3,
                'days' => 'Mon/Wed',
                'meeting_time' => '08:00-09:00',
                'start_time' => '08:00',
                'end_time' => '09:00',
                'room' => 'Room '.$code,
                'program' => 'All',
                'year_level' => 'All',
                'session' => 'All',
                'semester' => '1st Semester',
                'academic_year' => '2026-2027',
            ]);
        }

        $token = $this->token('registrar@aces.edu.ph');
        $this->withToken($token)->putJson('/api/faculty/'.$faculty->id.'/subjects', [
            'codes' => ['FC101', 'FC102'],
        ])->assertUnprocessable()->assertJsonValidationErrors('codes');

        Subject::where('code', 'FC102')->update(['semester' => '2nd Semester']);
        $this->withToken($token)->putJson('/api/faculty/'.$faculty->id.'/subjects', [
            'codes' => ['FC101', 'FC102'],
        ])->assertNoContent();
    }

    public function test_a_subject_can_be_assigned_to_multiple_faculty_without_transferring_assignments(): void
    {
        $facultyOne = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $facultyTwo = User::factory()->create([
            'role_id' => $facultyOne->role_id,
            'password' => 'Faculty-Test-2026!',
        ]);
        $registrarToken = $this->token('registrar@aces.edu.ph');
        Subject::create([
            'code' => 'IT401',
            'name' => 'Concurrent Teaching',
            'units' => 3,
            'days' => 'Mon/Wed',
            'meeting_time' => '08:00-09:00',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'room' => 'Room 1',
            'program' => 'All',
            'year_level' => 'All',
        ]);

        $this->withToken($registrarToken)
            ->putJson('/api/faculty/'.$facultyOne->id.'/subjects', ['codes' => ['IT401']])
            ->assertNoContent();
        $this->withToken($registrarToken)
            ->putJson('/api/faculty/'.$facultyTwo->id.'/subjects', ['codes' => ['IT401']])
            ->assertNoContent();
        $this->withToken($registrarToken)
            ->putJson('/api/faculty/'.$facultyTwo->id.'/subjects', ['codes' => ['IT401']])
            ->assertNoContent();

        $this->assertDatabaseCount('faculty_subject', 2);
        $this->assertEqualsCanonicalizing(
            [$facultyOne->id, $facultyTwo->id],
            DB::table('faculty_subject')->where('subject_code', 'IT401')->pluck('faculty_id')->all()
        );
        $facultyPortal = $this->withToken($this->token($facultyTwo->email, 'Faculty-Test-2026!'))
            ->getJson('/api/portal')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$facultyOne->id, $facultyTwo->id],
            $facultyPortal->json('subjects.IT401.faculty_ids')
        );

        $this->withToken($registrarToken)->putJson('/api/subjects/IT401', [
            'name' => 'Concurrent Teaching Updated',
        ])->assertOk();
        $this->assertDatabaseCount('faculty_subject', 2);

        $this->withToken($registrarToken)
            ->putJson('/api/faculty/'.$facultyOne->id.'/subjects', ['codes' => []])
            ->assertNoContent();
        $this->assertDatabaseHas('faculty_subject', [
            'faculty_id' => $facultyTwo->id,
            'subject_code' => 'IT401',
        ]);
        $this->assertDatabaseMissing('faculty_subject', [
            'faculty_id' => $facultyOne->id,
            'subject_code' => 'IT401',
        ]);
        $this->assertSame(['IT401'], $facultyTwo->subjects()->pluck('subjects.code')->all());
    }

    public function test_faculty_assignment_migration_preserves_existing_subject_assignments(): void
    {
        $faculty = User::where('email', 'faculty@aces.edu.ph')->firstOrFail();
        $migration = require database_path('migrations/2026_10_27_000000_create_faculty_subject_table.php');
        $migration->down();

        Subject::create([
            'code' => 'IT402',
            'name' => 'Legacy Faculty Assignment',
            'units' => 3,
            'days' => 'Tue/Thu',
            'meeting_time' => '09:00-10:00',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => 'Room 2',
            'program' => 'All',
            'year_level' => 'All',
        ]);
        DB::table('subjects')->where('code', 'IT402')->update(['faculty_id' => $faculty->id]);
        $this->assertDatabaseHas('subjects', ['code' => 'IT402', 'faculty_id' => $faculty->id]);

        $migration->up();

        $this->assertDatabaseHas('faculty_subject', [
            'faculty_id' => $faculty->id,
            'subject_code' => 'IT402',
        ]);
        $this->assertFalse(Schema::hasColumn('subjects', 'faculty_id'));
    }

    public function test_student_subject_assignment_requires_a_matching_enrolled_application(): void
    {
        $application = $this->submitApplication(null, [
            'email' => 'load.student@example.com',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'session' => 'Weekends',
        ]);
        $registrarToken = $this->token('registrar@aces.edu.ph');
        $this->withToken($registrarToken)->putJson('/api/enrollments/'.$application->reference, [
            'status' => 'Enrolled',
        ])->assertOk();
        $student = Student::where('enrollment_application_id', $application->id)->firstOrFail();
        Subject::create([
            'code' => 'BA301',
            'name' => 'Finance Fundamentals',
            'units' => 3,
            'days' => 'Sat',
            'meeting_time' => '09:00-10:00',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => 'Room 1',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'year_level' => '1',
            'session' => 'Weekends',
            'semester' => '1st Semester',
            'academic_year' => $application->academic_year,
        ]);
        Subject::create([
            'code' => 'BA302',
            'name' => 'Human Resources',
            'units' => 3,
            'days' => 'Sun',
            'meeting_time' => '09:00-10:00',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room' => 'Room 2',
            'program' => 'BSBA',
            'major' => 'Human Resources Management',
            'year_level' => '1',
            'session' => 'Weekends',
            'semester' => '1st Semester',
            'academic_year' => $application->academic_year,
        ]);
        Subject::create([
            'code' => 'BA303',
            'name' => 'Prior Term Finance',
            'units' => 3,
            'days' => 'Sun',
            'meeting_time' => '11:00-12:00',
            'start_time' => '11:00',
            'end_time' => '12:00',
            'room' => 'Room 3',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'year_level' => '1',
            'session' => 'Weekends',
            'semester' => '2nd Semester',
            'academic_year' => $application->academic_year,
        ]);
        $student->subjects()->attach('BA303');

        $assigned = $this->withToken($registrarToken)->putJson('/api/students/'.$student->id.'/subjects', [
            'codes' => ['BA301'],
        ])->assertOk();
        $this->assertEqualsCanonicalizing(['BA301', 'BA303'], $assigned->json('codes'));
        $this->assertDatabaseHas('student_subject', [
            'student_id' => $student->id,
            'subject_code' => 'BA303',
        ]);
        $this->withToken($registrarToken)->putJson('/api/students/'.$student->id.'/subjects', [
            'codes' => ['BA302'],
        ])->assertUnprocessable()->assertJsonValidationErrors('codes');

        $pending = Student::create([
            'school_id' => '2026000999',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'year_level' => 1,
            'section' => 'A',
            'status' => 'Applicant',
        ]);
        $this->withToken($registrarToken)->putJson('/api/students/'.$pending->id.'/subjects', [
            'codes' => ['BA301'],
        ])->assertUnprocessable()->assertJsonValidationErrors('student');
    }

    public function test_staff_portal_lists_only_students_with_enrolled_applications(): void
    {
        $unlinked = Student::create([
            'school_id' => '2026000998',
            'program' => 'BSIT',
            'year_level' => 1,
            'section' => 'A',
            'status' => 'Applicant',
        ]);

        $response = $this->withToken($this->token('registrar@aces.edu.ph'))->getJson('/api/portal')
            ->assertOk();
        $this->assertSame([], $response->json('students'));
    }

    public function test_enrollment_validates_gender_and_program_and_saves_middle_name(): void
    {
        $payload = [
            'first_name' => 'Another Test',
            'middle_name' => 'Lee',
            'last_name' => 'Student',
            'email' => 'another.applicant@example.com',
            'phone' => '09175550123',
            'date_of_birth' => '2007-03-12',
            'sex' => 'Others',
            'address' => 'Tagum City',
            'program' => 'BSBA',
            'major' => 'Financial Management',
            'year_level' => 1,
            'session' => 'Weekends',
            'semester' => '1st Semester',
            'application_type' => 'New Student',
            'previous_school' => 'Tagum National High School',
            'guardian_name' => 'Test Guardian',
            'guardian_phone' => '09175550124',
            'certified' => true,
        ];
        $this->postJson('/api/enrollments', array_merge($payload, ['sex' => 'Unknown']))
            ->assertUnprocessable();
        $this->postJson('/api/enrollments', array_merge($payload, ['program' => 'UNKNOWN']))
            ->assertUnprocessable();

        $response = $this->postJson('/api/enrollments', $payload)->assertCreated();
        $application = EnrollmentApplication::where('reference', $response->json('reference'))->firstOrFail();
        $this->assertSame('Another Test', $application->first_name);
        $this->assertSame('Lee', $application->middle_name);
        $this->assertSame('Student', $application->last_name);
        $this->assertNotEmpty($application->academic_year);
        $this->assertSame('Others', $application->sex);
        $this->assertSame('BSBA', $application->program);

        foreach ([
            ['STEM', 11, 'stem.applicant@example.com'],
            ['TVL', 12, 'tvl.applicant@example.com'],
        ] as [$program, $yearLevel, $email]) {
            $response = $this->postJson('/api/enrollments', array_merge($payload, [
                'first_name' => 'Senior High',
                'email' => $email,
                'program' => $program,
                'major' => $program === 'TVL' ? 'ICT – Computer Servicing' : null,
                'year_level' => $yearLevel,
            ]))->assertCreated();
            $this->assertDatabaseHas('enrollment_applications', [
                'reference' => $response->json('reference'),
                'program' => $program,
                'year_level' => $yearLevel,
            ]);
        }
    }

    public function test_admin_configured_academic_year_is_suggested_and_saved_on_enrollment(): void
    {
        $standingRules = DB::table('portal_settings')->where('key', 'standing_rules')->value('value');
        $adminToken = $this->token('admin@aces.edu.ph');
        $this->withToken($adminToken)->putJson('/api/settings/academic-year', [
            'academic_year' => '2027-2028',
        ])->assertOk()->assertJsonPath('academic_year', '2027-2028');
        $this->assertSame(
            $standingRules,
            DB::table('portal_settings')->where('key', 'standing_rules')->value('value')
        );
        $this->getJson('/api/settings/academic-year')
            ->assertOk()->assertJsonPath('academic_year', '2027-2028');
        $this->withToken($this->token('registrar@aces.edu.ph'))
            ->putJson('/api/settings/academic-year', ['academic_year' => '2028-2029'])
            ->assertForbidden();

        $application = $this->submitApplication('2028-2029');
        $this->assertSame('2027-2028', $application->academic_year);
        $this->withToken($this->token('registrar@aces.edu.ph'))->getJson('/api/enrollments')
            ->assertOk()->assertJsonPath('data.0.academic_year', '2027-2028');
        $this->postJson('/api/enrollments/track', [
            'reference' => $application->reference,
            'email' => $application->email,
        ])->assertOk()->assertJsonPath('academic_year', '2027-2028');
    }

    public function test_name_fields_are_preserved_through_enrollment_approval_and_account_creation(): void
    {
        $this->withToken($this->token('admin@aces.edu.ph'))->putJson('/api/settings/academic-year', [
            'academic_year' => '2028-2029',
        ])->assertOk();
        $response = $this->postJson('/api/enrollments', [
            'first_name' => 'Asthon Lloyd',
            'middle_name' => 'Barbante',
            'last_name' => 'Quisquisol',
            'email' => 'multiword.applicant@example.com',
            'phone' => '09175550123',
            'date_of_birth' => '2007-03-12',
            'sex' => 'Others',
            'address' => 'Tagum City',
            'program' => 'BSIT',
            'year_level' => 1,
            'session' => 'Weekends',
            'semester' => '1st Semester',
            'academic_year' => '2029-2030',
            'application_type' => 'New Student',
            'previous_school' => 'Tagum National High School',
            'guardian_name' => 'Test Guardian',
            'guardian_phone' => '09175550124',
            'certified' => true,
        ])->assertCreated();
        $reference = $response->json('reference');
        $application = EnrollmentApplication::where('reference', $reference)->firstOrFail();

        $this->assertSame('Asthon Lloyd', $application->first_name);
        $this->assertSame('Barbante', $application->middle_name);
        $this->assertSame('Quisquisol', $application->last_name);
        $this->assertSame('2028-2029', $application->academic_year);
        $this->assertSame('Asthon Lloyd Barbante Quisquisol', $application->name);

        $this->postJson('/api/enrollments/track', [
            'reference' => $reference,
            'email' => 'multiword.applicant@example.com',
        ])->assertOk()
            ->assertJsonPath('first_name', 'Asthon Lloyd')
            ->assertJsonPath('middle_name', 'Barbante')
            ->assertJsonPath('last_name', 'Quisquisol')
            ->assertJsonPath('academic_year', '2028-2029')
            ->assertJsonMissingPath('name_parts_verified');

        $registrarToken = $this->token('registrar@aces.edu.ph');
        $this->withToken($registrarToken)->getJson('/api/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Asthon Lloyd')
            ->assertJsonPath('data.0.middle_name', 'Barbante')
            ->assertJsonPath('data.0.last_name', 'Quisquisol')
            ->assertJsonPath('data.0.academic_year', '2028-2029')
            ->assertJsonMissingPath('data.0.name_parts_verified');
        $this->withToken($registrarToken)->putJson('/api/enrollments/'.$reference, [
            'status' => 'Enrolled',
        ])->assertOk();

        $adminToken = $this->token('admin@aces.edu.ph');
        $this->withToken($adminToken)->getJson('/api/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Asthon Lloyd')
            ->assertJsonPath('data.0.middle_name', 'Barbante')
            ->assertJsonPath('data.0.last_name', 'Quisquisol')
            ->assertJsonMissingPath('data.0.name_parts_verified');
        $this->withToken($adminToken)->postJson('/api/users', [
            'first_name' => 'Incorrect First',
            'middle_name' => 'Incorrect Middle',
            'last_name' => 'Incorrect Last',
            'email' => 'multiword.student@example.com',
            'role' => 'Student',
            'password' => 'Issued-Student-2026!',
            'application_ref' => $reference,
        ])->assertCreated()
            ->assertJsonPath('first_name', 'Asthon Lloyd')
            ->assertJsonPath('middle_name', 'Barbante')
            ->assertJsonPath('last_name', 'Quisquisol')
            ->assertJsonPath('name', 'Asthon Lloyd Barbante Quisquisol');

        $this->assertDatabaseHas('users', [
            'email' => 'multiword.student@example.com',
            'first_name' => 'Asthon Lloyd',
            'middle_name' => 'Barbante',
            'last_name' => 'Quisquisol',
        ]);
    }

    public function test_legacy_user_names_are_recovered_for_every_role_profile(): void
    {
        $accounts = [
            'student@aces.edu.ph' => 'Student',
            'faculty@aces.edu.ph' => 'Faculty',
            'registrar@aces.edu.ph' => 'Registrar Staff',
            'admin@aces.edu.ph' => 'Admin',
        ];

        foreach ($accounts as $email => $role) {
            User::where('email', $email)->firstOrFail()->forceFill([
                'name' => 'Test Middle Person',
                'first_name' => null,
                'middle_name' => 'Middle',
                'last_name' => null,
            ])->save();

            $this->withToken($this->token($email))->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('user.role', $role)
                ->assertJsonPath('user.name', 'Test Middle Person')
                ->assertJsonPath('user.first_name', 'Test')
                ->assertJsonPath('user.middle_name', 'Middle')
                ->assertJsonPath('user.last_name', 'Person');
        }

        $this->withToken($this->token('admin@aces.edu.ph'))->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('data.0.first_name', 'Test');
    }

    public function test_legacy_enrollment_names_are_recovered_for_admin_and_registrar_views(): void
    {
        $application = $this->submitApplication();
        DB::table('enrollment_applications')->where('id', $application->id)->update([
            'name' => 'Test Marie Student',
            'first_name' => null,
            'middle_name' => 'Marie',
            'last_name' => null,
        ]);

        foreach (['registrar@aces.edu.ph', 'admin@aces.edu.ph'] as $email) {
            $this->withToken($this->token($email))->getJson('/api/enrollments')
                ->assertOk()
                ->assertJsonPath('data.0.name', 'Test Marie Student')
                ->assertJsonPath('data.0.first_name', 'Test')
                ->assertJsonPath('data.0.middle_name', 'Marie')
                ->assertJsonPath('data.0.last_name', 'Student');
        }

        $this->postJson('/api/enrollments/track', [
            'reference' => $application->reference,
            'email' => $application->email,
        ])->assertOk()
            ->assertJsonPath('first_name', 'Test')
            ->assertJsonPath('middle_name', 'Marie')
            ->assertJsonPath('last_name', 'Student');

        $this->withToken($this->token('registrar@aces.edu.ph'))
            ->putJson('/api/enrollments/'.$application->reference, ['status' => 'Enrolled'])
            ->assertOk();
        $this->withToken($this->token('admin@aces.edu.ph'))
            ->postJson('/api/users', [
                'email' => 'recovered.student@example.com',
                'role' => 'Student',
                'password' => 'Recovered-Student-2026!',
                'application_ref' => $application->reference,
            ])->assertCreated()
            ->assertJsonPath('name', 'Test Marie Student')
            ->assertJsonPath('first_name', 'Test')
            ->assertJsonPath('middle_name', 'Marie')
            ->assertJsonPath('last_name', 'Student');
    }

    public function test_legacy_name_parts_are_returned_and_used_without_reconstruction(): void
    {
        $application = $this->submitApplication();
        $application->update([
            'name' => 'Asthon Lloyd Barbante Quisquisol',
            'first_name' => 'Asthon',
            'middle_name' => 'Barbante',
            'last_name' => 'Lloyd Barbante Quisquisol',
        ]);

        $registrarToken = $this->token('registrar@aces.edu.ph');
        $this->withToken($registrarToken)->getJson('/api/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Asthon Lloyd Barbante Quisquisol')
            ->assertJsonPath('data.0.first_name', 'Asthon')
            ->assertJsonPath('data.0.middle_name', 'Barbante')
            ->assertJsonPath('data.0.last_name', 'Lloyd Barbante Quisquisol')
            ->assertJsonMissingPath('data.0.name_parts_verified');
        $this->postJson('/api/enrollments/track', [
            'reference' => $application->reference,
            'email' => $application->email,
        ])->assertOk()
            ->assertJsonPath('first_name', 'Asthon')
            ->assertJsonPath('middle_name', 'Barbante')
            ->assertJsonPath('last_name', 'Lloyd Barbante Quisquisol')
            ->assertJsonMissingPath('name_parts_verified');

        $this->withToken($registrarToken)->putJson('/api/enrollments/'.$application->reference, [
            'status' => 'Enrolled',
        ])->assertOk();
        $this->withToken($this->token('admin@aces.edu.ph'))->postJson('/api/users', [
            'first_name' => 'Asthon',
            'middle_name' => 'Barbante',
            'last_name' => 'Lloyd Barbante Quisquisol',
            'email' => 'unverified.student@example.com',
            'role' => 'Student',
            'password' => 'Issued-Student-2026!',
            'application_ref' => $application->reference,
        ])->assertCreated()
            ->assertJsonPath('first_name', 'Asthon')
            ->assertJsonPath('middle_name', 'Barbante')
            ->assertJsonPath('last_name', 'Lloyd Barbante Quisquisol');

        $this->assertDatabaseHas('enrollment_applications', [
            'id' => $application->id,
            'first_name' => 'Asthon',
            'last_name' => 'Lloyd Barbante Quisquisol',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'unverified.student@example.com',
            'first_name' => 'Asthon',
            'middle_name' => 'Barbante',
            'last_name' => 'Lloyd Barbante Quisquisol',
        ]);
    }

    public function test_enrollment_does_not_infer_name_parts_from_a_combined_name(): void
    {
        $this->postJson('/api/enrollments', [
            'name' => 'Asthon Lloyd Barbante Quisquisol',
            'middle_name' => 'Barbante',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'first_name',
            'last_name',
        ]);
    }

    public function test_student_can_mark_all_notifications_read_in_one_request(): void
    {
        $student = User::where('email', 'student@aces.edu.ph')->firstOrFail()->student;
        foreach (['First', 'Second'] as $title) {
            DB::table('portal_notifications')->insert([
                'student_id' => $student->id, 'type' => 'alert', 'title' => $title,
                'message' => 'Test notification', 'is_read' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->withToken($this->token('student@aces.edu.ph'))
            ->putJson('/api/notifications/read-all')->assertNoContent();
        $this->assertSame(0, DB::table('portal_notifications')
            ->where('student_id', $student->id)->where('is_read', false)->count());
    }

    private function submitApplication(?string $academicYear = null, array $overrides = []): EnrollmentApplication
    {
        $payload = [
            'first_name' => 'Test',
            'middle_name' => 'Marie',
            'last_name' => 'Student',
            'email' => 'test.applicant@example.com',
            'phone' => '09175550123',
            'date_of_birth' => '2007-03-12',
            'sex' => 'Others',
            'address' => 'Tagum City',
            'program' => 'BSIT',
            'year_level' => 1,
            'session' => 'Weekends',
            'semester' => '1st Semester',
            'application_type' => 'New Student',
            'previous_school' => 'Tagum National High School',
            'guardian_name' => 'Test Guardian',
            'guardian_phone' => '09175550124',
            'certified' => true,
        ];
        if ($academicYear !== null) {
            $payload['academic_year'] = $academicYear;
        }
        $payload = array_merge($payload, $overrides);
        $response = $this->postJson('/api/enrollments', $payload)->assertCreated();

        $reference = $response->json('reference');
        $this->postJson('/api/enrollments/track', [
            'reference' => $reference,
            'email' => $payload['email'],
        ])->assertOk()->assertJsonPath('st', 'Pending')->assertJsonPath('middle_name', 'Marie');

        return EnrollmentApplication::where('reference', $reference)->firstOrFail();
    }

    public function test_announcement_emails_are_recorded_and_delivered(): void
    {
        Mail::fake();
        $registrar = $this->token('registrar@aces.edu.ph');

        $id = $this->withToken($registrar)->postJson('/api/announcements', [
            'title' => 'Records reminder',
            'message' => 'Please settle your records before Friday.',
            'priority' => 'Normal',
            'publish_date' => now()->toDateString(),
            'program' => 'BSIT', 'year_level' => '3', 'section' => 'A',
        ])->assertCreated()
            ->assertJsonPath('targeted', 1)
            ->assertJsonPath('email_queued', 1)
            ->json('id');

        Mail::assertSent(
            PortalNotice::class,
            fn ($mail) => $mail->hasTo('student@aces.edu.ph')
        );

        $this->withToken($registrar)->getJson("/api/announcements/{$id}/delivery")
            ->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('failed', 0);
    }

    public function test_failed_email_is_reported_instead_of_hidden(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $registrar = $this->token('registrar@aces.edu.ph');

        $id = $this->withToken($registrar)->postJson('/api/announcements', [
            'title' => 'Records reminder',
            'message' => 'Please settle your records before Friday.',
            'priority' => 'Normal',
            'publish_date' => now()->toDateString(),
            'program' => 'BSIT', 'year_level' => '3', 'section' => 'A',
        ])->assertCreated()->json('id');

        $this->withToken($registrar)->getJson("/api/announcements/{$id}/delivery")
            ->assertOk()
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('sent', 0)
            ->assertJsonPath('failed_recipients.0.error', 'SMTP down');
    }

    private function token(string $email, string $password = 'AcadBest-Demo-2026!'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()->json('token');
    }
}
