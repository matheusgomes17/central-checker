<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A token belongs to a person acting on behalf of one team.
 *
 * Sanctum ties a token to whoever created it and stops there. In a product
 * where the same person belongs to several teams, that is not enough: a token
 * with no team would act on whichever team happened to be current, and "which
 * team" would depend on the last thing the owner did in the browser.
 *
 * Naming the team on the token also makes revocation meaningful — losing a
 * team means losing exactly the tokens that could reach it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
