<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('enrollment_applications', 'academic_year')) {
            Schema::table('enrollment_applications', function (Blueprint $table) {
                $table->string('academic_year', 9)->nullable();
            });
        }

        if (Schema::hasColumn('subjects', 'semester')) {
            $semester = collect(Schema::getColumns('subjects'))
                ->firstWhere('name', 'semester');
            if (! $semester['nullable'] || $semester['default'] !== 'NULL') {
                Schema::table('subjects', function (Blueprint $table) {
                    $table->string('semester')->nullable()->default(null)->change();
                });
            }
            DB::table('subjects')->where('semester', 'Unspecified')->update(['semester' => null]);
        }

        DB::table('enrollment_applications')
            ->where('name_parts_verified', false)
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->orderBy('id')
            ->chunkById(500, function ($applications): void {
                foreach ($applications as $application) {
                    $parts = array_filter(
                        array_map(
                            fn (?string $part): string => trim($part ?? ''),
                            [$application->first_name, $application->middle_name, $application->last_name]
                        ),
                        fn (string $part): bool => $part !== ''
                    );

                    if (implode(' ', $parts) === trim($application->name)) {
                        DB::table('enrollment_applications')
                            ->where('id', $application->id)
                            ->update(['name_parts_verified' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('subjects')->whereNull('semester')->update(['semester' => 'Unspecified']);
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('semester')->default('Unspecified')->nullable(false)->change();
        });

        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropColumn('academic_year');
        });
    }
};
