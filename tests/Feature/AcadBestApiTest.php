<?php

namespace Tests\Feature;

use App\Models\EnrollmentApplication;
use App\Models\Student;
use App\Models\User;
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
        ])->assertOk()->assertJsonPath('user.role', 'Student')->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', [
            'email' => 'student@aces.edu.ph',
            'password' => 'incorrect',
        ])->assertUnprocessable();
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
        $this->withToken($registrarToken)->putJson('/api/enrollments/'.$application->reference, [
            'status' => 'Enrolled',
        ])->assertOk()->assertJsonPath('status', 'Enrolled');
        $this->assertDatabaseHas('enrollment_applications', [
            'reference' => $application->reference, 'student_status' => 'New Student',
        ]);

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
        ])->assertCreated()->assertJsonPath('role', 'Student');

        $student = User::with('student')->where('email', 'issued.student@aces.edu.ph')->firstOrFail();
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
            'account_issued' => true,
            'user_id' => $student->id,
        ]);

        $studentToken = $this->token('issued.student@aces.edu.ph', 'Issued-Student-2026!');
        $this->withToken($studentToken)->getJson('/api/portal')
            ->assertOk()->assertJsonPath('students.0.name', $application->name)
            ->assertJsonPath('students.0.middle_name', 'Marie Louise')
            ->assertJsonPath('students.0.status', 'New Student');
        $this->withToken($registrarToken)->getJson('/api/enrollments')
            ->assertOk()->assertJsonPath('data.0.ref', $application->reference)
            ->assertJsonPath('data.0.session', 'Weekends')
            ->assertJsonPath('data.0.middle_name', 'Marie')
            ->assertJsonPath('data.0.student_status', 'New Student');
        $this->withToken($adminToken)->getJson('/api/users')
            ->assertOk()->assertJsonPath('total', 5);
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
        $this->withToken($registrarToken)->postJson('/api/subjects', [
            'code' => 'IT301', 'name' => 'Integrative Programming', 'units' => 3,
            'days' => 'Mon/Wed', 'start_time' => '08:00', 'end_time' => '09:30', 'room' => 'Lab 2',
            'program' => 'BSIT', 'year_level' => '3', 'grade_weights' => [30, 30, 40],
            'component_weights' => [40, 40, 20],
        ])->assertCreated();
        $this->withToken($registrarToken)->putJson('/api/faculty/'.$faculty->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertNoContent();
        $this->withToken($registrarToken)->putJson('/api/students/'.$student->id.'/subjects', [
            'codes' => ['IT301'],
        ])->assertNoContent();

        foreach (['Prelim', 'Midterm', 'Final'] as $term) {
            $this->withToken($this->token('faculty@aces.edu.ph'))->putJson('/api/grades', [
                'subject_code' => 'IT301',
                'term' => $term,
                'grades' => [[
                    'student_id' => $student->id, 'written_works' => 80,
                    'performance_tasks' => 90, 'exams' => 100,
                ]],
            ])->assertNoContent();
        }

        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_code' => 'IT301', 'term' => 'Prelim',
            'score' => 88, 'written_works' => 80, 'performance_tasks' => 90, 'exams' => 100,
        ]);
        $this->withToken($this->token('grade.student@aces.edu.ph', 'Grade-Student-2026!'))
            ->getJson('/api/portal')->assertOk()->assertJsonPath('students.0.g.IT301.0', 88)
            ->assertJsonPath('students.0.g.IT301.1', 88)
            ->assertJsonPath('students.0.g.IT301.2', 88)
            ->assertJsonPath('subjects.IT301.cw', [40, 40, 20])
            ->assertJsonPath('students.0.gc.IT301.0.written_works', 80);
        $this->withToken($this->token('faculty@aces.edu.ph'))->putJson('/api/subjects/IT301', [
            'component_weights' => [20, 30, 50],
        ])->assertOk();
        $this->assertDatabaseHas('grades', [
            'student_id' => $student->id, 'subject_code' => 'IT301', 'term' => 'Prelim', 'score' => 93,
        ]);
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
            'grade_weights' => [30, 30, 40],
            'component_weights' => [40, 40, 20],
        ];
        $created = $this->withToken($token)->postJson('/api/subjects', $subject)
            ->assertCreated()->assertJsonPath('code', 'IT305')
            ->assertJsonPath('start_time', '08:00')->assertJsonPath('end_time', '09:30');
        $this->assertDatabaseHas('subjects', [
            'code' => 'IT305', 'name' => 'Applied Database Systems',
        ]);
        $this->withToken($token)->postJson('/api/subjects', $subject)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/subjects', ['code' => 'BAD'])->assertUnprocessable();
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
            'name' => 'Another Test Student',
            'middle_name' => 'Lee',
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
        $this->assertSame('Lee', $application->middle_name);
        $this->assertSame('Others', $application->sex);
        $this->assertSame('BSBA', $application->program);
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

    private function submitApplication(): EnrollmentApplication
    {
        $response = $this->postJson('/api/enrollments', [
            'name' => 'Test Student',
            'middle_name' => 'Marie',
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
        ])->assertCreated();

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
