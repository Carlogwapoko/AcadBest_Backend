<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subjects', 'grade_weights')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropColumn('grade_weights');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('subjects', 'grade_weights')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->json('grade_weights')->nullable();
            });
        }
    }
};
