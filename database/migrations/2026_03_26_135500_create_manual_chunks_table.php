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
        Schema::create('manual_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('source_path');
            $table->string('source_hash', 64);
            $table->unsignedInteger('chunk_order');
            $table->longText('content');
            $table->timestamps();

            $table->index(['source_path', 'source_hash']);
            $table->unique(['source_path', 'chunk_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_chunks');
    }
};
