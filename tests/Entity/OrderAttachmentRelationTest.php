<?php

namespace ControleOnline\Orders\Tests\Entity;

use ControleOnline\Entity\File;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderFile;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

class OrderAttachmentRelationTest extends TestCase
{
    public function testOrderStartsWithEmptyAttachmentCollection(): void
    {
        $order = new Order();

        self::assertInstanceOf(Collection::class, $order->getOrderFiles());
        self::assertCount(0, $order->getOrderFiles());
    }

    public function testOrderCanAddAndRemoveAttachmentRelations(): void
    {
        $order = new Order();
        $attachment = new OrderFile();
        $attachment->setOrder($order);
        $attachment->setFile(new File());

        $order->addOrderFile($attachment);

        self::assertCount(1, $order->getOrderFiles());
        self::assertSame($attachment, $order->getOrderFiles()->first());

        $order->removeOrderFile($attachment);

        self::assertCount(0, $order->getOrderFiles());
    }
}
