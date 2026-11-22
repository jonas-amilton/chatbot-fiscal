<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->string('tool_name', 64)->nullable();
            $table->json('tool_payload')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
