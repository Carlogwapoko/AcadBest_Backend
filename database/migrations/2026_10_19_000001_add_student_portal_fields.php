<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'users' => ['middle_name'],
            'enrollment_applications' => ['middle_name', 'student_status'],
            'students' => ['status'],
            'subjects' => ['start_time', 'end_time', 'component_weights'],
            'grades' => ['written_works', 'performance_tasks', 'exams'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                    if ($table === 'subjects' && $column === 'component_weights') {
                        $blueprint->json($column)->nullable();
                    } elseif ($table === 'enrollment_applications' && $column === 'student_status') {
                        $blueprint->string($column)->default('Applicant');
                    } elseif ($table === 'students' && $column === 'status') {
                        $blueprint->string($column)->nullable();
                    } elseif ($table === 'grades') {
                        $blueprint->decimal($column, 5, 2)->nullable();
                    } elseif ($table === 'subjects') {
                        $blueprint->string($column, 5)->nullable();
                    } else {
                        $blueprint->string($column)->nullable();
                    }
                });
            }
        }

        DB::table('enrollment_applications')->where('status', 'Enrolled')
            ->update(['student_status' => 'New Student']);

        foreach (DB::table('subjects')->whereNotNull('meeting_time')
            ->whereNull('start_time')->get(['code', 'meeting_time']) as $subject) {
            $times = $this->parseLegacyMeetingTime($subject->meeting_time);
            if ($times) {
                DB::table('subjects')->where('code', $subject->code)->update([
                    'start_time' => $times[0],
                    'end_time' => $times[1],
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'grades' => ['written_works', 'performance_tasks', 'exams'],
            'subjects' => ['component_weights', 'start_time', 'end_time'],
            'students' => ['status'],
            'enrollment_applications' => ['middle_name', 'student_status'],
            'users' => ['middle_name'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
                }
            }
        }
    }

    private function parseLegacyMeetingTime(string $value): ?array
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*(AM|PM)?\s*[–-]\s*(\d{1,2}):(\d{2})\s*(AM|PM)?\s*$/i', $value, $matches)) {
            return null;
        }

        $startPeriod = strtoupper($matches[3] ?: $matches[6]);
        $endPeriod = strtoupper($matches[6] ?: $matches[3]);
        $start = $this->time24((int) $matches[1], (int) $matches[2], $startPeriod);
        $end = $this->time24((int) $matches[4], (int) $matches[5], $endPeriod);

        return $start !== null && $end !== null ? [$start, $end] : null;
    }

    private function time24(int $hour, int $minute, string $period): ?string
    {
        if ($minute > 59 || ($period !== '' && ($hour < 1 || $hour > 12)) || ($period === '' && $hour > 23)) {
            return null;
        }
        if ($period !== '') {
            $hour = ($hour % 12) + ($period === 'PM' ? 12 : 0);
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
};
