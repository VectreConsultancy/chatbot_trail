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
        Schema::create('chat_response_memories', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->text('normalized_question');
            $table->longText('answer');
            $table->boolean('helpful')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('helpful');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_response_memories');
    }
};
