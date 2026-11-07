<?php

namespace Tests\Unit;

use App\Services\Catalog\ProductService;
use App\Services\Core\SmsService;
use PHPUnit\Framework\TestCase;

class StockMathTest extends TestCase
{
    public function test_phone_numbers_are_normalised_to_international_format(): void
    {
        $this->assertSame('255712345678', SmsService::normalizePhone('0712 345 678'));
        $this->assertSame('255712345678', SmsService::normalizePhone('+255 712 345 678'));
        $this->assertSame('255712345678', SmsService::normalizePhone('712345678'));
        $this->assertNull(SmsService::normalizePhone('123'));
    }

    public function test_ean13_check_digit(): void
    {
        $service = (new \ReflectionClass(ProductService::class))->newInstanceWithoutConstructor();
        // 400638133393 -> check digit 1 (4006381333931 is a valid EAN-13)
        $this->assertSame(1, $service->ean13CheckDigit('400638133393'));
    }
}
