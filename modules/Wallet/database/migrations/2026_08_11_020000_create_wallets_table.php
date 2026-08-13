<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            // Uma carteira por time — o unique é o que garante isso mesmo sob
            // criação concorrente, quando o firstOrCreate perde a corrida.
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
