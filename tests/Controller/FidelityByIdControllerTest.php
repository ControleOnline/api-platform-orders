<?php

namespace ControleOnline\Orders\Tests\Controller;

use ControleOnline\Controller\FidelityByIdController;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use ControleOnline\Service\OrderLoyaltySnapshotService;
use ControleOnline\Service\PeopleRoleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

#[AllowMockObjectsWithoutExpectations]
class FidelityByIdControllerTest extends TestCase
{
    public function testReturnsSnapshotPayloadForTheAccessingClient(): void
    {
        $client = $this->people(20);
        $provider = $this->people(10);

        $user = $this->createMock(User::class);
        $user
            ->method('getPeople')
            ->willReturn($client);

        $token = $this->createMock(TokenInterface::class);
        $token
            ->method('getUser')
            ->willReturn($user);

        $security = $this->createMock(Security::class);
        $security
            ->method('getToken')
            ->willReturn($token);

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->method('find')
            ->with(20)
            ->willReturn($client);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(function (string $class) use ($peopleRepository) {
                return match ($class) {
                    People::class => $peopleRepository,
                    default => $this->createMock(EntityRepository::class),
                };
            });

        $peopleRoleService = $this->createMock(PeopleRoleService::class);
        $peopleRoleService
            ->method('getMainCompany')
            ->willReturn($provider);

        $snapshotService = $this->createMock(OrderLoyaltySnapshotService::class);
        $snapshotService
            ->method('buildForClient')
            ->with($provider, $client, true)
            ->willReturn([
                [
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701]],
                ],
            ]);

        $controller = new FidelityByIdController(
            $manager,
            $peopleRoleService,
            $snapshotService,
            $security,
        );

        $response = $controller->__invoke('20', Request::create('/orders/fidelityById/20?history=1', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'clientId' => 20,
                'providerId' => 10,
                'count' => 1,
                'member' => [
                    [
                        'card' => ['id' => 500],
                        'requiredSales' => 3,
                        'stamps' => [['id' => 701]],
                    ],
                ],
            ],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function people(int $id): People
    {
        $people = new People();
        $reflection = new \ReflectionObject($people);
        while ($reflection) {
            if ($reflection->hasProperty('id')) {
                $property = $reflection->getProperty('id');
                $property->setAccessible(true);
                $property->setValue($people, $id);
                return $people;
            }

            $reflection = $reflection->getParentClass();
        }

        return $people;
    }
}
