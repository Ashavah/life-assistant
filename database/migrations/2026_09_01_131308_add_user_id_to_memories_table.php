<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::table('memories')
            ->orderBy('id')
            ->eachById(function (object $memory): void {
                $userId = DB::table('characters')
                    ->where('id', $memory->character_id)
                    ->value('user_id');

                DB::table('memories')
                    ->where('id', $memory->id)
                    ->update(['user_id' => $userId]);
            });

        Schema::table('memories', function (Blueprint $table) {
            $table->dropUnique(['character_id', 'memory_key']);
            $table->unique(['user_id', 'character_id', 'memory_key']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'character_id', 'memory_key']);
            $table->unique(['character_id', 'memory_key']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
