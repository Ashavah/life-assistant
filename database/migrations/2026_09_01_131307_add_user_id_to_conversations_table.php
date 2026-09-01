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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::table('conversations')
            ->orderBy('id')
            ->eachById(function (object $conversation): void {
                $userId = DB::table('characters')
                    ->where('id', $conversation->character_id)
                    ->value('user_id');

                DB::table('conversations')
                    ->where('id', $conversation->id)
                    ->update(['user_id' => $userId]);
            });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->index(['user_id', 'status', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'last_message_at']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
