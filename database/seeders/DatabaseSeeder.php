<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = collect([
            ['slug' => 'student', 'name' => 'Student'],
            ['slug' => 'faculty', 'name' => 'Faculty'],
            ['slug' => 'registrar', 'name' => 'Registrar Staff'],
            ['slug' => 'admin', 'name' => 'Admin'],
        ])->mapWithKeys(fn (array $role) => [
            $role['slug'] => Role::updateOrCreate(['slug' => $role['slug']], ['name' => $role['name']]),
        ]);

        $password = (string) env('DEMO_PASSWORD', 'AcadBest-Demo-2026!');
        $accounts = [
            ['first_name' => 'Maria', 'last_name' => 'Santos', 'name' => 'Maria Santos', 'email' => 'student@aces.edu.ph', 'phone' => '0917 555 0101', 'role' => 'student'],
            ['first_name' => 'Prof. Anfred', 'last_name' => 'Albit', 'name' => 'Prof. Anfred Albit', 'email' => 'faculty@aces.edu.ph', 'phone' => '0917 555 0202', 'role' => 'faculty'],
            ['first_name' => 'Liza', 'last_name' => 'Ramos', 'name' => 'Liza Ramos', 'email' => 'registrar@aces.edu.ph', 'phone' => '0917 555 0303', 'role' => 'registrar'],
            ['first_name' => 'System', 'last_name' => 'Admin', 'name' => 'System Admin', 'email' => 'admin@aces.edu.ph', 'phone' => '0917 555 0404', 'role' => 'admin'],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'phone' => $account['phone'],
                    'password' => Hash::make($password),
                    'role_id' => $roles[$account['role']]->id,
                    'is_active' => true,
                ]
            );

            if ($account['role'] === 'student') {
                Student::updateOrCreate(
                    ['user_id' => $user->id],
                    ['program' => 'BSIT', 'year_level' => 3, 'section' => 'A']
                );
            }
        }

        DB::table('portal_settings')->updateOrInsert(
            ['key' => 'standing_rules'],
            ['value' => json_encode(['pass' => 75, 'safe' => 80]), 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
