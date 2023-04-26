<?php

declare(strict_types=1);

namespace Mvorisek\Utils\Tests;

use Mvorisek\Net\IpAddress;
use Mvorisek\Utils\GeoIp;
use PHPUnit\Framework\TestCase;

class GeoIpTest extends TestCase
{
    public function testGetCountryCode(): void
    {
        // Google
        $this->assertSame('US', GeoIp::getCountryCode(new IpAddress('8.8.8.8')));
        $this->assertSame('US', GeoIp::getCountryCode(new IpAddress('2001:4860:4860::8888')));

        // Seznam
        $this->assertSame('CZ', GeoIp::getCountryCode(new IpAddress('77.75.75.1')));
        $this->assertSame('CZ', GeoIp::getCountryCode(new IpAddress('2a02:598:4444:1::1')));
    }
}
