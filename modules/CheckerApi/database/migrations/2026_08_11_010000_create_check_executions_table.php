<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome');
            $table->unsignedInteger('duration_ms');
            $table->json('input_masked');
            // Cifrado em repouso pelo cast do model; texto porque o corpo cifrado
            // não cabe garantidamente num JSON nativo.
            $table->text('result')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // O escopo global consulta por team_id em toda leitura.
            $table->index('team_id');
            // O relatório de taxa de sucesso agrupa por check e desfecho.
            $table->index(['check_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_executions');
    }
};
