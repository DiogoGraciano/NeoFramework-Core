<?php

declare(strict_types=1);

namespace Tests;

use NeoFramework\BrSupport\Cep;
use NeoFramework\BrSupport\Cnpj;
use NeoFramework\BrSupport\Cpf;
use NeoFramework\BrSupport\Dre;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkBrSupportTest extends TestCase
{
    public function testCpfValidationRejectsRepeatedAndBadCheckDigits(): void
    {
        self::assertTrue(Cpf::isValid('529.982.247-25'));
        self::assertFalse(Cpf::isValid('111.111.111-11'));
        self::assertFalse(Cpf::isValid('529.982.247-26'));
        self::assertSame('529.982.247-25', Cpf::format('52998224725'));
    }

    public function testCnpjValidationChecksBothDigits(): void
    {
        self::assertTrue(Cnpj::isValid('11.222.333/0001-81'));
        self::assertFalse(Cnpj::isValid('11.222.333/0001-82'));
        self::assertFalse(Cnpj::isValid('00.000.000/0000-00'));
        self::assertSame('11.222.333/0001-81', Cnpj::format('11222333000181'));
    }

    public function testCepAndDreOnlyAssertTheirLocalStructure(): void
    {
        self::assertTrue(Cep::isValid('01001-000'));
        self::assertFalse(Cep::isValid('0100-1000'));
        self::assertSame('01001-000', Cep::format('01001000'));

        self::assertTrue(Dre::isValid('1.2.3.10'));
        self::assertFalse(Dre::isValid('1..2'));
        self::assertSame('1.2.3.1.0', Dre::format('12310'));
    }

    public function testFormattingRefusesWrongLengthsInsteadOfInventingADocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cpf::format('123');
    }
}
