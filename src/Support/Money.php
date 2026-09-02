<?php
declare(strict_types=1);

namespace NeoFramework\Core\Support;

use InvalidArgumentException;
use NumberFormatter;

/** Immutable monetary value stored in the currency's minor unit. */
final readonly class Money
{
    private function __construct(
        public int $minorAmount,
        public string $currency,
        public int $fractionDigits,
    ) {
    }

    public static function ofMinor(int $minorAmount, string $currency): self
    {
        $currency = self::normalizeCurrency($currency);

        return new self($minorAmount, $currency, self::fractionDigits($currency));
    }

    /** Creates money from a canonical decimal value without implicit rounding. */
    public static function fromDecimal(string $amount, string $currency): self
    {
        $currency = self::normalizeCurrency($currency);
        $fractionDigits = self::fractionDigits($currency);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('The amount must be a canonical decimal string.');
        }

        $fraction = $parts[3] ?? '';
        if (strlen($fraction) > $fractionDigits) {
            throw new InvalidArgumentException(sprintf('The %s currency supports at most %d decimal places.', $currency, $fractionDigits));
        }

        $digits = ltrim($parts[2] . str_pad($fraction, $fractionDigits, '0'), '0');
        if ($digits === '') {
            return new self(0, $currency, $fractionDigits);
        }

        $minor = filter_var($digits, FILTER_VALIDATE_INT);
        if (!is_int($minor)) {
            throw new InvalidArgumentException('The amount exceeds the supported integer range.');
        }

        return new self($parts[1] === '-' ? -$minor : $minor, $currency, $fractionDigits);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorAmount + $other->minorAmount, $this->currency, $this->fractionDigits);
    }

    public function format(string $locale = 'pt_BR'): string
    {
        $formatter = new NumberFormatter($locale . '@currency=' . $this->currency, NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($this->minorAmount / (10 ** $this->fractionDigits), $this->currency);

        if ($formatted === false) {
            throw new InvalidArgumentException(sprintf('The "%s" locale cannot format currency.', $locale));
        }

        return $formatted;
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('The currency must be a three-letter ISO 4217 code.');
        }

        return $currency;
    }

    private static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter('en_US@currency=' . $currency, NumberFormatter::CURRENCY);
        $fractionDigits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        return is_int($fractionDigits) ? $fractionDigits : 2;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money values can only be combined when their currencies match.');
        }
    }
}
