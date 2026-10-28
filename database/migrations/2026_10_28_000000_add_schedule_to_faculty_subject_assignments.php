<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('faculty_subject')) {
            return;
        }

        Schema::table('faculty_subject', function (Blueprint $table): void {
            foreach (['days', 'start_time', 'end_time', 'room'] as $column) {
                if (! Schema::hasColumn('faculty_subject', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });

        if (! Schema::hasTable('subjects')) {
            return;
        }

        DB::table('faculty_subject')
            ->join('subjects', 'subjects.code', '=', 'faculty_subject.subject_code')
            ->whereNull('faculty_subject.days')
            ->orWhereNull('faculty_subject.start_time')
            ->orWhereNull('faculty_subject.end_time')
            ->orWhereNull('faculty_subject.room')
            ->select([
                'faculty_subject.id',
                'subjects.days',
                'subjects.start_time',
                'subjects.end_time',
                'subjects.room',
            ])
            ->orderBy('faculty_subject.id')
            ->get()
            ->each(function (object $assignment): void {
                DB::table('faculty_subject')->where('id', $assignment->id)->update([
                    'days' => $assignment->days,
                    'start_time' => $assignment->start_time,
                    'end_time' => $assignment->end_time,
                    'room' => $assignment->room,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('faculty_subject')) {
            return;
        }

        Schema::table('faculty_subject', function (Blueprint $table): void {
            foreach (['days', 'start_time', 'end_time', 'room'] as $column) {
                if (Schema::hasColumn('faculty_subject', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
