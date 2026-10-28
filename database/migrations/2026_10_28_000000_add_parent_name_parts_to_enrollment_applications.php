<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table): void {
            foreach ([
                'mother_first_name',
                'mother_middle_name',
                'mother_last_name',
                'father_first_name',
                'father_middle_name',
                'father_last_name',
            ] as $column) {
                if (! Schema::hasColumn('enrollment_applications', $column)) {
                    $table->string($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table): void {
            foreach ([
                'mother_first_name',
                'mother_middle_name',
                'mother_last_name',
                'father_first_name',
                'father_middle_name',
                'father_last_name',
            ] as $column) {
                if (Schema::hasColumn('enrollment_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
