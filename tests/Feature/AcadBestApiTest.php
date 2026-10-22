<?php

namespace Tests\Feature;

use App\Models\EnrollmentApplication;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\StandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'program' => 'BSIT', 'year_level' => '3',
            'session' => 'Weekends', 'semester' => '2nd Semester', 'academic_year' => '2026-2027',
        ])->assertCreated();
        $this->withToken($registrarToken)->putJson('/api/faculty/'.$faculty->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertNoContent();
        $this->withToken($registrarToken)->putJson('/api/students/'.$student->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertNoContent();

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
            ->assertJsonPath('subjects.IT301.session', 'Weekends')
            ->assertJsonPath('subjects.IT301.semester', '2nd Semester')
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
        $this->withToken($registrarToken)->putJson('/api/requests/'.$reference, [
            'status' => 'Processing',
        ])->assertNoContent();

        $this->withToken($studentToken)->getJson('/api/requests')
            ->assertOk()->assertJsonPath('data.0.id', $reference)
            ->assertJsonPath('data.0.st', 'Processing')->assertJsonPath('total', 1);
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
        ];
        $created = $this->withToken($token)->postJson('/api/subjects', $subject)
            ->assertCreated()->assertJsonPath('code', 'IT305')
            ->assertJsonPath('start_time', '08:00')->assertJsonPath('end_time', '09:30')
            ->assertJsonPath('semester', null);
        $this->assertDatabaseHas('subjects', [
            'code' => 'IT305', 'name' => 'Applied Database Systems',
        ]);
        $this->withToken($token)->postJson('/api/subjects', $subject)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', ['code' => 'BAD'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT307', 'semester' => 'Unspecified',
        ]))->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', array_merge($subject, [
            'code' => 'IT306', 'start_time' => '10:00', 'end_time' => '09:00',
        ]))->assertUnprocessable();
        $this->withToken($token)->putJson('/api/subjects/IT305', [
            'start_time' => '09:00',
            'end_time' => '10:30',
        ])->assertOk()->assertJsonPath('start_time', '09:00')->assertJsonPath('end_time', '10:30');
        $this->assertDatabaseHas('subjects', ['code' => $created->json('code')]);
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
            'year_level' => 1,
            'session' => 'Weekends',
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
    }

    public function test_admin_configured_academic_year_is_suggested_and_saved_on_enrollment(): void
    {
        $adminToken = $this->token('admin@aces.edu.ph');
        $this->withToken($adminToken)->putJson('/api/settings/academic-year', [
            'academic_year' => '2027-2028',
        ])->assertOk()->assertJsonPath('academic_year', '2027-2028');
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

    public function test_legacy_name_parts_are_available_when_verification_flag_is_false(): void
    {
        $application = $this->submitApplication();
        DB::table('enrollment_applications')
            ->where('id', $application->id)
            ->update(['name_parts_verified' => false]);

        $this->postJson('/api/enrollments/track', [
            'reference' => $application->reference,
            'email' => $application->email,
        ])->assertOk()
            ->assertJsonPath('name', 'Test Marie Student')
            ->assertJsonPath('first_name', 'Test')
            ->assertJsonPath('middle_name', 'Marie')
            ->assertJsonPath('last_name', 'Student')
            ->assertJsonMissingPath('name_parts_verified');
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

    private function submitApplication(?string $academicYear = null): EnrollmentApplication
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
            'application_type' => 'New Student',
            'previous_school' => 'Tagum National High School',
            'guardian_name' => 'Test Guardian',
            'guardian_phone' => '09175550124',
            'certified' => true,
        ];
        if ($academicYear !== null) {
            $payload['academic_year'] = $academicYear;
        }
        $response = $this->postJson('/api/enrollments', $payload)->assertCreated();

        $reference = $response->json('reference');
        $this->postJson('/api/enrollments/track', [
            'reference' => $reference,
            'email' => 'test.applicant@example.com',
        ])->assertOk()->assertJsonPath('st', 'Pending')->assertJsonPath('middle_name', 'Marie');

        return EnrollmentApplication::where('reference', $reference)->firstOrFail();
    }

    private function token(string $email, string $password = 'AcadBest-Demo-2026!'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()->json('token');
    }
}
