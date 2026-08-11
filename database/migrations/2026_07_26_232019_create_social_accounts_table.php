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
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id');
            $table->timestamps();

            // A identidade é o par (provedor, id no provedor), nunca o e-mail:
            // e-mail muda de dono, id do provedor não. O índice único é o que
            // impede a mesma conta social de ser vinculada a dois usuários.
            $table->unique(['provider', 'provider_id']);

            // Um usuário tem no máximo uma conta por provedor.
            $table->unique(['user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
