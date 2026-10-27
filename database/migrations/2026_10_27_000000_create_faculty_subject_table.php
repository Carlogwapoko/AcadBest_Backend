<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $assignments = DB::table('subjects')
            ->whereNotNull('faculty_id')
            ->orderBy('code')
            ->get(['code', 'faculty_id']);

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faculty_id');
        });

        Schema::create('faculty_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_code', 10);
            $table->timestamps();
            $table->unique(['faculty_id', 'subject_code']);
            $table->index('subject_code');
            $table->foreign('subject_code')->references('code')->on('subjects')->cascadeOnDelete();
        });

        $assignments->each(fn ($subject) => DB::table('faculty_subject')->insert([
            'faculty_id' => $subject->faculty_id,
            'subject_code' => $subject->code,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function down(): void
    {
        $hasMultipleFaculty = DB::table('faculty_subject')
            ->select('subject_code')
            ->groupBy('subject_code')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasMultipleFaculty) {
            throw new RuntimeException('Cannot roll back multiple faculty assignments without losing assignment data.');
        }

        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('faculty_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('faculty_subject')
            ->orderBy('subject_code')
            ->orderBy('faculty_id')
            ->get(['subject_code', 'faculty_id'])
            ->each(function ($assignment): void {
                DB::table('subjects')->where('code', $assignment->subject_code)
                    ->whereNull('faculty_id')
                    ->update(['faculty_id' => $assignment->faculty_id]);
            });

        Schema::dropIfExists('faculty_subject');
    }
};
