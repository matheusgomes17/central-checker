<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A command that always reports "all clear" is worse than no command, so each
 * finding it can produce is provoked here on purpose.
 */
class TenantAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The boilerplate has no scoped domain model yet, so the fixture one joins
     * the scanned paths. Without it the index cases would pass for the wrong
     * reason: the command would stop earlier, complaining about a table with
     * no scoped model at all.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->push('tenancy.model_paths', base_path('tests/Fixtures/Models'));
    }

    public function test_the_shipped_schema_is_clean(): void
    {
        $this->artisan('tenant:audit')->assertSuccessful();
    }

    /**
     * The leak this command exists to catch: a table carrying team_id that no
     * model scopes. Nothing stops a query on it from crossing tenants.
     */
    public function test_it_reports_a_tenant_table_with_no_scoped_model(): void
    {
        Schema::create('orphaned_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained();
            $table->index('team_id');
        });

        $this->artisan('tenant:audit')
            ->expectsTable(['Tabela', 'Problema'], [
                ['orphaned_records', 'tem team_id mas nenhum model usa BelongsToTenant'],
            ])
            ->assertFailed();
    }

    /**
     * The fixture model is scoped, so the only thing left to complain about is
     * the missing index — which is what makes every scoped query scan.
     */
    public function test_it_reports_a_scoped_table_with_no_usable_index(): void
    {
        Schema::create('tenant_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
        });

        $this->artisan('tenant:audit')
            ->expectsTable(['Tabela', 'Problema'], [
                ['tenant_resources', 'nenhum índice começa por team_id'],
            ])
            ->assertFailed();
    }

    /**
     * An index whose first column is something else never answers a lookup on
     * team_id alone. Counting it would call the table fine while it scans.
     */
    public function test_a_composite_index_not_starting_with_the_tenant_column_does_not_count(): void
    {
        Schema::create('tenant_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
            $table->index(['name', 'team_id']);
        });

        $this->artisan('tenant:audit')
            ->expectsTable(['Tabela', 'Problema'], [
                ['tenant_resources', 'nenhum índice começa por team_id'],
            ])
            ->assertFailed();
    }

    public function test_a_scoped_table_with_a_leading_tenant_index_passes(): void
    {
        Schema::create('tenant_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
            $table->index(['team_id', 'name']);
        });

        $this->artisan('tenant:audit')->assertSuccessful();
    }
}
