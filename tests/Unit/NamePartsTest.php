<?php

namespace Tests\Unit;

use App\Support\NameParts;
use PHPUnit\Framework\TestCase;

class NamePartsTest extends TestCase
{
    public function test_it_recovers_two_part_names_without_a_middle_name(): void
    {
        $this->assertSame([
            'first_name' => 'Maria',
            'middle_name' => '',
            'last_name' => 'Santos',
        ], NameParts::resolve('Maria Santos', null, null, null));
    }

    public function test_it_recovers_missing_outer_parts_around_a_known_middle_name(): void
    {
        $this->assertSame([
            'first_name' => 'Asthon Lloyd',
            'middle_name' => 'Barbante',
            'last_name' => 'Quisquisol',
        ], NameParts::resolve('Asthon Lloyd Barbante Quisquisol', null, 'Barbante', null));
    }

    public function test_it_keeps_ambiguous_parts_missing_but_uses_the_full_stored_name_for_display(): void
    {
        $parts = NameParts::resolve('Asthon Lloyd Barbante Quisquisol', null, null, null);

        $this->assertSame('', $parts['first_name']);
        $this->assertSame('', $parts['last_name']);
        $this->assertSame(
            'Asthon Lloyd Barbante Quisquisol',
            NameParts::displayName('Asthon Lloyd Barbante Quisquisol', null, null, null)
        );
        $this->assertSame(
            'Asthon Lloyd Barbante Quisquisol',
            NameParts::displayName('Asthon Lloyd Barbante Quisquisol', null, 'Unmatched Middle', null, true)
        );
    }
}
