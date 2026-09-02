<?php
declare(strict_types=1);

namespace Tests;

use NeoFramework\Core\Cache;
use NeoFramework\Core\Events\UserLoggedOut;
use NeoFramework\Core\Jobs\Entity\JobEntity;
use NeoFramework\Core\Testing\FakeCache;
use NeoFramework\Core\Testing\FakeDispatcher;
use NeoFramework\Core\Testing\FakeMailer;
use NeoFramework\Core\Testing\FakeQueue;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class NeoFrameworkFakesTest extends TestCase
{
    public function testTheQueueRecordsWhatWasEnqueuedWithItsArguments(): void
    {
        $queue = new FakeQueue();
        $queue->enqueue(new JobEntity('SendWelcome', ['userId' => 42]), 'mail');

        $queue->assertPushed('SendWelcome');
        // Sem inspecionar argumentos o teste provaria apenas que *algum* job
        // daquele tipo passou — o que costuma ser verdade mesmo com o argumento errado.
        $queue->assertPushed('SendWelcome', static fn (JobEntity $job, string $q): bool => $job->getArgs()['userId'] === 42 && $q === 'mail');
        $queue->assertNotPushed('SendInvoice');
        $queue->assertPushedTimes('SendWelcome', 1);
    }

    public function testTheQueueFailsWhenTheArgumentFilterDoesNotMatch(): void
    {
        $queue = new FakeQueue();
        $queue->enqueue(new JobEntity('SendWelcome', ['userId' => 1]));

        $this->expectException(AssertionFailedError::class);
        $queue->assertPushed('SendWelcome', static fn (JobEntity $job): bool => $job->getArgs()['userId'] === 42);
    }

    public function testTheQueueStillBehavesLikeAQueue(): void
    {
        $queue = new FakeQueue();
        $queue->enqueue(new JobEntity('A'), 'alpha');
        $queue->enqueue(new JobEntity('B'), 'beta');

        // Um fake que não respeita as filas faria um teste de isolamento passar
        // por engano.
        self::assertSame(1, $queue->size('alpha'));
        self::assertSame('A', $queue->dequeue('alpha')?->getClass());
        self::assertNull($queue->dequeue('alpha'));
        self::assertSame(1, $queue->size('beta'));
    }

    public function testTheQueueLockIsNotReentrant(): void
    {
        $queue = new FakeQueue();

        self::assertTrue($queue->lock('job-1'));
        // Se o fake concedesse o lock duas vezes, um teste de processamento
        // concorrente passaria enquanto o driver de verdade falha.
        self::assertFalse($queue->lock('job-1'));
        self::assertTrue($queue->unlock('job-1'));
        self::assertTrue($queue->lock('job-1'));
    }

    public function testTheDispatcherRecordsEventsAndTheirContent(): void
    {
        $dispatcher = new FakeDispatcher();
        $dispatcher->dispatch(new UserLoggedOut(null, 'session'));

        $dispatcher->assertDispatched(UserLoggedOut::class);
        $dispatcher->assertDispatched(UserLoggedOut::class, static fn (UserLoggedOut $e): bool => $e->guard === 'session');
        $dispatcher->assertDispatchedTimes(UserLoggedOut::class, 1);
        $dispatcher->assertNotDispatched(\NeoFramework\Core\Events\UserAuthenticated::class);
    }

    public function testTheDispatcherReturnsTheEventSoCallersKeepWorking(): void
    {
        $dispatcher = new FakeDispatcher();
        $event = new UserLoggedOut(null, 'session');

        // PSR-14 exige que dispatch devolva o evento; um fake que devolvesse
        // outra coisa quebraria quem encadeia sobre o retorno.
        self::assertSame($event, $dispatcher->dispatch($event));
    }

    public function testTheMailerRecordsSubjectBodyAndRecipients(): void
    {
        $mailer = new FakeMailer();
        $mailer->addEmail('quem@example.com')->setFrom('eu@example.com', 'Eu')->send('Assunto', '<b>oi</b>', true);

        $mailer->assertSentTimes(1);
        $mailer->assertSentTo('quem@example.com');
        $mailer->assertSent(static fn (array $m): bool => $m['subject'] === 'Assunto' && $m['html'] === true);
    }

    public function testASecondMessageDoesNotInheritTheFirstRecipients(): void
    {
        $mailer = new FakeMailer();
        $mailer->addEmail('primeiro@example.com')->send('Um', 'corpo');
        $mailer->addEmail('segundo@example.com')->send('Dois', 'corpo');

        $sent = $mailer->sent();
        // Herdar destinatários entre envios é, num mailer de verdade, mandar
        // e-mail para quem não devia recebê-lo.
        self::assertSame(['primeiro@example.com'], $sent[0]['to']);
        self::assertSame(['segundo@example.com'], $sent[1]['to']);
    }

    public function testTheMailerFailsWhenNothingMatches(): void
    {
        $mailer = new FakeMailer();
        $mailer->assertNothingSent();

        $this->expectException(AssertionFailedError::class);
        $mailer->assertSentTo('ninguem@example.com');
    }

    public function testTheFakeCacheReplacesTheConfiguredPoolAndIsRestorable(): void
    {
        $pool = FakeCache::install();

        try {
            $cache = new Cache();
            $item = $cache->getItem('chave');
            $item->set('valor');
            $cache->save($item);

            self::assertTrue($cache->getItem('chave')->isHit());
            self::assertSame('valor', $cache->getItem('chave')->get());
            self::assertTrue($pool->getItem('chave')->isHit());

            $cache->deleteItem('chave');
            self::assertFalse($cache->getItem('chave')->isHit());
        } finally {
            FakeCache::uninstall();
        }
    }
}
