<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role_id', 'is_active']);
        });
        Schema::table('students', function (Blueprint $table) {
            $table->index(['program', 'year_level', 'section']);
        });
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->index(['status', 'account_issued', 'created_at']);
            $table->index(['email', 'reference']);
        });
        Schema::table('subjects', function (Blueprint $table) {
            $table->index(['program', 'year_level']);
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->index(['publish_date', 'valid_until']);
            $table->index(['program', 'year_level', 'section']);
        });
        Schema::table('service_requests', function (Blueprint $table) {
            $table->index(['student_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        Schema::table('portal_notifications', function (Blueprint $table) {
            $table->index(['student_id', 'is_read', 'created_at']);
        });
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', fn (Blueprint $table) => $table->dropIndex(['user_id', 'expires_at']));
        Schema::table('portal_notifications', fn (Blueprint $table) => $table->dropIndex(['student_id', 'is_read', 'created_at']));
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropIndex(['student_id', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
        });
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['publish_date', 'valid_until']);
            $table->dropIndex(['program', 'year_level', 'section']);
        });
        Schema::table('subjects', fn (Blueprint $table) => $table->dropIndex(['program', 'year_level']));
        Schema::table('enrollment_applications', function (Blueprint $table) {
            $table->dropIndex(['status', 'account_issued', 'created_at']);
            $table->dropIndex(['email', 'reference']);
        });
        Schema::table('students', fn (Blueprint $table) => $table->dropIndex(['program', 'year_level', 'section']));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['role_id', 'is_active']));
    }
};
