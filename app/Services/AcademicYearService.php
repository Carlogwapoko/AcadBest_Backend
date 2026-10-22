<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AcademicYearService
{
    public function current(): string
    {
        $configured = DB::table('portal_settings')
            ->where('key', 'academic_year')
            ->value('value');
        $configured = is_string($configured) ? json_decode($configured, true) : null;

        if (is_string($configured) && $this->isValid($configured)) {
            return $configured;
        }

        $now = now();
        $startYear = (int) $now->format('Y');
        if ((int) $now->format('n') < 6) {
            $startYear--;
        }

        return $startYear.'-'.($startYear + 1);
    }

    public function set(string $academicYear): void
    {
        DB::table('portal_settings')->updateOrInsert(
            ['key' => 'academic_year'],
            [
                'value' => json_encode($academicYear),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function isValid(string $academicYear): bool
    {
        if (! preg_match('/^(\d{4})-(\d{4})$/', $academicYear, $matches)) {
            return false;
        }

        return (int) $matches[2] === (int) $matches[1] + 1;
    }
}
