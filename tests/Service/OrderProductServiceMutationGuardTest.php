<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class OrderProductServiceMutationGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOrderProductServiceStaticState();
    }

    public function testPrePersistRejectsSaleOrderOnAddProductsRoute(): void
    {
        $service = $this->buildService('/orders/78112/add-products', 'POST');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_SALE);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(
            'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
        );

        $service->prePersist($orderProduct);
    }

    public function testPrePersistRejectsSaleOrderOnReplaceProductsRoute(): void
    {
        $service = $this->buildService('/orders/78112/replace-products', 'PUT');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_SALE);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(
            'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
        );

        $service->prePersist($orderProduct);
    }

    public function testPrePersistAllowsCartOrderOnAddProductsRoute(): void
    {
        $service = $this->buildService('/orders/78112/add-products', 'POST');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_CART);

        $service->prePersist($orderProduct);

        self::assertTrue(true);
    }

    public function testPrePersistAllowsCartOrderOnReplaceProductsRoute(): void
    {
        $service = $this->buildService('/orders/78112/replace-products', 'PUT');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_CART);

        $service->prePersist($orderProduct);

        self::assertTrue(true);
    }

    public function testPreUpdateAllowsCartOrderQuantityChanges(): void
    {
        $service = $this->buildService('/order_products/990', 'PUT');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_CART);

        $service->preUpdate($orderProduct);

        self::assertTrue(true);
    }

    public function testPreUpdateRejectsSaleOrderQuantityChanges(): void
    {
        $service = $this->buildService('/order_products/990', 'PUT');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_SALE);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(
            'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
        );

        $service->preUpdate($orderProduct);
    }

    public function testPreRemoveRejectsSaleOrderDeletion(): void
    {
        $service = $this->buildService('/order_products/990', 'DELETE');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_SALE);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(
            'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
        );

        $service->preRemove($orderProduct);
    }

    public function testPreRemoveRejectsQuoteOrderDeletion(): void
    {
        $service = $this->buildService('/order_products/990', 'DELETE');
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_QUOTE);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage(
            'Produtos, quantidades e remocoes so podem ser alterados enquanto o pedido estiver em cart.'
        );

        $service->preRemove($orderProduct);
    }

    public function testPreRemoveAllowsCartOrderDeletion(): void
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                self::callback(
                    static fn (array $criteria): bool =>
                        array_key_exists('orderProduct', $criteria)
                        && $criteria['orderProduct'] instanceof OrderProduct
                ),
                null,
                null,
                null,
            )
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(OrderProduct::class)
            ->willReturn($repository);
        $entityManager
            ->expects(self::never())
            ->method('flush');

        $service = $this->buildService('/order_products/990', 'DELETE', $entityManager);
        $orderProduct = $this->createOrderProduct(OrderService::ORDER_TYPE_CART);

        $service->preRemove($orderProduct);

        self::assertTrue(true);
    }

    private function buildService(
        string $path,
        string $method,
        ?EntityManagerInterface $entityManager = null,
    ): OrderProductService
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path, $method));

        return new OrderProductService(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(PeopleService::class),
            $this->createMock(OrderService::class),
            $this->createMock(StatusService::class),
            $requestStack,
            $this->createMock(OrderProductQueueService::class),
            $this->createMock(InvoiceService::class),
        );
    }

    private function resetOrderProductServiceStaticState(): void
    {
        foreach (['mainProduct' => true, 'calculateBefore' => []] as $propertyName => $value) {
            $property = new \ReflectionProperty(OrderProductService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }

    private function createOrderProduct(string $orderType): OrderProduct
    {
        $order = new Order();
        $order->setOrderType($orderType);

        $orderProduct = new OrderProduct();
        $orderProduct->setOrder($order);
        $orderProduct->setStatus(new Status());
        $orderProduct->setQuantity(1);

        return $orderProduct;
    }
}
