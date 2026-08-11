<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the people who can fix it that a charge failed.
 *
 * Says which attempt this is, because "your payment failed" on the first retry
 * and on the last one call for very different urgency, and a message that
 * cannot tell them apart gets ignored on both.
 */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly int $amountDue,
        public readonly int $attempt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Payment failed for :team', ['team' => $this->team->name]))
            ->line(__('We could not charge the card on file for :team.', ['team' => $this->team->name]))
            ->line(__('Amount due: :amount', ['amount' => $this->formattedAmount()]))
            ->line(__('This was attempt number :attempt. Access continues while we retry.', [
                'attempt' => $this->attempt,
            ]))
            ->action(__('Update payment method'), route('billing.edit'))
            ->line(__('If the card is not updated, the subscription will eventually be cancelled.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->team->id,
            'amount_due' => $this->amountDue,
            'attempt' => $this->attempt,
        ];
    }

    private function formattedAmount(): string
    {
        return number_format($this->amountDue / 100, 2);
    }
}
