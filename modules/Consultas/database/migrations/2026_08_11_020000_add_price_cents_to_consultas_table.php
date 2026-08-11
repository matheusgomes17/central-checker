<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultas', function (Blueprint $table): void {
            // Preço em crédito, em centavos. Zero = sem cobrança por crédito
            // (consulta gratuita, ou coberta por plano quando a Fase D chegar).
            $table->unsignedInteger('price_cents')->default(0)->after('provider_key');
        });
    }

    public function down(): void
    {
        Schema::table('consultas', function (Blueprint $table): void {
            $table->dropColumn('price_cents');
        });
    }
};
