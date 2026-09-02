<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Config\ConfigRepository;
use NeoFramework\Core\Http\ResettableInterface;
use NeoFramework\Core\Runtime\RuntimeDoctor;
use PHPUnit\Framework\TestCase;

final class DoctorResettableFixture implements ResettableInterface
{
    public function reset(): void {}
}

final class DoctorUnsafeFixture {}

final class NeoFrameworkRuntimeDoctorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neof-runtime-doctor-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void { @rmdir($this->root); }

    /** @param list<class-string> $services */
    private function inspect(array $services): array
    {
        $config = new ConfigRepository($this->root, [
            'logging' => ['stream' => 'stderr'],
            'cache' => ['adapter' => 'filesystem'],
            'runtime' => ['stateful_services' => $services],
        ], false);

        return RuntimeDoctor::inspect($config, $this->root);
    }

    public function testItAcceptsStatefulServicesThatCanBeReset(): void
    {
        $checks = $this->inspect([DoctorResettableFixture::class]);
        self::assertFalse(RuntimeDoctor::hasErrors($checks));
        self::assertContains('ok', array_column($checks, 'status'));
    }

    public function testItRejectsDeclaredStatefulServicesWithoutResetContract(): void
    {
        $checks = $this->inspect([DoctorUnsafeFixture::class]);

        self::assertTrue(RuntimeDoctor::hasErrors($checks));
        self::assertSame('error', $this->check($checks, 'service:' . DoctorUnsafeFixture::class)['status']);
    }

    public function testItReportsAFileLogThatCannotBeWritten(): void
    {
        $config = new ConfigRepository($this->root, [
            'logging' => ['stream' => 'file', 'path' => 'missing/system.log'],
            'cache' => ['adapter' => 'filesystem'], 'runtime' => ['stateful_services' => []],
        ], false);
        $checks = RuntimeDoctor::inspect($config, $this->root);

        self::assertSame('error', $this->check($checks, 'logging')['status']);
    }

    /** @param list<array{id:string,status:string,message:string}> $checks @return array{id:string,status:string,message:string} */
    private function check(array $checks, string $id): array
    {
        foreach ($checks as $check) if ($check['id'] === $id) return $check;
        self::fail("Missing check {$id}");
    }
}
