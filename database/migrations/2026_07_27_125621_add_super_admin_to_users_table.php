<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform administration lives on the user, not in the role tables.
 *
 * The permission package's team mode puts the team id inside the primary key
 * of the assignment table, so an assignment with no team cannot be stored at
 * all. Super admin is exactly that: a grant that belongs to no team. Bending
 * the package's primary key to fit would be fighting the tool; a column on the
 * user says the same thing and says it plainly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }
};
