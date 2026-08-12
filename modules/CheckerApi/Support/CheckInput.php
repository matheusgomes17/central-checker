<?php

declare(strict_types=1);

namespace Modules\CheckerApi\Support;

use Illuminate\Support\Str;

/**
 * The immutable input to a check.
 *
 * Owns the masking used before anything is written down: check inputs are
 * third-party personal data (a CPF, a document number), and the raw value must
 * never reach a log or an audit row. Short values are left alone — there is
 * nothing to hide in a two-letter state code.
 */
final readonly class CheckInput
{
    /**
     * @param  array<string, string>  $params
     */
    private function __construct(private array $params) {}

    /**
     * @param  array<string, string>  $params
     */
    public static function fromArray(array $params): self
    {
        return new self($params);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->params;
    }

    public function get(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function masked(): array
    {
        return array_map(
            static function (string $value): string {
                if (Str::length($value) < 6) {
                    return $value;
                }

                $keepStart = Str::substr($value, 0, 3);
                $keepEnd = Str::substr($value, -2);
                $hidden = str_repeat('*', Str::length($value) - 5);

                return $keepStart.$hidden.$keepEnd;
            },
            $this->params,
        );
    }
}
