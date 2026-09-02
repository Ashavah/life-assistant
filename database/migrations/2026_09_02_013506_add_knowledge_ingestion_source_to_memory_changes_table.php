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
        Schema::table('memory_changes', function (Blueprint $table) {
            $table->foreignId('source_knowledge_ingestion_id')
                ->nullable()
                ->after('source_message_id')
                ->constrained('knowledge_ingestions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memory_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_knowledge_ingestion_id');
        });
    }
};
