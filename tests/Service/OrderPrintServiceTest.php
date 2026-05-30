<?php

namespace ControleOnline\Orders\Tests\Service;

require_once __DIR__ . '/../../src/Service/OrderPrintService.php';

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Service\OrderPrintService;
use ControleOnline\Service\PrintService;
use PHPUnit\Framework\TestCase;

class OrderPrintServiceTest extends TestCase
{
    public function testQueueMaterializedItemDoesNotShowRootQuantityPrefix(): void
    {
        [$service, $capture] = $this->createServiceWithPrintSpy();

        $root = $this->createOrderProduct('Combo Alpha Gyros', 2);
        $child = $this->createOrderProduct('Batata Frita', 2);
        $root->addOrderProductComponent($child);

        $this->invokePrivateMethod($service, 'printQueueItem', [
            $root,
            false,
            2,
            false,
        ]);

        self::assertTrue($this->containsLine($capture->lines, 'Combo Alpha Gyros'));
        self::assertFalse($this->containsLine($capture->lines, '2x Combo Alpha Gyros'));
        self::assertTrue($this->containsLine($capture->lines, '2x Batata Frita'));
        self::assertSame(0, $capture->cutCount);
    }

    public function testStandaloneNonMaterializedItemShowsQuantityPrefixAboveOne(): void
    {
        [$service, $capture] = $this->createServiceWithPrintSpy();

        $orderProduct = $this->createOrderProduct('Molho Extra', 2);

        $this->invokePrivateMethod($service, 'printStandaloneOrderProduct', [
            $orderProduct,
            false,
        ]);

        self::assertTrue($this->containsLine($capture->lines, '2x Molho Extra'));
        self::assertSame(1, $capture->cutCount);
    }

    public function testHiddenRootProductGroupDoesNotPrintTheGroupTitle(): void
    {
        [$service, $capture] = $this->createServiceWithPrintSpy();

        $order = new Order();
        $order->addOrderProduct($this->createOrderProduct(
            'Alpha Gyros (Fraldinha)',
            1,
            $this->createProductGroup('Escolha seu queijo', false),
        ));

        $groups = $this->invokePrivateMethod($service, 'getGroups', [$order]);

        self::assertCount(1, $groups);
        self::assertFalse($groups[0]['showLabel']);

        $this->invokePrivateMethod($service, 'printGroups', [$groups, false]);

        self::assertTrue($this->containsLine($capture->lines, 'Alpha Gyros (Fraldinha)'));
        self::assertFalse($this->containsLine($capture->lines, 'ESCOLHA SEU QUEIJO'));
    }

    public function testHiddenChildProductGroupDoesNotPrintTheGroupTitle(): void
    {
        [$service, $capture] = $this->createServiceWithPrintSpy();

        $root = $this->createOrderProduct('Combo Alpha Gyros', 1);
        $root->addOrderProductComponent($this->createOrderProduct(
            'Queijo Mucarela',
            1,
            $this->createProductGroup('Escolha seu queijo', false),
        ));

        $this->invokePrivateMethod($service, 'printChildren', [
            $root->getOrderProductComponents(),
            true,
            false,
        ]);

        self::assertTrue($this->containsLine($capture->lines, 'COMPONENTES:'));
        self::assertTrue($this->containsLine($capture->lines, 'Queijo Mucarela'));
        self::assertFalse($this->containsLine($capture->lines, 'ESCOLHA SEU QUEIJO'));
    }

    private function createServiceWithPrintSpy(): array
    {
        $service = (new \ReflectionClass(OrderPrintService::class))->newInstanceWithoutConstructor();
        $capture = (object) [
            'lines' => [],
            'cutCount' => 0,
        ];

        $printService = $this->createStub(PrintService::class);

        $printService
            ->method('addLine')
            ->willReturnCallback(function (...$args) use ($capture): void {
                $capture->lines[] = implode("\t", array_map(
                    static fn ($value): string => (string) $value,
                    $args
                ));
            });

        $printService
            ->method('addCutMarker')
            ->willReturnCallback(function () use ($capture): void {
                $capture->cutCount++;
            });

        $this->setPrivateProperty($service, 'printService', $printService);

        return [$service, $capture];
    }

    private function createOrderProduct(
        string $name,
        float $quantity,
        ?ProductGroup $productGroup = null
    ): OrderProduct
    {
        $product = (new Product())
            ->setProduct($name)
            ->setDescription('')
            ->setPrice(0);

        return (new OrderProduct())
            ->setProduct($product)
            ->setQuantity($quantity)
            ->setPrice(0)
            ->setTotal(0)
            ->setComment('')
            ->setShowInParentQueue(true)
            ->setProductGroup($productGroup);
    }

    private function createProductGroup(string $name, bool $showInDisplay): ProductGroup
    {
        return (new ProductGroup())
            ->setProductGroup($name)
            ->setShowInDisplay($showInDisplay);
    }

    private function containsLine(array $lines, string $needle): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($object, ...$arguments);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
