<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderQrConsume;
use ControleOnline\Entity\OrderQrContext;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderQrConsumeRepository;
use ControleOnline\Repository\OrderQrContextRepository;
use ControleOnline\Service\OrderQrService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderQrServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private OrderQrContextRepository&MockObject $contextRepo;
    private OrderQrConsumeRepository&MockObject $consumeRepo;
    private OrderService&MockObject $orderService;
    private StatusService&MockObject $statusService;
    private OrderQrService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->contextRepo = $this->createMock(OrderQrContextRepository::class);
        $this->consumeRepo = $this->createMock(OrderQrConsumeRepository::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->statusService = $this->createMock(StatusService::class);

        $this->service = new OrderQrService(
            $this->em,
            $this->contextRepo,
            $this->consumeRepo,
            $this->orderService,
            $this->statusService,
        );
    }

    public function testHashTokenIsSha256(): void
    {
        $token = 'abc123';
        $this->assertSame(hash('sha256', $token), $this->service->hashToken($token));
    }

    public function testGenerateRawTokenIsOpaqueAndLongEnough(): void
    {
        $a = $this->service->generateRawToken();
        $b = $this->service->generateRawToken();
        $this->assertNotSame($a, $b);
        $this->assertGreaterThanOrEqual(32, strlen($a));
        // Must not look like a sequential integer id
        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $a);
    }

    public function testEmitSessionRequiresRootOrder(): void
    {
        $provider = $this->makePeople(1);
        $this->expectException(BadRequestHttpException::class);
        $this->service->emit([
            'purpose' => OrderQrContext::PURPOSE_SESSION,
            'linkType' => OrderQrContext::LINK_TABLE,
            'provider' => $provider,
            'externalCode' => 'T-1',
        ]);
    }

    public function testEmitSessionRejectsClosedRoot(): void
    {
        $provider = $this->makePeople(1);
        $root = $this->makeOrder(10, $provider, 'T-1');

        $this->contextRepo->method('isOrderOpenRoot')->with($root)->willReturn(false);

        $this->expectException(BadRequestHttpException::class);
        $this->service->emit([
            'purpose' => OrderQrContext::PURPOSE_SESSION,
            'linkType' => OrderQrContext::LINK_TABLE,
            'provider' => $provider,
            'rootOrder' => $root,
            'externalCode' => 'T-1',
        ]);
    }

    public function testEmitSessionSuccessPersistsHashOnly(): void
    {
        $provider = $this->makePeople(1);
        $root = $this->makeOrder(10, $provider, 'T-1');
        $this->contextRepo->method('isOrderOpenRoot')->willReturn(true);

        $persisted = null;
        $this->em->expects($this->once())->method('persist')->willReturnCallback(
            function ($entity) use (&$persisted) {
                $persisted = $entity;
            }
        );
        $this->em->expects($this->once())->method('flush');

        [$context, $rawToken] = $this->service->emit([
            'purpose' => OrderQrContext::PURPOSE_SESSION,
            'linkType' => OrderQrContext::LINK_TABLE,
            'provider' => $provider,
            'rootOrder' => $root,
            'externalCode' => 'T-1',
            'expiresInSeconds' => 3600,
        ]);

        $this->assertInstanceOf(OrderQrContext::class, $context);
        $this->assertSame($persisted, $context);
        $this->assertSame($this->service->hashToken($rawToken), $context->getTokenHash());
        $this->assertNotSame($rawToken, $context->getTokenHash());
        $this->assertSame(OrderQrContext::PURPOSE_SESSION, $context->getPurpose());
        $this->assertSame(OrderQrContext::LINK_TABLE, $context->getLinkType());
        $this->assertNotNull($context->getExpiresAt());
    }

    public function testResolveUnknownTokenReturnsNotFoundUniformly(): void
    {
        $this->contextRepo->method('findOneByTokenHash')->willReturn(null);
        $this->expectException(NotFoundHttpException::class);
        $this->service->resolve('some-unknown-token-value-xx');
    }

    public function testResolveExpiredTokenDenied(): void
    {
        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('tok-123456789012'));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);
        $context->setExpiresAt(new \DateTime('-1 hour'));
        $context->setProvider($this->makePeople(1));

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);
        $this->em->expects($this->once())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->resolve('tok-123456789012');
    }

    public function testResolveRevokedTokenDenied(): void
    {
        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('tok-123456789012'));
        $context->setStatus(OrderQrContext::STATUS_REVOKED);
        $context->setProvider($this->makePeople(1));

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->resolve('tok-123456789012');
    }

    public function testResolveValidReturnsPublicContextWithoutIds(): void
    {
        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('good-token-123456'));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);
        $context->setPurpose(OrderQrContext::PURPOSE_PERMANENT);
        $context->setLinkType(OrderQrContext::LINK_TABLE);
        $context->setExternalCode('MESA-3');
        $context->setProvider($this->makePeople(1));
        $context->setKeyVersion(2);

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);

        $public = $this->service->resolve('good-token-123456');

        $this->assertSame('permanent', $public['purpose']);
        $this->assertSame('table', $public['linkType']);
        $this->assertSame('MESA-3', $public['externalCode']);
        $this->assertArrayNotHasKey('id', $public);
        $this->assertArrayNotHasKey('tokenHash', $public);
        $this->assertArrayNotHasKey('rootOrder', $public);
    }

    public function testConsumeRequiresIdempotencyKey(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->consume(['token' => 'x', 'idempotencyKey' => '']);
    }

    public function testConsumeNoneNeverAttachesMainOrder(): void
    {
        $provider = $this->makePeople(5);
        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('none-token-123456'));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);
        $context->setPurpose(OrderQrContext::PURPOSE_PERMANENT);
        $context->setLinkType(OrderQrContext::LINK_NONE);
        $context->setProvider($provider);

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);
        $this->consumeRepo->method('findOneByIdempotencyKey')->willReturn(null);

        $orders = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$orders) {
            if ($entity instanceof Order) {
                // simulate id assignment
                $ref = new \ReflectionProperty(Order::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($entity, 100 + count($orders));
                $orders[] = $entity;
            }
        });
        $this->em->method('flush');

        $result = $this->service->consume([
            'token' => 'none-token-123456',
            'idempotencyKey' => 'idem-none-1',
        ]);

        $this->assertFalse($result['reused']);
        $this->assertNull($result['rootOrder']);
        $this->assertNull($result['order']['mainOrder']);
        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]->getMainOrder());
    }

    public function testConsumeSessionRejectsClosedRoot(): void
    {
        $provider = $this->makePeople(1);
        $root = $this->makeOrder(50, $provider, 'T-9');

        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('sess-123456789012'));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);
        $context->setPurpose(OrderQrContext::PURPOSE_SESSION);
        $context->setLinkType(OrderQrContext::LINK_TABLE);
        $context->setProvider($provider);
        $context->setRootOrder($root);
        $context->setExternalCode('T-9');

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);
        $this->contextRepo->method('isOrderOpenRoot')->with($root)->willReturn(false);
        $this->consumeRepo->method('findOneByIdempotencyKey')->willReturn(null);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->consume(['token' => 'sess-123456789012', 'idempotencyKey' => 'k1']);
    }

    public function testConsumeIdempotentReplayReturnsSameOrder(): void
    {
        $provider = $this->makePeople(1);
        $order = $this->makeOrder(77, $provider, 'shop-qr:abc');

        $context = new OrderQrContext();
        $context->setPurpose(OrderQrContext::PURPOSE_PERMANENT);
        $context->setLinkType(OrderQrContext::LINK_NONE);
        $context->setProvider($provider);
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);

        $consume = new OrderQrConsume();
        $consume->setQrContext($context);
        $consume->setOrder($order);
        $consume->setRootOrder(null);
        $consume->setIdempotencyKey('idem-replay');

        $this->consumeRepo->method('findOneByIdempotencyKey')
            ->with('idem-replay')
            ->willReturn($consume);

        $result = $this->service->consume([
            'token' => 'anything',
            'idempotencyKey' => 'idem-replay',
        ]);

        $this->assertTrue($result['reused']);
        $this->assertSame(77, $result['order']['id']);
    }

    public function testConsumePermanentCreatesRootWhenMissing(): void
    {
        $provider = $this->makePeople(3);
        $context = new OrderQrContext();
        $context->setTokenHash($this->service->hashToken('perm-123456789012'));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);
        $context->setPurpose(OrderQrContext::PURPOSE_PERMANENT);
        $context->setLinkType(OrderQrContext::LINK_TABLE);
        $context->setExternalCode('MESA-1');
        $context->setProvider($provider);

        $this->contextRepo->method('findOneByTokenHash')->willReturn($context);
        $this->contextRepo->method('findOpenRootByProviderAndExternalCode')->willReturn(null);
        $this->consumeRepo->method('findOneByIdempotencyKey')->willReturn(null);

        $created = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$created) {
            if ($entity instanceof Order) {
                $ref = new \ReflectionProperty(Order::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($entity, 200 + count($created));
                $created[] = $entity;
            }
        });
        $this->em->method('flush');

        $result = $this->service->consume([
            'token' => 'perm-123456789012',
            'idempotencyKey' => 'idem-perm-1',
        ]);

        $this->assertFalse($result['reused']);
        $this->assertNotNull($result['rootOrder']);
        $this->assertGreaterThanOrEqual(2, count($created)); // root + round
        $root = $created[0];
        $round = $created[1];
        $this->assertNull($root->getMainOrder());
        $this->assertSame($root, $round->getMainOrder());
        $this->assertSame('MESA-1', $root->getExternalCode());
    }

    private function makePeople(int $id): People
    {
        $people = $this->createMock(People::class);
        $people->method('getId')->willReturn($id);
        return $people;
    }

    private function makeOrder(int $id, People $provider, ?string $externalCode): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getProvider', 'getExternalCode', 'getMainOrder', 'setMainOrder', 'setProvider', 'setExternalCode', 'setApp', 'setStatus', 'getApp'])
            ->getMock();
        $order->method('getId')->willReturn($id);
        $order->method('getProvider')->willReturn($provider);
        $order->method('getExternalCode')->willReturn($externalCode);
        $order->method('getMainOrder')->willReturn(null);
        $order->method('getApp')->willReturn('SHOP');
        return $order;
    }
}
