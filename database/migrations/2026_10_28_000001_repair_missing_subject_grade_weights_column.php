<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subjects') && ! Schema::hasColumn('subjects', 'grade_weights')) {
            Schema::table('subjects', function (Blueprint $table): void {
                $table->json('grade_weights')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subjects') || ! Schema::hasColumn('subjects', 'grade_weights')) {
            return;
        }

        if (DB::table('subjects')->whereNotNull('grade_weights')->exists()) {
            throw new RuntimeException(
                'Cannot remove subject grade weights because existing grading configuration would be lost.'
            );
        }

        Schema::table('subjects', function (Blueprint $table): void {
            $table->dropColumn('grade_weights');
        });
    }
};
