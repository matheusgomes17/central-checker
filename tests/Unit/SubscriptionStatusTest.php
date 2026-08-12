<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Billing\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

/**
 * Which states the product should be nudging the team about.
 *
 * The list is short enough to look correct at a glance and that is the danger:
 * dropping one entry silently stops the nudging for the state it named, and
 * the state that stops nudging first is the one nobody complains about.
 */
class SubscriptionStatusTest extends TestCase
{
    /**
     * @return array<string, array{SubscriptionStatus}>
     */
    public static function statusesThatNeedAttention(): array
    {
        return [
            'marcada para não renovar' => [SubscriptionStatus::Expiring],
            'período acabou' => [SubscriptionStatus::Expired],
        ];
    }

    /**
     * @return array<string, array{SubscriptionStatus}>
     */
    public static function statusesThatDoNot(): array
    {
        return [
            'sem assinatura' => [SubscriptionStatus::None],
            'em teste' => [SubscriptionStatus::Trialing],
            'ativa' => [SubscriptionStatus::Active],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesThatNeedAttention')]
    public function test_it_asks_for_attention(SubscriptionStatus $status): void
    {
        $this->assertTrue($status->needsAttention());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesThatDoNot')]
    public function test_it_stays_quiet(SubscriptionStatus $status): void
    {
        $this->assertFalse($status->needsAttention());
    }

    /**
     * @return array<string, array{SubscriptionStatus, string}>
     */
    public static function statusesAndTheirLabel(): array
    {
        return [
            'none' => [SubscriptionStatus::None, 'Sem assinatura'],
            'trialing' => [SubscriptionStatus::Trialing, 'Em teste'],
            'active' => [SubscriptionStatus::Active, 'Ativa'],
            'expiring' => [SubscriptionStatus::Expiring, 'Expirando'],
            'expired' => [SubscriptionStatus::Expired, 'Expirada'],
        ];
    }

    /**
     * Pins the labels to pt_BR: there is no translation catalog behind
     * these, so an English literal here would ship English on the product.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statusesAndTheirLabel')]
    public function test_label_is_in_portuguese(SubscriptionStatus $status, string $expected): void
    {
        $this->assertSame($expected, $status->label());
    }
}
