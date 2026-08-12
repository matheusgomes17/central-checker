<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use Tests\TestCase;

/**
 * Render tests for the "fire" theme's base components (stat, glow, plan-timer).
 *
 * These are pure display Blade components, so no database is needed — unlike
 * most Feature tests in this suite, this one skips RefreshDatabase.
 */
class FireComponentsTest extends TestCase
{
    public function test_stat_renders_label_and_value(): void
    {
        $this->blade('<x-fire.stat label="Consultas" value="1.240" />')
            ->assertSee('Consultas')
            ->assertSee('1.240');
    }

    public function test_stat_renders_hint_when_given(): void
    {
        $this->blade('<x-fire.stat label="Consultas" value="1.240" hint="Nos últimos 30 dias" />')
            ->assertSee('Nos últimos 30 dias');
    }

    public function test_stat_neutral_tone_has_no_ok_or_fire_marker(): void
    {
        $this->blade('<x-fire.stat label="Consultas" value="1.240" />')
            ->assertDontSee('Indicador positivo')
            ->assertDontSee('Indicador em destaque');
    }

    public function test_stat_ok_tone_renders_accessible_marker(): void
    {
        $this->blade('<x-fire.stat label="Consultas" value="1.240" tone="ok" />')
            ->assertSee('Indicador positivo');
    }

    public function test_stat_fire_tone_renders_accessible_marker(): void
    {
        $this->blade('<x-fire.stat label="Consultas" value="1.240" tone="fire" />')
            ->assertSee('Indicador em destaque');
    }

    public function test_glow_renders_its_slot(): void
    {
        $this->blade('<x-fire.glow>Conteúdo do produto</x-fire.glow>')
            ->assertSee('Conteúdo do produto');
    }

    public function test_plan_timer_shows_days_left(): void
    {
        $this->blade('<x-fire.plan-timer label="Plano Pro" :daysLeft="12" :fraction="0.4" />')
            ->assertSee('Plano Pro')
            ->assertSee('12');
    }

    public function test_plan_timer_shows_no_active_plan_when_days_left_is_null(): void
    {
        $this->blade('<x-fire.plan-timer label="Plano Pro" :daysLeft="null" :fraction="0" />')
            ->assertSee('sem plano ativo');
    }
}
