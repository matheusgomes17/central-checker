<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * The billing states the product has to behave differently in.
 *
 * The subscription is prepaid via Pix: there is no gateway retrying a failed
 * charge, so there is no "past due" state. A period either still has time
 * left or it does not — reading that boundary consistently at every call
 * site is the point of this enum.
 */
enum SubscriptionStatus: string
{
    /** Nunca assinou; no plano gratuito. */
    case None = 'none';

    /** Em trial (prazo), acesso ao gratuito. */
    case Trialing = 'trialing';

    /** Assinatura paga, período no futuro. */
    case Active = 'active';

    /** Assinatura ativa marcada para não renovar; ainda tem acesso até o fim do período. */
    case Expiring = 'expiring';

    /** Período acabou; caiu no plano gratuito (acesso normal ao gratuito, não é bloqueio). */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem assinatura',
            self::Trialing => 'Em teste',
            self::Active => 'Ativa',
            self::Expiring => 'Expirando',
            self::Expired => 'Expirada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active, self::Trialing => 'success',
            self::Expiring, self::Expired => 'warning',
            self::None => 'gray',
        };
    }

    /**
     * Whether the product should be nudging the team to act.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Expiring, self::Expired], true);
    }
}
