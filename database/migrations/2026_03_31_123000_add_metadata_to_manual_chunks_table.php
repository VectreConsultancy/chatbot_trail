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
        if (Schema::hasColumn('manual_chunks', 'metadata')) {
            return;
        }

        Schema::table('manual_chunks', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('manual_chunks', 'metadata')) {
            return;
        }

        Schema::table('manual_chunks', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
