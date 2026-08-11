<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasNotificationPreferences;
use App\Concerns\HasProfile;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Override;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property bool $is_super_admin
 * @property string|null $avatar_path
 * @property string|null $timezone
 * @property string|null $locale
 * @property array<string, array<int, string>>|null $notification_opt_outs
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'email', 'password', 'current_team_id', 'avatar_path', 'timezone', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasNotificationPreferences, HasProfile, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable {
        // Os dois traits declaram teams(). O da aplicação é o que representa
        // os times de que a pessoa participa e é usado no app inteiro; o do
        // pacote de permissões devolve os times pelos quais ela tem papel, que
        // é outra pergunta. Renomeado em vez de descartado para continuar
        // disponível quando for essa a pergunta.
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as teamsWithRoles;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'notification_opt_outs' => 'array',
        ];
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Who may reach the operator panel.
     *
     * Until now the only thing keeping the panel shut was Filament's own
     * fallback — no contract meant local environments only. That is a safe
     * default, not an access rule; this is the rule.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperAdmin();
    }
}
