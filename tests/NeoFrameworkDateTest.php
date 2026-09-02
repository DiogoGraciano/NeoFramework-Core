<?php
declare(strict_types=1);

namespace Tests;

use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use NeoFramework\Core\Support\Date;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkDateTest extends TestCase
{
    public function testDateParsingAndSqlFormattingAreLocaleIndependent(): void
    {
        $date = Date::parse('2026-08-26 14:30:00', 'Y-m-d H:i:s', new DateTimeZone('UTC'));

        self::assertSame('2026-08-26', Date::toSqlDate($date));
        self::assertSame('2026-08-26 14:30:00', Date::toSqlDateTime($date));
        self::assertSame('2026-08-26T14:30:00+00:00', Date::format($date, DateTimeInterface::ATOM));
    }

    public function testDateFormattingUsesAnExplicitLocaleAndTimezone(): void
    {
        $date = Date::parse('2026-08-26 14:30:00', 'Y-m-d H:i:s', new DateTimeZone('UTC'));

        self::assertStringContainsString('2026', Date::localized($date, 'en_US', IntlDateFormatter::FULL, IntlDateFormatter::SHORT, new DateTimeZone('UTC')));
        $this->expectException(InvalidArgumentException::class);
        Date::parse('26/08/2026', 'Y-m-d');
    }
}
