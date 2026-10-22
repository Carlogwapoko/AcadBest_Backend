<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('session')->nullable();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->string('session')->default('All');
            $table->string('semester')->default('Unspecified');
            $table->string('academic_year')->nullable();
        });

        DB::table('students')->whereNull('session')
            ->whereNotNull('enrollment_application_id')
            ->orderBy('id')->each(function (object $student): void {
                $session = DB::table('enrollment_applications')
                    ->where('id', $student->enrollment_application_id)
                    ->value('session');
                DB::table('students')->where('id', $student->id)->update(['session' => $session]);
            });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['session', 'semester', 'academic_year']);
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('session');
        });
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
