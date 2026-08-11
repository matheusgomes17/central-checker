<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferences are stored as what the user turned *off*.
 *
 * Storing the opt-outs rather than the full matrix means a topic or channel
 * added later starts switched on for everyone, which is what people expect —
 * with the opposite shape, every existing account would silently miss the new
 * notification until they went and enabled it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_opt_outs')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_opt_outs');
        });
    }
};
