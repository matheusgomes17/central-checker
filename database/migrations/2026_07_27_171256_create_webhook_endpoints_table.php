<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a team wants to be told when something happens.
 *
 * The secret is per endpoint rather than per team: rotating it should break
 * one integration, not every integration a customer has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('description')->nullable();
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            // O escopo consulta por team_id; o índice é o que impede a
            // varredura completa quando a tabela crescer.
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
