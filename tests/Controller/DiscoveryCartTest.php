<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\DiscoveryCart;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

final class DiscoveryCartTest extends TestCase
{
    private function peopleWithId(int $id): People
    {
        $people = $this->createMock(People::class);
        $people->method('getId')->willReturn($id);
        return $people;
    }

    private function invoke(array $query, bool $linked = true): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $userPeople = $this->peopleWithId(99);
        $user = $this->createMock(User::class);
        $user->method('getPeople')->willReturn($userPeople);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $security = $this->createMock(Security::class);
        $security->method('getToken')->willReturn($token);

        $client = isset($query['client']) ? $this->peopleWithId((int) $query['client']) : null;
        $provider = isset($query['provider']) ? $this->peopleWithId((int) $query['provider']) : null;

        $peopleRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $peopleRepo->method('find')->willReturnCallback(function ($id) use ($client, $provider, $query) {
            if (isset($query['client']) && (string) $id === (string) $query['client']) {
                return $client;
            }
            if (isset($query['provider']) && (string) $id === (string) $query['provider']) {
                return $provider;
            }
            return null;
        });

        $linkRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['hasLinkWith'])
            ->getMock();
        $linkRepo->method('hasLinkWith')->willReturn($linked);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $orderRepo = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneBy'])
            ->getMock();
        $orderRepo->method('findOneBy')->willReturn($order);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($peopleRepo, $linkRepo, $orderRepo) {
            return match ($class) {
                People::class => $peopleRepo,
                PeopleLink::class => $linkRepo,
                Order::class => $orderRepo,
                default => $peopleRepo,
            };
        });

        $status = $this->createMock(Status::class);
        $statusService = $this->createMock(StatusService::class);
        $statusService->method('discoveryStatus')->willReturn($status);

        $orderService = $this->createMock(OrderService::class);
        $orderService->method('normalizeDraftCartOrder')->willReturn(false);

        $hydrator = $this->createMock(HydratorService::class);
        $hydrator->method('item')->willReturn(['id' => 42, 'orderType' => 'cart']);
        $hydrator->method('error')->willReturnCallback(static fn(\Throwable $e) => ['error' => $e->getMessage()]);

        $controller = new DiscoveryCart($hydrator, $em, $orderService, $statusService, $security);
        return $controller(Request::create('/cart', 'GET', $query));
    }

    public function testRequiresClient(): void
    {
        $response = $this->invoke(['provider' => '1', 'orderType' => 'cart']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Client é obrigatório', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testRequiresProvider(): void
    {
        $response = $this->invoke(['client' => '1', 'orderType' => 'cart']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Provider é obrigatório', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testResolvesCartWithProviderClientAndOrderTypeQuery(): void
    {
        $response = $this->invoke([
            'provider' => '1',
            'client' => '1',
            'orderType' => 'cart',
        ]);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(42, $payload['id']);
        self::assertSame('cart', $payload['orderType']);
    }

    public function testDefaultsStillRequireClientAndProviderWhenOrderTypeAbsent(): void
    {
        $response = $this->invoke([]);
        self::assertSame(400, $response->getStatusCode());
    }
}
