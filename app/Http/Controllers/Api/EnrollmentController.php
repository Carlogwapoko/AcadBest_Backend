<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
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
        $applications = EnrollmentApplication::query()
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'string', Rule::in(['Male', 'Female', 'Others'])],
            'address' => ['required', 'string', 'min:5'],
            'program' => ['required', 'string', Rule::in(['BSTM', 'BSED', 'BEED', 'BSE', 'BSMA', 'BSHM', 'BSIT', 'BSBA'])],
            'year_level' => ['required', 'integer', 'min:1', 'max:8'],
            'session' => ['nullable', 'string', Rule::in(['Afternoon', 'Evening', 'Weekends'])],
            'application_type' => ['required', 'string', 'max:50'],
            'previous_school' => ['required', 'string', 'min:3', 'max:255'],
            'guardian_name' => ['required', 'string', 'min:3', 'max:255'],
            'guardian_phone' => ['required', 'string', 'max:30'],
            'certified' => ['required', 'accepted'],
        ]);
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
            'ref' => $application->reference, 'name' => $application->name,
            'middle_name' => $application->middle_name,
            'student_status' => $application->student_status,
            'email' => $application->email, 'prog' => $application->program,
            'yr' => $application->year_level, 'st' => $application->status,
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
            $enrollment->update(['requested_subjects' => $data['subjects']]);

            return response()->noContent();
        }
        if (! isset($data['status'])) {
            throw ValidationException::withMessages(['status' => ['Choose an application status.']]);
        }
        if ($enrollment->account_issued && $data['status'] !== 'Enrolled') {
            throw ValidationException::withMessages(['status' => ['An application with an issued account cannot be changed from Enrolled.']]);
        }

        DB::transaction(function () use ($data, $enrollment) {
            $history = $enrollment->history ?? [];
            $history[] = ['date' => now()->format('M d'), 'action' => match ($data['status']) {
                'Enrolled' => 'Approved – officially enrolled',
                'Rejected' => 'Application rejected',
                'Under Review' => 'Under review by the registrar',
                default => 'Pending',
            }];
            $enrollment->update([
                'status' => $data['status'],
                'student_status' => $data['status'] === 'Enrolled' ? 'New Student' : 'Applicant',
                'reviewer_note' => $data['note'] ?? $enrollment->reviewer_note,
                'history' => $history,
            ]);
        });

        return response()->json(['status' => $enrollment->status, 'note' => $enrollment->reviewer_note]);
    }

    private function present(EnrollmentApplication $application): array
    {
        return [
            'ref' => $application->reference, 'name' => $application->name, 'email' => $application->email,
            'phone' => $application->phone, 'dob' => $application->date_of_birth->format('Y-m-d'),
            'middle_name' => $application->middle_name,
            'student_status' => $application->student_status,
            'sex' => $application->sex, 'addr' => $application->address, 'prog' => $application->program,
            'yr' => $application->year_level, 'session' => $application->session,
            'type' => $application->application_type,
            'school' => $application->previous_school, 'gname' => $application->guardian_name,
            'gph' => $application->guardian_phone, 'st' => $application->status,
            'date' => $application->created_at->format('M d'), 'note' => $application->reviewer_note ?? '',
            'acct' => $application->account_issued, 'subs' => $application->requested_subjects ?? [],
            'log' => collect($application->history)->map(fn ($item) => [$item['date'], $item['action']])->all(),
        ];
    }
}
