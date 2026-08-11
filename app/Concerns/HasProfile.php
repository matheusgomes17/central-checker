<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * How a person is presented, and what they chose about it.
 *
 * Pulled out of the User model when it crossed the size the architecture rule
 * allows. That rule is about a model staying one responsibility wide, and
 * these three had drifted into being a second one: rendering.
 */
trait HasProfile
{
    /**
     * Public URL for the user's avatar, or null when they have not set one.
     *
     * Built on read instead of stored: the bucket and endpoint differ between
     * environments, so a URL persisted at upload time goes stale.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        return Storage::disk(Config::string('accounts.avatar_disk'))->url($this->avatar_path);
    }

    /**
     * The timezone to render dates in for this user.
     *
     * The application clock stays in UTC; this is a display concern only.
     * Falling back to the application timezone keeps a user who never chose
     * one following the default when it changes.
     */
    public function effectiveTimezone(): string
    {
        if ($this->timezone !== null && in_array($this->timezone, timezone_identifiers_list(), true)) {
            return $this->timezone;
        }

        return Config::string('app.timezone');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
