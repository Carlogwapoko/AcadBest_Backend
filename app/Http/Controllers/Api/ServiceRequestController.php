<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Support\NameParts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceRequestController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user()->loadMissing(['role', 'student']);
        if (! in_array($actor->role->slug, ['student', 'registrar', 'admin'], true)) {
            return response()->json(['message' => 'You are not authorized to view service requests.'], 403);
        }
        $studentId = $actor->role->slug === 'student' ? $actor->student?->id : null;
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $type = $request->query('type');
        $perPage = min(max((int) $request->query('per_page', 25), 1), 50);
        $requests = ServiceRequest::with(['student.user'])
            ->when($actor->role->slug === 'student', fn ($query) => $query->where('student_id', $studentId ?? 0))
            ->when($status && $status !== 'All', fn ($query) => $query->where('status', $status))
            ->when($type && $type !== 'All', fn ($query) => $query->where('type', $type))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('type', 'like', '%'.$search.'%')
                    ->orWhereHas('student.user', fn ($users) => $users->where('name', 'like', '%'.$search.'%'));
            }))
            ->latest()
            ->paginate($perPage);

        return response()->json($requests->through(function (ServiceRequest $serviceRequest): array {
            $user = $serviceRequest->student?->user;
            $parts = $user
                ? NameParts::resolve($user->name, $user->first_name, $user->middle_name, $user->last_name)
                : ['first_name' => '', 'middle_name' => '', 'last_name' => ''];

            return [
                'id' => $serviceRequest->reference,
                'sid' => $serviceRequest->student_id,
                'by' => $user
                    ? NameParts::displayName(
                        $user->name,
                        $parts['first_name'],
                        $parts['middle_name'],
                        $parts['last_name'],
                        true
                    )
                    : 'Student',
                'first_name' => $parts['first_name'] ?: null,
                'middle_name' => $parts['middle_name'] ?: null,
                'last_name' => $parts['last_name'] ?: null,
                'type' => $serviceRequest->type,
                'purpose' => $serviceRequest->purpose,
                'copies' => $serviceRequest->copies,
                'need' => $serviceRequest->date_needed->format('Y-m-d'),
                'rem' => $serviceRequest->remarks ?? '',
                'st' => $serviceRequest->status,
                'log' => collect($serviceRequest->history)->map(fn ($item) => [$item['date'], $item['action']])->all(),
            ];
        }));
    }

    public function store(Request $request)
    {
        $student = $request->user()->student;
        if (! $student) {
            return response()->json(['message' => 'A student record is required to submit a service request.'], 422);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(['Certificate of Enrollment', 'Transcript of Records', 'Good Moral Certificate', 'Clearance', 'Copy of Grades'])],
            'purpose' => ['required', 'string', 'min:5', 'max:255'],
            'copies' => ['required', 'integer', 'between:1,5'],
            'date_needed' => ['required', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $reference = 'REQ-'.strtoupper(bin2hex(random_bytes(3)));
        $serviceRequest = ServiceRequest::create($data + [
            'reference' => $reference, 'student_id' => $student->id,
            'history' => [['date' => now()->format('M d'), 'at' => now()->toIso8601String(), 'action' => 'Submitted']],
        ]);

        return response()->json(['reference' => $serviceRequest->reference], 201);
    }

    public function update(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['Pending', 'Processing', 'Approved', 'Ready for pick-up', 'Completed', 'Rejected'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $transitions = [
            'Pending' => ['Processing', 'Approved', 'Rejected'],
            'Processing' => ['Approved', 'Ready for pick-up', 'Rejected'],
            'Approved' => ['Ready for pick-up', 'Rejected'],
            'Ready for pick-up' => ['Completed'],
            'Completed' => [],
            'Rejected' => [],
        ];
        if (! in_array($data['status'], $transitions[$serviceRequest->status] ?? [], true)) {
            return response()->json(['message' => 'This request cannot move from '.$serviceRequest->status.' to '.$data['status'].'.'], 422);
        }
        $history = $serviceRequest->history ?? [];
        $action = $data['status'] === 'Rejected' && ! empty($data['remarks'])
            ? 'Rejected – '.$data['remarks']
            : $data['status'];
        $history[] = ['date' => now()->format('M d'), 'at' => now()->toIso8601String(), 'action' => $action];
        $serviceRequest->update([
            'status' => $data['status'], 'history' => $history,
            'remarks' => $data['remarks'] ?? $serviceRequest->remarks,
            'completed_at' => in_array($data['status'], ['Completed', 'Rejected'], true) ? now() : null,
        ]);

        return response()->noContent();
    }

    public function metrics()
    {
        $done = ServiceRequest::where('status', 'Completed')->whereNotNull('completed_at')->get();
        $minutes = $done->map(fn ($r) => $r->created_at->diffInMinutes($r->completed_at));

        return response()->json([
            'completed' => $done->count(),
            'avg_processing_hours' => $minutes->isEmpty() ? null : round($minutes->avg() / 60, 1),
            'by_type' => $done->groupBy('type')->map(fn ($g) => round(
                $g->avg(fn ($r) => $r->created_at->diffInMinutes($r->completed_at)) / 60, 1
            )),
        ]);
    }
}
