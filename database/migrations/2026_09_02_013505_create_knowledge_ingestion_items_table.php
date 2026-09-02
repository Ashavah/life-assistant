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
        Schema::create('knowledge_ingestion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_ingestion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('action', 16);
            $table->string('memory_key', 120);
            $table->string('category', 80);
            $table->text('content');
            $table->unsignedTinyInteger('importance');
            $table->decimal('confidence', 4, 3);
            $table->text('reason')->nullable();
            $table->string('source_reference')->nullable();
            $table->boolean('selected')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['knowledge_ingestion_id', 'character_id', 'memory_key'],
                'knowledge_items_ingestion_character_key_unique',
            );
            $table->index(['knowledge_ingestion_id', 'selected']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingestion_items');
    }
};
