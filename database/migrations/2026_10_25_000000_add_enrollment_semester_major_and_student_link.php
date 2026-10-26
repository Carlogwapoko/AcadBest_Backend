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
            $table->string('major')->nullable();
            $table->string('semester')->nullable();
        });

        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->string('major')->nullable();
            $table->string('semester')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
        });

        DB::table('students')
            ->whereNotNull('enrollment_application_id')
            ->orderBy('id')
            ->each(function (object $student): void {
                DB::table('enrollment_applications')
                    ->where('id', $student->enrollment_application_id)
                    ->update(['student_id' => $student->id]);
            });
    }

    public function down(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
            $table->dropColumn(['major', 'semester']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['major', 'semester']);
        });
    }
};
