<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Support;

/**
 * The immutable payer identification sent to the gateway when a Pix charge is created.
 */
final readonly class PayerData
{
    private function __construct(
        private string $name,
        private string $email,
        private ?string $document,
        private ?string $phone,
    ) {}

    public static function for(string $name, string $email, ?string $document = null, ?string $phone = null): self
    {
        return new self($name, $email, $document, $phone);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function document(): ?string
    {
        return $this->document;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }
}
