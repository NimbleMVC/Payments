<?php

namespace NimblePHP\Payments\Enum;

enum PaymentTransactionStatusEnum: string
{

    case pending = 'pending';

    case processing = 'processing';

    case completed = 'completed';

    case failed = 'failed';

    case cancelled = 'cancelled';

}
