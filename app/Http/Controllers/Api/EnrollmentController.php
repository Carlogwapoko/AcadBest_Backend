<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AcademicYearService;
use App\Support\NameParts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $pendingAccountsOnly = $request->boolean('pending_accounts');
        $perPage = min(max((int) $request->query('per_page', 25), 1), 50);
        $applications = EnrollmentApplication::query()->with('student')
            ->when($status && $status !== 'All', fn ($query) => $query->where('status', $status))
            ->when($pendingAccountsOnly, fn ($query) => $query->where('status', 'Enrolled')->where('account_issued', false))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('program', 'like', '%'.$search.'%');
            }))
            ->latest()
            ->paginate($perPage);

        return response()->json($applications->through(fn (EnrollmentApplication $application) => $this->present($application)));
    }

    public function store(Request $request, AcademicYearService $academicYear)
    {
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $request->merge([$field => trim($value) === '' ? null : trim($value)]);
            }
        }
        $request->merge(['academic_year' => $academicYear->current()]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'min:2', 'max:255'],
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', Rule::in(['Male', 'Female', 'Others'])],
            'address' => ['required', 'string', 'min:5'],
            'program' => ['required', 'string', Rule::in(['BSTM', 'BSED', 'BEED', 'BSE', 'BSMA', 'BSHM', 'BSIT', 'BSBA',
                'STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'])],
            'major' => ['nullable', 'string', 'required_if:program,BSBA,BSED,TVL', Rule::in([
                'Financial Management', 'Human Resources Management', 'Filipino', 'English', 'Mathematics',
                'ICT – Computer Servicing', 'Home Economics', 'Automotive Servicing', 'Electricity Servicing',
            ])],
            'year_level' => ['required', 'integer', 'min:1', 'max:12'],
            'session' => ['nullable', 'string', Rule::in(['Afternoon', 'Evening', 'Weekends'])],
            'semester' => ['required', 'string', Rule::in(['1st Semester', '2nd Semester'])],
            'application_type' => ['required', 'string', 'max:50'],
            'previous_school' => ['required', 'string', 'min:3', 'max:255'],
            'guardian_name' => ['required', 'string', 'min:3', 'max:255'],
            'guardian_phone' => ['required', 'string', 'max:30'],
            'certified' => ['required', 'accepted'],
        ]);
        if (! $academicYear->isValid($data['academic_year'])) {
            throw ValidationException::withMessages([
                'academic_year' => ['Choose a valid academic year, such as 2026-2027.'],
            ]);
        }
        if (isset($data['major']) && ! in_array($data['major'], $this->majorsFor($data['program']), true)) {
            throw ValidationException::withMessages([
                'major' => ['Choose a major offered by the selected program.'],
            ]);
        }
        $data['name'] = $this->fullName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']);
        $data['reference'] = 'ENR-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
        $data['status'] = 'Pending';
        $data['history'] = [['date' => now()->format('M d'), 'action' => 'Application submitted']];

        $application = EnrollmentApplication::create($data);

        return response()->json(['reference' => $application->reference], 201);
    }

    public function track(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string'],
            'email' => ['required', 'email'],
        ]);
        $application = EnrollmentApplication::where('reference', strtoupper(trim($data['reference'])))
            ->where('email', strtolower(trim($data['email'])))->first();

        if (! $application) {
            return response()->json(['message' => 'No application found for that reference number and e-mail.'], 404);
        }

        return response()->json([
            'ref' => $application->reference,
            'name' => NameParts::displayName(
                $application->name,
                $application->first_name,
                $application->middle_name,
                $application->last_name
            ),
            ...array_map(
                fn (string $part): ?string => $part !== '' ? $part : null,
                NameParts::resolve(
                    $application->name,
                    $application->first_name,
                    $application->middle_name,
                    $application->last_name
                )
            ),
            'student_status' => $application->student_status,
            'email' => $application->email, 'prog' => $application->program,
            'yr' => $application->year_level, 'st' => $application->status,
            'academic_year' => $application->academic_year,
            'semester' => $application->semester,
            'major' => $application->major,
            'school_id' => $application->student?->school_id,
            'note' => $application->reviewer_note ?? '',
            'log' => collect($application->history)->map(fn ($item) => [$item['date'], $item['action']])->all(),
        ]);
    }

    public function update(Request $request, EnrollmentApplication $enrollment)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(['Pending', 'Under Review', 'Enrolled', 'Rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'subjects' => ['sometimes', 'array'],
            'subjects.*' => ['string', 'exists:subjects,code'],
        ]);
        if (isset($data['subjects']) && ! isset($data['status'])) {
            if ($enrollment->session && Subject::query()
                ->whereIn('code', $data['subjects'])
                ->whereNotIn('session', ['All', $enrollment->session])
                ->exists()) {
                throw ValidationException::withMessages([
                    'subjects' => ['The subject load must match the applicant’s selected session.'],
                ]);
            }
            $enrollment->update(['requested_subjects' => $data['subjects']]);

            return response()->noContent();
        }
        if (! isset($data['status'])) {
            throw ValidationException::withMessages(['status' => ['Choose an application status.']]);
        }
        if ($enrollment->account_issued && $data['status'] !== 'Enrolled') {
            throw ValidationException::withMessages(['status' => ['An application with an issued account cannot be changed from Enrolled.']]);
        }

        $schoolId = DB::transaction(function () use ($data, $enrollment) {
            $application = EnrollmentApplication::whereKey($enrollment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $student = $application->student()->lockForUpdate()->first()
                ?? Student::where('enrollment_application_id', $application->id)
                    ->lockForUpdate()
                    ->first();

            if ($data['status'] === 'Enrolled') {
                if (! $student) {
                    $email = strtolower(trim($application->email));
                    $student = Student::query()
                        ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                        ->orWhereHas('enrollmentApplication', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
                        ->lockForUpdate()
                        ->first();
                }
                $student ??= Student::create([
                    'enrollment_application_id' => $application->id,
                    'program' => $application->program,
                    'major' => $application->major,
                    'year_level' => $application->year_level,
                    'section' => 'A',
                    'status' => 'New Student',
                    'session' => $application->session,
                    'semester' => $application->semester,
                ]);
                $student->assignSchoolId();
                $student->update([
                    'program' => $application->program,
                    'major' => $application->major,
                    'year_level' => $application->year_level,
                    'session' => $application->session,
                    'semester' => $application->semester,
                ]);
                $application->update([
                    'student_id' => $student->id,
                    'user_id' => $student->user_id ?? $application->user_id,
                    'account_issued' => $student->user_id ? true : $application->account_issued,
                ]);
            } elseif (! $application->account_issued) {
                if ($student && $student->enrollment_application_id === $application->id && ! $student->user_id) {
                    $student->delete();
                    $student = null;
                }
            }

            $history = $application->history ?? [];
            $history[] = ['date' => now()->format('M d'), 'action' => match ($data['status']) {
                'Enrolled' => 'Approved – officially enrolled',
                'Rejected' => 'Application rejected',
                'Under Review' => 'Under review by the registrar',
                default => 'Pending',
            }];
            $application->update([
                'status' => $data['status'],
                'student_status' => $data['status'] === 'Enrolled'
                    ? ($student?->status ?? 'New Student')
                    : 'Applicant',
                'reviewer_note' => $data['note'] ?? $application->reviewer_note,
                'history' => $history,
            ]);

            return $student?->school_id;
        });

        $enrollment->refresh();

        return response()->json([
            'status' => $enrollment->status,
            'note' => $enrollment->reviewer_note,
            'school_id' => $schoolId,
        ]);
    }

    private function present(EnrollmentApplication $application): array
    {
        $parts = NameParts::resolve(
            $application->name,
            $application->first_name,
            $application->middle_name,
            $application->last_name
        );

        return [
            'ref' => $application->reference,
            'name' => NameParts::displayName(
                $application->name,
                $application->first_name,
                $application->middle_name,
                $application->last_name
            ),
            'first_name' => $parts['first_name'] ?: null,
            'middle_name' => $parts['middle_name'] ?: null,
            'last_name' => $parts['last_name'] ?: null,
            'email' => $application->email,
            'phone' => $application->phone, 'dob' => $application->date_of_birth->format('Y-m-d'),
            'student_status' => $application->student_status,
            'sex' => $application->sex, 'addr' => $application->address, 'prog' => $application->program,
            'yr' => $application->year_level, 'session' => $application->session,
            'major' => $application->major, 'semester' => $application->semester,
            'academic_year' => $application->academic_year,
            'type' => $application->application_type,
            'school' => $application->previous_school, 'gname' => $application->guardian_name,
            'gph' => $application->guardian_phone, 'st' => $application->status,
            'date' => $application->created_at->format('M d'), 'note' => $application->reviewer_note ?? '',
            'acct' => $application->account_issued, 'subs' => $application->requested_subjects ?? [],
            'school_id' => $application->student?->school_id,
            'log' => collect($application->history)->map(fn ($item) => [$item['date'], $item['action']])->all(),
        ];
    }

    private function fullName(?string $first, ?string $middle, ?string $last): string
    {
        return implode(' ', array_filter(
            array_map(fn (?string $part): string => trim($part ?? ''), [$first, $middle, $last]),
            fn (string $part): bool => $part !== ''
        ));
    }

    private function majorsFor(string $program): array
    {
        return match ($program) {
            'BSBA' => ['Financial Management', 'Human Resources Management'],
            'BSED' => ['Filipino', 'English', 'Mathematics'],
            'TVL' => [
                'ICT – Computer Servicing',
                'Home Economics',
                'Automotive Servicing',
                'Electricity Servicing',
            ],
            default => [],
        };
    }
}
