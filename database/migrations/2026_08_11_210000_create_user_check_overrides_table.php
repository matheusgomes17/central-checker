<?php

declare(strict_types=1);

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
        Schema::create('user_check_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->boolean('blocked')->default(false);
            $table->boolean('granted')->default(false);
            // Preço em créditos customizado para este usuário.
            $table->unsignedInteger('price_cents')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'user_id', 'check_id']);
            $table->index('check_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_check_overrides');
    }
};
