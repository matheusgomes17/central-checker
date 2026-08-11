<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a team can ask to be told about.
 *
 * A closed list, and deliberately not "everything that happens". An outgoing
 * webhook is a promise: once someone builds against an event, changing its
 * shape breaks their code silently. Fewer events, each meant, is the cheaper
 * promise to keep.
 */
enum WebhookEvent: string
{
    case MemberJoined = 'member.joined';
    case MemberRemoved = 'member.removed';
    case InvitationCreated = 'invitation.created';
    case SubscriptionChanged = 'subscription.changed';

    public function label(): string
    {
        return match ($this) {
            self::MemberJoined => __('Member joined'),
            self::MemberRemoved => __('Member removed'),
            self::InvitationCreated => __('Invitation created'),
            self::SubscriptionChanged => __('Subscription changed'),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
