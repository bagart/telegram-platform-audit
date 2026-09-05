<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('actor_type')->index();
            $table->string('actor_id')->index();
            $table->string('actor_display_name')->nullable();
            $table->string('bot_id')->nullable()->index();
            $table->bigInteger('chat_id')->nullable()->index();
            $table->string('subject_type')->index();
            $table->string('subject_id')->index();
            $table->string('operation')->index();
            $table->json('old_state')->nullable();
            $table->json('new_state')->nullable();
            $table->string('source');
            $table->string('source_version')->nullable();
            $table->json('metadata')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->smallInteger('schema_version')->default(1);
            $table->bigInteger('sequence')->unsigned()->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at');

            $table->index(['bot_id', 'operation', 'occurred_at']);
            $table->index(['bot_id', 'actor_type', 'actor_id']);
            $table->index(['bot_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
