<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate the display timezone.
     *
     * Restricted to identifiers PHP actually knows: anything else would throw
     * the first time a date is rendered for that user.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function timezoneRules(): array
    {
        return ['nullable', 'string', 'timezone:all'];
    }

    /**
     * Get the validation rules used to validate the preferred language.
     *
     * @return array<int, ValidationRule|In|array<mixed>|string>
     */
    protected function localeRules(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('accounts.locales', []);

        return ['nullable', 'string', Rule::in(array_keys($locales))];
    }

    /**
     * Get the validation rules used to validate avatar uploads.
     *
     * The mime check reads the file rather than trusting its extension, and
     * the dimension cap keeps a decompression bomb out of the image pipeline.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function avatarRules(): array
    {
        return [
            'nullable',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:2048',
            'dimensions:max_width=4000,max_height=4000',
        ];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
