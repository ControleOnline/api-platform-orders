<?php

namespace ControleOnline\Orders\Tests\Fixtures;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Spool;

final class OrderPrintDoubles
{
    public static function people(int $id): People
    {
        $people = new People();
        self::setId($people, $id);

        return $people;
    }

    public static function spool(int $id): Spool
    {
        $spool = new Spool();
        self::setId($spool, $id);

        return $spool;
    }

    public static function order(
        int $id,
        ?People $provider = null,
        array|string|null $otherInformations = null,
        ?string $app = null
    ): Order {
        $order = new Order();
        self::setId($order, $id);
        $order->setProvider($provider);

        if ($app === null && is_string($otherInformations)) {
            $order->setApp($otherInformations);

            return $order;
        }

        if ($otherInformations !== null) {
            $order->setOtherInformations($otherInformations);
        }

        $order->setApp($app);

        return $order;
    }

    private static function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
