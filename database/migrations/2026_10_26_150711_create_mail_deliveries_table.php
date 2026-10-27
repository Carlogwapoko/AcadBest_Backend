<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->string('subject');
            $table->text('body');
            $table->string('status', 10)->default('queued'); // queued | sending | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
    }
};