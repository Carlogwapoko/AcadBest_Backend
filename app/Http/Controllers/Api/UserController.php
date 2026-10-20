<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentApplication;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 25), 1), 50);
        $users = User::with(['role', 'student'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhereHas('role', fn ($roles) => $roles->where('name', 'like', '%'.$search.'%'));
            }))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json($users->through(fn (User $user) => $this->present($user)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'exists:roles,name'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            'program' => ['nullable', 'string', 'max:20'],
            'year_level' => ['nullable', 'integer', 'min:1', 'max:8'],
            'section' => ['nullable', 'string', 'max:10'],
            'application_ref' => ['nullable', 'exists:enrollment_applications,reference'],
        ]);

        $role = Role::where('name', $validated['role'])->firstOrFail();
        if ($role->slug === 'student') {
            $application = EnrollmentApplication::where('reference', $validated['application_ref'] ?? '')
                ->where('status', 'Enrolled')->where('account_issued', false)->first();
            if (! $application) {
                throw ValidationException::withMessages([
                    'application_ref' => ['A student account can only be created for an approved application.'],
                ]);
            }
        }

        $user = DB::transaction(function () use ($validated, $role) {
            $application = $role->slug === 'student'
                ? EnrollmentApplication::where('reference', $validated['application_ref'])->lockForUpdate()->firstOrFail()
                : null;
            if ($application && ($application->status !== 'Enrolled' || $application->account_issued)) {
                throw ValidationException::withMessages([
                    'application_ref' => ['This approved application already has an account or is no longer eligible.'],
                ]);
            }
            $user = User::create([
                'name' => trim($validated['name']),
                'middle_name' => $application?->middle_name ?? ($validated['middle_name'] ?? null),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role_id' => $role->id,
                'phone' => $validated['phone'] ?? null,
                'is_active' => true,
            ]);

            if ($role->slug === 'student') {
                $student = Student::create([
                    'user_id' => $user->id,
                    'program' => $application->program,
                    'year_level' => $application->year_level,
                    'section' => $validated['section'] ?? 'A',
                    'status' => $application->student_status,
                ]);
                $student->subjects()->sync($application->requested_subjects ?? []);
                $application->update(['account_issued' => true, 'user_id' => $user->id]);
            }

            return $user->load(['role', 'student']);
        });

        return response()->json($this->present($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['sometimes', 'required', 'exists:roles,name'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'avatar_data' => ['sometimes', 'nullable', 'string', 'max:4194304'],
            'program' => ['sometimes', 'required', 'string', 'max:20'],
            'year_level' => ['sometimes', 'required', 'integer', 'min:1', 'max:8'],
        ]);

        if (isset($validated['role'])) {
            $newRole = Role::where('name', $validated['role'])->firstOrFail();
            $currentRole = $user->role;
            if ($currentRole?->slug !== $newRole->slug
                && in_array('student', [$currentRole?->slug, $newRole->slug], true)) {
                throw ValidationException::withMessages([
                    'role' => ['Student accounts must be issued from an approved enrollment application.'],
                ]);
            }
            $user->role()->associate($newRole);
        }
        foreach (['name', 'middle_name', 'email', 'phone'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $field === 'email' ? strtolower(trim($validated[$field]))
                    : ($validated[$field] === null ? null : trim($validated[$field]));
            }
        }
        if (array_key_exists('active', $validated)) {
            $user->is_active = $validated['active'];
        }
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (array_key_exists('avatar_data', $validated)) {
            $this->saveAvatar($user, $validated['avatar_data']);
        }

        DB::transaction(function () use ($validated, $user) {
            $user->save();
            if ($user->role?->slug === 'student'
                && (isset($validated['program']) || isset($validated['year_level']))) {
                $student = $user->student;
                if (! $student) {
                    throw ValidationException::withMessages([
                        'student' => ['The student record could not be found.'],
                    ]);
                }
                $student->update([
                    'program' => $validated['program'] ?? $student->program,
                    'year_level' => $validated['year_level'] ?? $student->year_level,
                ]);
            }
        });

        return response()->json($this->present($user->fresh(['role', 'student'])));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->delete();

        return response()->noContent();
    }

    private function saveAvatar(User $user, ?string $dataUrl): void
    {
        if ($dataUrl === null) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = null;

            return;
        }

        if (! preg_match('/^data:image\/(png|jpeg|webp);base64,([A-Za-z0-9+\/]+=*)$/', $dataUrl, $matches)) {
            throw ValidationException::withMessages(['avatar_data' => ['Choose a valid PNG, JPEG, or WebP image.']]);
        }
        $bytes = base64_decode($matches[2], true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024 || ! $image
            || ! in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages(['avatar_data' => ['The profile image is invalid or exceeds 3 MB.']]);
        }

        $extension = match ($image['mime']) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = 'avatars/'.bin2hex(random_bytes(20)).'.'.$extension;
        Storage::disk('public')->put($path, $bytes);
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->avatar_path = $path;
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'middle_name' => $user->middle_name, 'email' => $user->email,
            'role' => $user->role?->name, 'active' => $user->is_active,
            'phone' => $user->phone ?? '', 'student_id' => $user->student?->id,
            'program' => $user->student?->program, 'year_level' => $user->student?->year_level,
            'avatar' => $user->avatar_path ? asset('storage/'.$user->avatar_path) : null,
        ];
    }
}
