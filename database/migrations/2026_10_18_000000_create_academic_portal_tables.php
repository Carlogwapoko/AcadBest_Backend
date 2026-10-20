<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('program', 20);
            $table->unsignedTinyInteger('year_level');
            $table->string('section', 10)->default('A');
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->unsignedTinyInteger('units');
            $table->string('days');
            $table->string('meeting_time');
            $table->string('room');
            $table->string('program')->default('All');
            $table->string('year_level')->default('All');
            $table->json('grade_weights');
            $table->foreignId('faculty_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('student_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('subject_code', 10);
            $table->timestamps();
            $table->unique(['student_id', 'subject_code']);
            $table->foreign('subject_code')->references('code')->on('subjects')->cascadeOnDelete();
        });

        Schema::create('enrollment_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30);
            $table->date('date_of_birth');
            $table->string('sex');
            $table->text('address');
            $table->string('program', 20);
            $table->unsignedTinyInteger('year_level');
            $table->string('application_type');
            $table->string('previous_school');
            $table->string('guardian_name');
            $table->string('guardian_phone', 30);
            $table->string('status')->default('Pending');
            $table->text('reviewer_note')->nullable();
            $table->boolean('account_issued')->default(false);
            $table->json('requested_subjects')->nullable();
            $table->json('history');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('subject_code', 10);
            $table->string('term', 20);
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'subject_code', 'term']);
            $table->foreign('subject_code')->references('code')->on('subjects')->cascadeOnDelete();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('priority')->default('Normal');
            $table->date('publish_date');
            $table->date('valid_until')->nullable();
            $table->string('program')->default('All');
            $table->string('year_level')->default('All');
            $table->string('section')->default('All');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('purpose');
            $table->unsignedTinyInteger('copies');
            $table->date('date_needed');
            $table->text('remarks')->nullable();
            $table->string('status')->default('Pending');
            $table->json('history');
            $table->timestamps();
        });

        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });

        Schema::create('portal_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['role_id', 'module_key']);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('module_access');
        Schema::dropIfExists('portal_settings');
        Schema::dropIfExists('portal_notifications');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('enrollment_applications');
        Schema::dropIfExists('student_subject');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('students');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['phone', 'avatar_path', 'is_active']);
        });
        Schema::dropIfExists('roles');
    }
};
