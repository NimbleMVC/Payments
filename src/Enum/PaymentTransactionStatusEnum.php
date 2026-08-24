<?php

namespace NimblePHP\Payments\Enum;

enum PaymentTransactionStatusEnum: string
{

    case pending = 'pending';

    case processing = 'processing';

    case completed = 'completed';

    case failed = 'failed';

    case cancelled = 'cancelled';

    /** PAY-H03: terminal statuses are immutable once reached - see PaymentTransactionModel::applyProviderUpdate(). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::completed, self::failed, self::cancelled => true,
            self::pending, self::processing => false,
        };
    }

}
