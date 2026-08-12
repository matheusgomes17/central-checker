<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_executions', function (Blueprint $table): void {
            // Se ESTA execução consumiu a franquia do plano — false para grants,
            // cobranças em crédito e execuções grátis/não cobertas.
            $table->boolean('consumed_franquia')->default(false)->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('check_executions', function (Blueprint $table): void {
            $table->dropColumn('consumed_franquia');
        });
    }
};
