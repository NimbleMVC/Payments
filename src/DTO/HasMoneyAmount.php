<?php

namespace NimblePHP\Payments\DTO;

use Brick\Money\Currency;
use Brick\Money\Money;
use InvalidArgumentException;
use Throwable;

/**
 * Shared amount/currency handling for DTOs storing `int|Money $amount` plus
 * a separate `string $currency` field (PAY-H04).
 *
 * When $amount is a Money instance, its own currency is always the source
 * of truth - $currency is only meaningful when $amount is a plain int minor
 * amount. This eliminates the class of bug where Money::of('10.00', 'USD')
 * produces the right minor amount but the DTO still carries the unrelated
 * default/explicit $currency field (confirmed manually by the audit:
 * amount=1000 alongside currency=PLN for a USD Money instance).
 */
trait HasMoneyAmount
{

    public int|Money $amount;

    public string $currency = 'PLN';

    public function getAmount(): int
    {
        if (is_int($this->amount)) {
            return $this->amount;
        }

        return $this->amount->getMinorAmount()->toInt();
    }

    public function getCurrency(): string
    {
        if ($this->amount instanceof Money) {
            return $this->amount->getCurrency()->getCurrencyCode();
        }

        return $this->currency;
    }

    /**
     * @throws InvalidArgumentException if the amount is not positive or the
     *         currency is not a recognised ISO 4217 code
     */
    public function assertValidAmount(): void
    {
        if ($this->getAmount() <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $currency = $this->getCurrency();

        try {
            Currency::of($currency);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException("Unsupported currency: {$currency}", 0, $exception);
        }
    }

}
