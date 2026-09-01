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
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $ownerId = DB::table('users')->oldest('id')->value('id');

        if ($ownerId !== null) {
            DB::table('characters')->whereNull('user_id')->update(['user_id' => $ownerId]);
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_slug_unique');
            $table->unique(['user_id', 'slug']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->unique('slug');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
