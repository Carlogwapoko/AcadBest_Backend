<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function rules(Request $request)
    {
        $data = $request->validate([
            'pass' => ['required', 'integer', 'between:50,95'],
            'safe' => ['required', 'integer', 'gt:pass', 'lte:100'],
        ]);
        DB::table('portal_settings')->updateOrInsert(
            ['key' => 'standing_rules'],
            ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json($data);
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
