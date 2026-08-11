<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A deliberately small user agent reader.
 *
 * Listing browser sessions only needs enough to tell one device from another
 * at a glance. Full user agent parsing is a moving target that belongs in a
 * dedicated package; matching a handful of well known tokens covers the
 * common cases and degrades to UNKNOWN instead of guessing wrong.
 *
 * Order matters in both maps: Edge announces itself as Chrome, Chrome as
 * Safari, and iPadOS as Mac. The more specific token has to win.
 *
 * Values are returned untranslated so the class stays free of the container;
 * the view is what decides how to render them.
 */
final readonly class UserAgent
{
    public const string UNKNOWN = 'Unknown';

    /**
     * @var array<string, string>
     */
    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox',
        'Safari/' => 'Safari',
    ];

    /**
     * @var array<string, string>
     */
    private const array PLATFORMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public function __construct(private ?string $value) {}

    public function browser(): string
    {
        return $this->match(self::BROWSERS);
    }

    public function platform(): string
    {
        return $this->match(self::PLATFORMS);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function match(array $tokens): string
    {
        if ($this->value === null || $this->value === '') {
            return self::UNKNOWN;
        }

        foreach ($tokens as $token => $label) {
            if (str_contains($this->value, $token)) {
                return $label;
            }
        }

        return self::UNKNOWN;
    }
}
