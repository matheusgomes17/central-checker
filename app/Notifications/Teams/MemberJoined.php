<?php

declare(strict_types=1);

namespace App\Notifications\Teams;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone accepted an invitation and is now on the team.
 *
 * The one notification the boilerplate ships that uses all three channels, so
 * that the wiring — mail, in-app record, live push — has something real
 * exercising it rather than being described in a comment.
 */
class MemberJoined extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences;

    public function __construct(
        public readonly Team $team,
        public readonly User $member,
    ) {
        // A fila alta é para o que alguém está esperando ver acontecer, e esta
        // chega enquanto a pessoa ainda está olhando a tela do time.
        $this->onQueue('high');
    }

    public function topic(): NotificationTopic
    {
        return NotificationTopic::Team;
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function channels(): array
    {
        return [NotificationChannel::Mail, NotificationChannel::Database, NotificationChannel::Broadcast];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':name joined :team', ['name' => $this->member->name, 'team' => $this->team->name]))
            ->markdown('mail.teams.member-joined', [
                'team' => $this->team,
                'member' => $this->member,
                'url' => route('teams.edit', ['team' => $this->team->slug]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'member_id' => $this->member->id,
            'member_name' => $this->member->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => __(':name joined :team', [
                'name' => $this->member->name,
                'team' => $this->team->name,
            ]),
            'team_id' => $this->team->id,
        ]);
    }
}
