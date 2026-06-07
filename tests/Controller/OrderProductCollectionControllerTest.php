<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\OrderProductCollectionController;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Service\HydratorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class OrderProductCollectionControllerTest extends TestCase
{
    public function testResolvesOrderIdQueryAndReturnsHydratedCollection(): void
    {
        $items = [new OrderProduct()];
        $orderReference = $this->createStub(Order::class);
        $capturedFindByArguments = null;
        $capturedCollectionArguments = null;
        $payload = [
            'member' => [
                ['id' => 72133],
            ],
            'totalItems' => 1,
        ];

        $repository = $this->createStub(EntityRepository::class);
        $repository
            ->method('findBy')
            ->willReturnCallback(function (...$arguments) use ($items, &$capturedFindByArguments) {
                $capturedFindByArguments = $arguments;

                return $items;
            });

        $manager = $this->createStub(EntityManagerInterface::class);
        $manager
            ->method('getReference')
            ->willReturn($orderReference);
        $manager
            ->method('getRepository')
            ->willReturn($repository);

        $hydratorService = $this->createStub(HydratorService::class);
        $hydratorService
            ->method('collectionData')
            ->willReturnCallback(function (...$arguments) use ($payload, &$capturedCollectionArguments) {
                $capturedCollectionArguments = $arguments;

                return $payload;
            });

        $controller = new OrderProductCollectionController($manager, $hydratorService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order_id' => '72133',
                'itemsPerPage' => '200',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($payload, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(
            [
                ['order' => $orderReference],
                ['id' => 'ASC'],
                200,
                0,
            ],
            array_slice($capturedFindByArguments, 0, 4),
        );
        self::assertSame(
            [
                $items,
                OrderProduct::class,
                'order_product:read',
                ['order' => $orderReference],
            ],
            array_slice($capturedCollectionArguments, 0, 4),
        );
    }

    public function testResolvesOrderIriAndPagination(): void
    {
        $items = [new OrderProduct()];
        $orderReference = $this->createStub(Order::class);
        $capturedFindByArguments = null;
        $capturedCollectionArguments = null;
        $payload = [
            'member' => [
                ['id' => 72133],
            ],
            'totalItems' => 1,
        ];

        $repository = $this->createStub(EntityRepository::class);
        $repository
            ->method('findBy')
            ->willReturnCallback(function (...$arguments) use ($items, &$capturedFindByArguments) {
                $capturedFindByArguments = $arguments;

                return $items;
            });

        $manager = $this->createStub(EntityManagerInterface::class);
        $manager
            ->method('getReference')
            ->willReturn($orderReference);
        $manager
            ->method('getRepository')
            ->willReturn($repository);

        $hydratorService = $this->createStub(HydratorService::class);
        $hydratorService
            ->method('collectionData')
            ->willReturnCallback(function (...$arguments) use ($payload, &$capturedCollectionArguments) {
                $capturedCollectionArguments = $arguments;

                return $payload;
            });

        $controller = new OrderProductCollectionController($manager, $hydratorService);
        $response = $controller->__invoke(
            Request::create('/order_products', 'GET', [
                'order' => '/orders/72133',
                'itemsPerPage' => '50',
                'page' => '2',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($payload, json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(
            [
                ['order' => $orderReference],
                ['id' => 'ASC'],
                50,
                50,
            ],
            array_slice($capturedFindByArguments, 0, 4),
        );
        self::assertSame(
            [
                $items,
                OrderProduct::class,
                'order_product:read',
                ['order' => $orderReference],
            ],
            array_slice($capturedCollectionArguments, 0, 4),
        );
    }
}
