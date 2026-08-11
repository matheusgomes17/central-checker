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
        Schema::table('users', function (Blueprint $table): void {
            // Caminho no disco, não URL: o bucket e o endpoint podem mudar
            // entre ambientes, e uma URL gravada no banco envelhece mal.
            $table->string('avatar_path')->nullable()->after('email_verified_at');

            // Nulo significa "usa o padrão da aplicação". Guardar o padrão em
            // cada linha faz uma futura mudança de padrão não valer para quem
            // nunca escolheu nada.
            $table->string('timezone', 64)->nullable()->after('avatar_path');
            $table->string('locale', 10)->nullable()->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_path', 'timezone', 'locale']);
        });
    }
};
