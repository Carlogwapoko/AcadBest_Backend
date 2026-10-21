<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('school_id', 10)->nullable()->unique();
            $table->foreignId('enrollment_application_id')
                ->nullable()
                ->unique()
                ->constrained('enrollment_applications')
                ->cascadeOnDelete();
        });

        DB::table('students')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->each(function (object $student): void {
                if ($student->id > 999999) {
                    throw new RuntimeException('Cannot generate a 10-digit school ID for a student record above 999999.');
                }

                $year = $student->created_at
                    ? (int) date('Y', strtotime($student->created_at))
                    : (int) now()->format('Y');

                DB::table('students')
                    ->where('id', $student->id)
                    ->update(['school_id' => sprintf('%04d%06d', $year, $student->id)]);
            });

        DB::table('enrollment_applications')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function (object $application): void {
                DB::table('students')
                    ->where('user_id', $application->user_id)
                    ->whereNull('enrollment_application_id')
                    ->update(['enrollment_application_id' => $application->id]);
            });

        DB::table('enrollment_applications')
            ->where('status', 'Enrolled')
            ->where('account_issued', false)
            ->whereNull('user_id')
            ->orderBy('id')
            ->each(function (object $application): void {
                $studentId = DB::table('students')->insertGetId([
                    'enrollment_application_id' => $application->id,
                    'program' => $application->program,
                    'year_level' => $application->year_level,
                    'section' => 'A',
                    'status' => $application->student_status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($studentId > 999999) {
                    throw new RuntimeException('Cannot generate a 10-digit school ID for a student record above 999999.');
                }

                DB::table('students')
                    ->where('id', $studentId)
                    ->update(['school_id' => sprintf('%04d%06d', (int) now()->format('Y'), $studentId)]);
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['enrollment_application_id']);
            $table->dropUnique(['enrollment_application_id']);
            $table->dropColumn('enrollment_application_id');
            $table->dropUnique(['school_id']);
            $table->dropColumn('school_id');
        });
    }
};
