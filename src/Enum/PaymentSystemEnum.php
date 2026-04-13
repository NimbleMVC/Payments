<?php

namespace NimblePHP\Payments\Enum;

use InvalidArgumentException;

enum PaymentSystemEnum: string
{
    case przelewy24 = 'przelewy24';

    public static function fromString(string $type): self
    {
        $normalizedType = strtolower(trim($type));

        return self::tryFrom($normalizedType)
            ?? throw new InvalidArgumentException('Unsupported payment system: ' . $type);
    }
}
