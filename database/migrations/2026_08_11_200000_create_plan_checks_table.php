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
        Schema::create('plan_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('plan');
            $table->foreignId('check_id')->constrained('checks')->cascadeOnDelete();
            // Nulo = ilimitado.
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();

            $table->unique(['plan', 'check_id']);
            $table->index('check_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_checks');
    }
};
