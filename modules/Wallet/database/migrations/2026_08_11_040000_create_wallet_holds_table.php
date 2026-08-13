<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('status')->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // O expurgo varre holds ativos vencidos; o disponível soma holds ativos.
            $table->index(['status', 'expires_at']);
            $table->index('wallet_id');
            // Índice liderado por team_id: sem ele o tenant:audit acusa a tabela
            // (foreignId()->constrained() não cria índice sozinho no SQLite).
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
    }
};
