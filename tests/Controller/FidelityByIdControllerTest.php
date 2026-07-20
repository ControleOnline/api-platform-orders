<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\FidelityByIdController;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\User;
use ControleOnline\Service\FidelityByIdService;
use ControleOnline\Service\HydratorService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

#[AllowMockObjectsWithoutExpectations]
class FidelityByIdControllerTest extends TestCase
{
    public function testReturnsHydratedSnapshotPayloadForTheAccessingClient(): void
    {
        $user = $this->createMock(User::class);

        $token = $this->createMock(TokenInterface::class);
        $token
            ->method('getUser')
            ->willReturn($user);

        $security = $this->createMock(Security::class);
        $security
            ->method('getToken')
            ->willReturn($token);

        $fidelityService = $this->createMock(FidelityByIdService::class);
        $fidelityService
            ->expects(self::once())
            ->method('buildSnapshot')
            ->with('20', $user, true)
            ->willReturn([
                [
                    'provider' => ['id' => 11, 'name' => 'FRANQUIA 11', 'alias' => 'FRANQUIA 11'],
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701]],
                ],
            ]);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('collectionData')
            ->with(
                [
                    [
                        'provider' => ['id' => 11, 'name' => 'FRANQUIA 11', 'alias' => 'FRANQUIA 11'],
                        'card' => ['id' => 500],
                        'requiredSales' => 3,
                        'stamps' => [['id' => 701]],
                    ],
                ],
                Order::class,
                'order:read',
                [],
                1,
                [
                    'historyRequested' => true,
                    'historyEmpty' => false,
                ],
            )
            ->willReturn([
                '@context' => '/contexts/Order',
                '@id' => '/orders',
                '@type' => 'Collection',
                'view' => [
                    '@id' => '/orders/fidelityById/20?history=1',
                    '@type' => 'PartialCollectionView',
                ],
                'member' => [
                    [
                        'provider' => ['id' => 11, 'name' => 'FRANQUIA 11', 'alias' => 'FRANQUIA 11'],
                        'card' => ['id' => 500],
                        'requiredSales' => 3,
                        'stamps' => [['id' => 701]],
                    ],
                ],
                'search' => [],
                'totalItems' => 1,
                'summary' => [
                    'historyRequested' => true,
                    'historyEmpty' => false,
                ],
            ]);

        $controller = new FidelityByIdController(
            $fidelityService,
            $hydratorService,
            $security,
        );

        $response = $controller->__invoke('20', Request::create('/orders/fidelityById/20?history=1', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                '@context' => '/contexts/Order',
                '@id' => '/orders',
                '@type' => 'Collection',
                'view' => [
                    '@id' => '/orders/fidelityById/20?history=1',
                    '@type' => 'PartialCollectionView',
                ],
                'member' => [
                    [
                        'provider' => ['id' => 11, 'name' => 'FRANQUIA 11', 'alias' => 'FRANQUIA 11'],
                        'card' => ['id' => 500],
                        'requiredSales' => 3,
                        'stamps' => [['id' => 701]],
                    ],
                ],
                'search' => [],
                'totalItems' => 1,
                'summary' => [
                    'historyRequested' => true,
                    'historyEmpty' => false,
                ],
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReturnsEmptyHistorySummaryWhenNoHistoricalCardsExist(): void
    {
        $user = $this->createMock(User::class);

        $token = $this->createMock(TokenInterface::class);
        $token
            ->method('getUser')
            ->willReturn($user);

        $security = $this->createMock(Security::class);
        $security
            ->method('getToken')
            ->willReturn($token);

        $fidelityService = $this->createMock(FidelityByIdService::class);
        $fidelityService
            ->expects(self::once())
            ->method('buildSnapshot')
            ->with('20', $user, true)
            ->willReturn([]);

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('collectionData')
            ->with(
                [],
                Order::class,
                'order:read',
                [],
                0,
                [
                    'historyRequested' => true,
                    'historyEmpty' => true,
                ],
            )
            ->willReturn([
                '@context' => '/contexts/Order',
                '@id' => '/orders',
                '@type' => 'Collection',
                'view' => [
                    '@id' => '/orders/fidelityById/20?history=1',
                    '@type' => 'PartialCollectionView',
                ],
                'member' => [],
                'search' => [],
                'totalItems' => 0,
                'summary' => [
                    'historyRequested' => true,
                    'historyEmpty' => true,
                ],
            ]);

        $controller = new FidelityByIdController(
            $fidelityService,
            $hydratorService,
            $security,
        );

        $response = $controller->__invoke('20', Request::create('/orders/fidelityById/20?history=1', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                '@context' => '/contexts/Order',
                '@id' => '/orders',
                '@type' => 'Collection',
                'view' => [
                    '@id' => '/orders/fidelityById/20?history=1',
                    '@type' => 'PartialCollectionView',
                ],
                'member' => [],
                'search' => [],
                'totalItems' => 0,
                'summary' => [
                    'historyRequested' => true,
                    'historyEmpty' => true,
                ],
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReturnsHydratedErrorWhenSnapshotServiceRejectsClient(): void
    {
        $token = $this->createMock(TokenInterface::class);
        $token
            ->method('getUser')
            ->willReturn($this->createMock(User::class));

        $security = $this->createMock(Security::class);
        $security
            ->method('getToken')
            ->willReturn($token);

        $fidelityService = $this->createMock(FidelityByIdService::class);
        $fidelityService
            ->expects(self::once())
            ->method('buildSnapshot')
            ->willThrowException(new \InvalidArgumentException('Client inválido'));

        $hydratorService = $this->createMock(HydratorService::class);
        $hydratorService
            ->expects(self::once())
            ->method('error')
            ->willReturn([
                '@context' => '/contexts/Error',
                '@type' => 'Error',
                'hydra:title' => 'An error occurred',
                'hydra:description' => 'Client inválido',
            ]);

        $controller = new FidelityByIdController(
            $fidelityService,
            $hydratorService,
            $security,
        );

        $response = $controller->__invoke('20', Request::create('/orders/fidelityById/20?history=1', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            [
                '@context' => '/contexts/Error',
                '@type' => 'Error',
                'hydra:title' => 'An error occurred',
                'hydra:description' => 'Client inválido',
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
