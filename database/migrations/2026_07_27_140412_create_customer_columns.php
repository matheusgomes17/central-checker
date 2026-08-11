<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing belongs to the team, not to the person.
 *
 * A SaaS sold to organizations is paid for by the organization: the trial
 * period is the team's, and the person who started it can leave without
 * taking it with them. Putting this column on users would tie the account to
 * whoever happened to sign up first.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn([
                'trial_ends_at',
            ]);
        });
    }
};
