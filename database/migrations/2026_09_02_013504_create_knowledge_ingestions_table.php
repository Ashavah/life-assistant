<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_ingestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('decision', 32)->nullable();
            $table->string('source_type', 16);
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->longText('temporary_text')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'character_id', 'created_at']);
            $table->index(['user_id', 'character_id', 'content_hash'], 'knowledge_ingestions_owner_hash_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingestions');
    }
};
