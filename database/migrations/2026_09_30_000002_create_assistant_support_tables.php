<?php

declare(strict_types=1);

use Core\Migration;
use Core\Schema\Blueprint;
use Core\Schema\Schema;

class CreateAssistantSupportTables extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('assistant_support_threads')) {
            Schema::create('assistant_support_threads', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_token', 64);
                $table->string('public_token', 64)->unique();
                $table->string('status', 20)->default('waiting');
                $table->string('question', 500)->default('');
                $table->text('context');
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->index('visitor_token');
                $table->index('status');
                $table->index('last_message_at');
            });
        }

        if (!Schema::hasTable('assistant_support_messages')) {
            Schema::create('assistant_support_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('thread_id');
                $table->string('sender_type', 20);
                $table->text('body');
                $table->timestamp('created_at')->nullable();
                $table->index('thread_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_support_messages');
        Schema::dropIfExists('assistant_support_threads');
    }
}
