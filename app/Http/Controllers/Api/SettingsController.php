<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AcademicYearService;
use App\Services\StandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function academicYear(AcademicYearService $academicYear)
    {
        return response()->json(['academic_year' => $academicYear->current()]);
    }

    public function updateAcademicYear(Request $request, AcademicYearService $academicYear)
    {
        $data = $request->validate([
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/', 'max:9'],
        ]);
        if (! $academicYear->isValid($data['academic_year'])) {
            throw ValidationException::withMessages([
                'academic_year' => ['Choose a valid academic year, such as 2026-2027.'],
            ]);
        }
        $academicYear->set($data['academic_year']);

        return response()->json(['academic_year' => $data['academic_year']]);
    }

    public function rules(Request $request, StandingService $standing)
    {
        $data = $request->validate([
            'pass' => ['required', 'integer', 'between:50,95'],
            'safe' => ['required', 'integer', 'gt:pass', 'lte:100'],
            'trend' => ['sometimes', 'integer', 'between:1,30'],
        ]);
        $data['trend'] ??= $standing->rules()['trend']; // dili ma-reset kung ang UI pass/safe ra ang gipadala
        DB::table('portal_settings')->updateOrInsert(
            ['key' => 'standing_rules'],
            ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['pass' => $data['pass'], 'safe' => $data['safe']]);
    }

    public function setModule(Request $request)
    {
        $data = $request->validate([
            'role' => ['required', 'exists:roles,slug'],
            'module' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
        ]);
        $role = Role::where('slug', $data['role'])->firstOrFail();
        DB::table('module_access')->updateOrInsert(
            ['role_id' => $role->id, 'module_key' => $data['module']],
            ['enabled' => $data['enabled'], 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->noContent();
    }
}
