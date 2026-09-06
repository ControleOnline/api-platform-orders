<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Service\OrderCheckoutIdempotencyStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrderCheckoutIdempotencyStoreTest extends TestCase
{
    public function testNormalizeKeyTrimsAndRejectsEmpty(): void
    {
        $store = new OrderCheckoutIdempotencyStore();

        self::assertNull($store->normalizeKey(null));
        self::assertNull($store->normalizeKey('   '));
        self::assertSame('abc-123', $store->normalizeKey('  abc-123  '));
    }

    public function testSameKeySamePayloadReplaysStoredResult(): void
    {
        $store = new OrderCheckoutIdempotencyStore();
        $order = new Order();
        $payload = ['confirm' => true, 'mode' => 'counter'];
        $result = ['outcome' => 'success', 'errno' => 0, 'errmsg' => 'ok'];

        $store->store($order, 'key-1', $payload, $result);
        $replay = $store->find($order, 'key-1', $payload);

        self::assertNotNull($replay);
        self::assertTrue($replay['idempotentReplay']);
        self::assertSame('success', $replay['outcome']);
        self::assertSame(0, $replay['errno']);
    }

    public function testSameKeyDifferentPayloadConflicts(): void
    {
        $store = new OrderCheckoutIdempotencyStore();
        $order = new Order();

        $store->store($order, 'key-1', ['confirm' => true], ['outcome' => 'success', 'errno' => 0]);

        $this->expectException(ConflictHttpException::class);
        $store->find($order, 'key-1', ['confirm' => false]);
    }

    public function testUnknownKeyReturnsNull(): void
    {
        $store = new OrderCheckoutIdempotencyStore();
        $order = new Order();

        self::assertNull($store->find($order, 'missing', ['confirm' => true]));
    }
}
