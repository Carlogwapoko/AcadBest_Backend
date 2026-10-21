<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::with(['role', 'student'])->where('email', strtolower(trim($credentials['email'])))->first();

        if (! $user || ! $user->is_active || ! $user->role
            || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $plainTextToken = bin2hex(random_bytes(32));
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'token' => $plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->present($request->user()->load(['role', 'student']))]);
    }

    public function logout(Request $request)
    {
        $request->attributes->get('api_token')?->delete();

        return response()->noContent();
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'middle_name' => $user->middle_name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'role_key' => $user->role?->slug,
            'phone' => $user->phone ?? '',
            'active' => $user->is_active,
            'student_id' => $user->student?->id,
            'school_id' => $user->student?->school_id,
            'avatar' => $user->avatar_path ? asset('storage/'.$user->avatar_path) : null,
        ];
    }
}
