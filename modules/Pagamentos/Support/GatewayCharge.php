<?php

declare(strict_types=1);

namespace Modules\Pagamentos\Support;

/**
 * The immutable charge returned by a payment gateway after a Pix charge is created.
 */
final readonly class GatewayCharge
{
    private function __construct(
        private string $externalId,
        private string $copyPaste,
        private ?string $qrCode,
        private string $status,
    ) {}

    public static function from(string $externalId, string $copyPaste, ?string $qrCode, string $status): self
    {
        return new self($externalId, $copyPaste, $qrCode, $status);
    }

    public function externalId(): string
    {
        return $this->externalId;
    }

    public function copyPaste(): string
    {
        return $this->copyPaste;
    }

    public function qrCode(): ?string
    {
        return $this->qrCode;
    }

    public function status(): string
    {
        return $this->status;
    }
}
