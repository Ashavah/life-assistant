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
        Schema::dropIfExists('character_service_connection');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('character_service_connection', function (Blueprint $table) {
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_connection_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->primary(['character_id', 'service_connection_id']);
        });
    }
};
