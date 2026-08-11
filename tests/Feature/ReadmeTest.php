<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The phase's acceptance criterion is someone new getting the project running
 * on their own, and what breaks that is not prose written badly: it is a
 * command that was renamed and a link pointing at a file that left.
 *
 * Documentation is not tested by reading it. These assertions cover the part
 * that rots without warning; the rest stays the writer's job.
 */
class ReadmeTest extends TestCase
{
    public function test_the_setup_script_is_executable(): void
    {
        $script = base_path('bin/setup');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'bin/setup não está executável — o README manda rodar ./bin/setup.');
    }

    public function test_every_composer_script_it_mentions_exists(): void
    {
        preg_match_all('/`composer ([a-z:]+)`/', $this->readme(), $matches);

        $mentioned = array_unique($matches[1]);

        $this->assertNotEmpty($mentioned);

        /** @var array{scripts: array<string, mixed>} $composer */
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        foreach ($mentioned as $script) {
            $this->assertArrayHasKey(
                $script,
                $composer['scripts'],
                "O README cita `composer {$script}`, que não existe no composer.json.",
            );
        }
    }

    public function test_every_artisan_command_it_mentions_exists(): void
    {
        preg_match_all('/`php artisan ([a-z0-9:_-]+)`/', $this->readme(), $matches);

        $mentioned = array_unique($matches[1]);

        $this->assertNotEmpty($mentioned);

        $registered = array_keys(Artisan::all());

        foreach ($mentioned as $command) {
            $this->assertContains(
                $command,
                $registered,
                "O README cita `php artisan {$command}`, que não está registrado.",
            );
        }
    }

    public function test_every_link_it_points_at_exists(): void
    {
        preg_match_all('/\]\(([^)]+)\)/', $this->readme(), $matches);

        $links = array_filter(
            array_unique($matches[1]),
            fn (string $link): bool => ! str_starts_with($link, 'http') && ! str_starts_with($link, '<'),
        );

        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            $this->assertFileExists(base_path($link), "O README aponta para [{$link}], que não existe.");
        }
    }

    private function readme(): string
    {
        return (string) file_get_contents(base_path('README.md'));
    }
}
