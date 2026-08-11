<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicada pelo pacote, com duas mudanças.
 *
 * As tabelas só são criadas em local, porque o Telescope é dependência de
 * desenvolvimento: em produção elas ficariam vazias para sempre, e a de
 * entries é das que mais crescem quando alguém liga o painel sem querer.
 */
return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        $connection = config('telescope.storage.database.connection');

        return is_string($connection) ? $connection : null;
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $schema = Schema::connection($this->getConnection());

        $schema->create('telescope_entries', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('uuid');
            $table->uuid('batch_id');
            $table->string('family_hash')->nullable();
            $table->boolean('should_display_on_index')->default(true);
            $table->string('type', 20);
            $table->longText('content');
            $table->dateTime('created_at')->nullable();

            $table->unique('uuid');
            $table->index('batch_id');
            $table->index('family_hash');
            $table->index('created_at');
            $table->index(['type', 'should_display_on_index']);
        });

        $schema->create('telescope_entries_tags', function (Blueprint $table): void {
            $table->uuid('entry_uuid');
            $table->string('tag');

            $table->primary(['entry_uuid', 'tag']);
            $table->index('tag');

            $table->foreign('entry_uuid')
                ->references('uuid')
                ->on('telescope_entries')
                ->cascadeOnDelete();
        });

        $schema->create('telescope_monitoring', function (Blueprint $table): void {
            $table->string('tag')->primary();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('telescope_entries_tags');
        $schema->dropIfExists('telescope_entries');
        $schema->dropIfExists('telescope_monitoring');
    }
};
