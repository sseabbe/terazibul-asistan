<?php

declare(strict_types=1);

use Core\Migration;
use Core\Schema\Blueprint;
use Core\Schema\Schema;

class CreateAssistantConversationEventsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_conversation_events')) {
            return;
        }

        Schema::create('assistant_conversation_events', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 32)->default('');
            $table->unsignedInteger('turn_id')->default(0);
            $table->text('user_message_redacted');
            $table->text('assistant_message_redacted');
            $table->string('intent', 40)->default('');
            $table->string('category', 80)->default('');
            $table->string('subcategory', 80)->default('');
            $table->boolean('ready_for_products')->default(false);
            $table->integer('search_total')->default(0);
            $table->boolean('show_products')->default(false);
            $table->string('handoff_reason', 40)->default('');
            $table->boolean('evidence_guard_triggered')->default(false);
            $table->boolean('zero_result')->default(false);
            $table->string('classification_source', 40)->default('');
            $table->string('pending_question_field', 40)->default('');
            $table->text('answered_fields');
            $table->string('blocking_filter', 40)->default('');
            $table->string('error_code', 40)->default('');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('assistant_version', 32)->default('');
            $table->text('signals');
            $table->timestamp('created_at')->nullable();
            $table->index('conversation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversation_events');
    }
}
