<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Log\LoggerInterface;

final class NeoFrameworkPsrTest extends TestCase
{
    protected function tearDown(): void { Container::reset(); }

    public function testContainerExposesPsrContracts(): void
    {
        $container = new Container();
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(ResponseFactoryInterface::class, $container->get(ResponseFactoryInterface::class));
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $container->get(ServerRequestFactoryInterface::class));
        self::assertInstanceOf(LoggerInterface::class, $container->get(LoggerInterface::class));
    }
}
