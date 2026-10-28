<?php

namespace App\Support;

class ProgramCatalog
{
    public const PROGRAMS = [
        'BSTM',
        'BSED',
        'BEED',
        'BSE',
        'BSMA',
        'BSHM',
        'BSIT',
        'BSBA',
        'STEM',
        'ABM',
        'HUMSS',
        'GAS',
        'TVL',
    ];

    public const MAJORS = [
        'BSBA' => ['Financial Management', 'Human Resources Management'],
        'BSED' => ['Filipino', 'English', 'Mathematics'],
        'TVL' => [
            'ICT – Computer Servicing',
            'Home Economics',
            'Automotive Servicing',
            'Electricity Servicing',
        ],
    ];

    public static function majorsFor(string $program): array
    {
        return self::MAJORS[$program] ?? [];
    }

    public static function allMajors(): array
    {
        return array_merge(...array_values(self::MAJORS));
    }
}
