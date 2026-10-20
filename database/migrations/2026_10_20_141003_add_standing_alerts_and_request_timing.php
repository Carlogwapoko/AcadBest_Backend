<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('standing', 20)->nullable();
            $table->timestamp('standing_changed_at')->nullable();
        });

        Schema::table('service_requests', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('faculty_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->index(['faculty_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_alerts');
        Schema::table('service_requests', fn (Blueprint $t) => $t->dropColumn('completed_at'));
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['standing', 'standing_changed_at']));
    }
};