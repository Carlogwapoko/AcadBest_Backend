<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->boolean('name_parts_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropColumn('name_parts_verified');
        });
    }
};
