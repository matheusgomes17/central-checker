<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Assinado: crédito é positivo, débito (charge) é negativo.
            $table->bigInteger('amount_cents');
            // O saldo depois desta linha. O razão é a fonte da verdade; a coluna
            // em wallets é cache reconciliável contra a soma daqui.
            $table->bigInteger('balance_after_cents');
            $table->string('description');
            // Referência polimórfica sem FK: liga a linha à execução (ou, depois,
            // ao pagamento) sem o módulo Carteira depender do módulo Consultas.
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
