<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\User;
use ControleOnline\Service\FidelityByIdService;
use ControleOnline\Service\OrderLoyaltySnapshotService;
use ControleOnline\Service\PeopleRoleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ControleOnline\Repository\PeopleLinkRepository;

#[AllowMockObjectsWithoutExpectations]
class FidelityByIdServiceTest extends TestCase
{
    public function testBuildSnapshotResolvesClientAndProviderAndReturnsCards(): void
    {
        $client = $this->people(20);
        $provider = $this->people(10);
        $franchise = $this->people(11);
        $linkedPeople = $this->people(99);

        $franchiseLink = new PeopleLink();
        $franchiseLink->setCompany($provider);
        $franchiseLink->setPeople($franchise);
        $franchiseLink->setLinkType('franchisee');
        $franchiseLink->setEnabled(true);

        $user = $this->createMock(User::class);
        $user
            ->method('getPeople')
            ->willReturn($linkedPeople);

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->method('find')
            ->with(20)
            ->willReturn($client);

        $peopleLinkRepository = $this->createMock(PeopleLinkRepository::class);
        $peopleLinkRepository
            ->method('hasLinkWith')
            ->with($user, $client)
            ->willReturn(true);
        $peopleLinkRepository
            ->method('findBy')
            ->with([
                'company' => $provider,
                'linkType' => 'franchisee',
                'enable' => true,
            ])
            ->willReturn([$franchiseLink]);

        $manager = $this->manager([
            People::class => $peopleRepository,
            PeopleLink::class => $peopleLinkRepository,
        ]);

        $peopleRoleService = $this->createMock(PeopleRoleService::class);
        $peopleRoleService
            ->method('getMainCompany')
            ->willReturn($provider);
        $peopleRoleService
            ->method('getAccessibleCompaniesForPeople')
            ->with($linkedPeople)
            ->willReturn([$provider]);

        $snapshotService = $this->createMock(OrderLoyaltySnapshotService::class);
        $snapshotService
            ->expects(self::once())
            ->method('buildForClientAcrossProviders')
            ->with($provider, [$provider, $franchise], $client, true)
            ->willReturn([
                [
                    'provider' => ['id' => 11, 'alias' => 'FRANQUIA 11'],
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701]],
                ],
            ]);

        $service = new FidelityByIdService(
            $manager,
            $peopleRoleService,
            $snapshotService,
        );

        self::assertSame(
            [
                [
                    'provider' => ['id' => 11, 'alias' => 'FRANQUIA 11'],
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701]],
                ],
            ],
            $service->buildSnapshot('20', $user, true),
        );
    }

    public function testBuildSnapshotAllowsClientLinkedToMainCompanyAcrossFranchiseNetwork(): void
    {
        $client = $this->people(20);
        $provider = $this->people(10);
        $franchise = $this->people(11);
        $linkedPeople = $this->people(99);

        $franchiseLink = new PeopleLink();
        $franchiseLink->setCompany($provider);
        $franchiseLink->setPeople($franchise);
        $franchiseLink->setLinkType('franchisee');
        $franchiseLink->setEnabled(true);

        $clientLink = new PeopleLink();
        $clientLink->setCompany($provider);
        $clientLink->setPeople($client);
        $clientLink->setLinkType('client');
        $clientLink->setEnabled(true);

        $user = $this->createMock(User::class);
        $user
            ->method('getPeople')
            ->willReturn($linkedPeople);

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->method('find')
            ->with(20)
            ->willReturn($client);

        $peopleLinkRepository = $this->createMock(PeopleLinkRepository::class);
        $peopleLinkRepository
            ->method('hasLinkWith')
            ->with($user, $client)
            ->willReturn(false);
        $peopleLinkRepository
            ->method('findBy')
            ->willReturnCallback(
                function (array $criteria) use ($provider, $client, $franchiseLink, $clientLink): array {
                    if (
                        ($criteria['company'] ?? null) === $provider
                        && ($criteria['linkType'] ?? null) === 'franchisee'
                        && ($criteria['enable'] ?? null) === true
                    ) {
                        return [$franchiseLink];
                    }

                    if (
                        ($criteria['company'] ?? null) === [(int) $provider->getId(), 11]
                        && ($criteria['people'] ?? null) === $client
                        && ($criteria['linkType'] ?? null) === 'client'
                        && ($criteria['enable'] ?? null) === true
                    ) {
                        return [$clientLink];
                    }

                    return [];
                },
            );

        $manager = $this->manager([
            People::class => $peopleRepository,
            PeopleLink::class => $peopleLinkRepository,
        ]);

        $peopleRoleService = $this->createMock(PeopleRoleService::class);
        $peopleRoleService
            ->method('getMainCompany')
            ->willReturn($provider);
        $peopleRoleService
            ->expects(self::once())
            ->method('getAccessibleCompaniesForPeople')
            ->with($linkedPeople)
            ->willReturn([$franchise]);

        $snapshotService = $this->createMock(OrderLoyaltySnapshotService::class);
        $snapshotService
            ->expects(self::once())
            ->method('buildForClientAcrossProviders')
            ->with($provider, [$provider, $franchise], $client, false)
            ->willReturn([
                [
                    'provider' => ['id' => 10, 'alias' => 'FRANQUIA 10'],
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701], ['id' => 702], ['id' => 703]],
                ],
            ]);

        $service = new FidelityByIdService(
            $manager,
            $peopleRoleService,
            $snapshotService,
        );

        self::assertSame(
            [
                [
                    'provider' => ['id' => 10, 'alias' => 'FRANQUIA 10'],
                    'card' => ['id' => 500],
                    'requiredSales' => 3,
                    'stamps' => [['id' => 701], ['id' => 702], ['id' => 703]],
                ],
            ],
            $service->buildSnapshot('20', $user, false),
        );
    }

    public function testBuildSnapshotRejectsWhenClientHasNoAccess(): void
    {
        $client = $this->people(20);
        $provider = $this->people(10);
        $linkedPeople = $this->people(99);

        $user = $this->createMock(User::class);
        $user
            ->method('getPeople')
            ->willReturn($linkedPeople);

        $peopleRepository = $this->createMock(EntityRepository::class);
        $peopleRepository
            ->method('find')
            ->with(20)
            ->willReturn($client);

        $peopleLinkRepository = $this->createMock(PeopleLinkRepository::class);
        $peopleLinkRepository
            ->method('hasLinkWith')
            ->with($user, $client)
            ->willReturn(false);
        $peopleLinkRepository
            ->method('findBy')
            ->willReturn([]);

        $manager = $this->manager([
            People::class => $peopleRepository,
            PeopleLink::class => $peopleLinkRepository,
        ]);

        $peopleRoleService = $this->createMock(PeopleRoleService::class);
        $peopleRoleService
            ->method('getMainCompany')
            ->willReturn($provider);
        $peopleRoleService
            ->expects(self::once())
            ->method('getAccessibleCompaniesForPeople')
            ->with($linkedPeople)
            ->willReturn([]);

        $snapshotService = $this->createMock(OrderLoyaltySnapshotService::class);
        $snapshotService
            ->expects(self::never())
            ->method('buildForClientAcrossProviders');

        $service = new FidelityByIdService(
            $manager,
            $peopleRoleService,
            $snapshotService,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Você não possui vínculo com esse cliente');

        $service->buildSnapshot('20', $user, true);
    }

    private function manager(array $repositories): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->method('getRepository')
            ->willReturnCallback(fn (string $class) => $repositories[$class] ?? $this->createMock(EntityRepository::class));

        return $manager;
    }

    private function people(int $id): People
    {
        $people = new People();
        $people->setName(sprintf('Empresa %d', $id));
        $people->setAlias(sprintf('Franquia %d', $id));
        $people->setEnabled(true);
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
