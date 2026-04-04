<?php

namespace App\Domain\Sales\ValueObjects;

use InvalidArgumentException;

final class DocumentStatus
{
    public const DRAFT = 'DRAFT';
    public const ISSUED = 'ISSUED';
    public const VOID = 'VOID';
    public const CANCELED = 'CANCELED';

    private const ALLOWED = [
        self::DRAFT,
        self::ISSUED,
        self::VOID,
        self::CANCELED,
    ];

    private string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Estado de documento invalido: ' . $value);
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    public function isClosed(): bool
    {
        return in_array($this->value, [self::VOID, self::CANCELED], true);
    }
}
