<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use NimblePHP\Framework\Config;

class Przelewy24ConfigDTO
{

    public function __construct(
        public int $merchantId,
        public int $posId,
        public string $apiKey,
        public string $crc,
        public bool $sandbox = true
    ) {
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
