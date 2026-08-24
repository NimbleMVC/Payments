<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use InvalidArgumentException;
use NimblePHP\Framework\Config;

class Przelewy24ConfigDTO
{

    /**
     * PAY-M02: fail fast - constructing an incomplete config is impossible
     * rather than silently producing merchantId=0/posId=0/apiKey=''/crc=''
     * that only surfaces as a confusing error much later (an
     * uninitialized-property Error, or a rejected/malformed P24 request).
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public int $merchantId,
        public int $posId,
        public string $apiKey,
        public string $crc,
        public bool $sandbox = true
    ) {
        if ($this->merchantId <= 0) {
            throw new InvalidArgumentException('Przelewy24 config: merchantId must be a positive integer.');
        }

        if ($this->posId <= 0) {
            throw new InvalidArgumentException('Przelewy24 config: posId must be a positive integer.');
        }

        if ($this->apiKey === '') {
            throw new InvalidArgumentException('Przelewy24 config: apiKey must not be empty.');
        }

        if ($this->crc === '') {
            throw new InvalidArgumentException('Przelewy24 config: crc must not be empty.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            merchantId: (int)Config::get('PRZELEWY24_MERCHANT_ID', Config::get('PRZELEWY24_MECHANT_ID', '')),
            posId: (int)Config::get('PRZELEWY24_POS_ID', ''),
            apiKey: (string)Config::get('PRZELEWY24_API_KEY', ''),
            crc: (string)Config::get('PRZELEWY24_CRC', ''),
            sandbox: Config::get('PRZELEWY24_API', 'sandbox') !== 'production'
        );
    }

}
