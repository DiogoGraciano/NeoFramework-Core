<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;

/** Date parsing and presentation with explicit formats, locales and timezones. */
final class Date
{
    private function __construct()
    {
    }

    /** Parses an exact date representation. Ambiguous input has no implicit locale. */
    public static function parse(string $value, string $format, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException(sprintf('"%s" does not match the "%s" date format.', $value, $format));
        }

        return $date;
    }

    public static function toSqlDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public static function toSqlDateTime(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public static function format(DateTimeInterface $date, string $format): string
    {
        return $date->format($format);
    }

    public static function localized(
        DateTimeInterface $date,
        string $locale,
        int $dateStyle = IntlDateFormatter::MEDIUM,
        int $timeStyle = IntlDateFormatter::NONE,
        ?DateTimeZone $timezone = null,
    ): string {
        $formatter = new IntlDateFormatter($locale, $dateStyle, $timeStyle, $timezone ?? $date->getTimezone());
        $formatted = $formatter->format($date);

        if ($formatted === false) {
            throw new InvalidArgumentException(sprintf('The "%s" locale cannot format dates.', $locale));
        }

        return $formatted;
    }
}
