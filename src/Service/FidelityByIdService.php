<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class FidelityByIdService
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PeopleRoleService $peopleRoleService,
        private readonly OrderLoyaltySnapshotService $orderLoyaltySnapshotService,
    ) {
    }

    /**
     * @agents Resolve the loyalty snapshot for the current client through the canonical access path.
     * The controller must stay thin, so input validation, company resolution, and access checks live here.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildSnapshot(string $rawClientId, mixed $user, bool $showHistory = false): array
    {
        $client = $this->resolveClient($rawClientId);

        if (!$this->canAccessClient($user, $client)) {
            throw new \RuntimeException('Você não possui vínculo com esse cliente');
        }

        $provider = $this->resolveProvider();
        $providers = $this->resolveNetworkProviders($provider);

        /*
         * @agents The snapshot service remains the single source of truth for the card chain.
         * This wrapper only resolves the people involved and forwards the history flag.
         */
        return $this->orderLoyaltySnapshotService->buildForClientAcrossProviders(
            $provider,
            $providers,
            $client,
            $showHistory,
        );
    }

    private function resolveClient(string $rawClientId): People
    {
        $clientId = (int) preg_replace('/\D+/', '', $rawClientId);
        if ($clientId <= 0) {
            throw new \InvalidArgumentException('Client inválido');
        }

        $client = $this->manager->getRepository(People::class)->find($clientId);
        if (!$client instanceof People) {
            throw new \InvalidArgumentException('Client inválido');
        }

        return $client;
    }

    private function resolveProvider(): People
    {
        $provider = $this->peopleRoleService->getMainCompany();
        $providerId = (int) ($provider->getId() ?? 0);
        if ($providerId <= 0) {
            throw new \InvalidArgumentException('Provider inválido');
        }

        return $provider;
    }

    /**
     * @agents Loyalty cards stay owned by the company that stamped them.
     * The shop reads only the main company and its active direct franchisees.
     *
     * @return People[]
     */
    private function resolveNetworkProviders(People $mainCompany): array
    {
        $providers = [(int) $mainCompany->getId() => $mainCompany];
        $links = $this->manager->getRepository(PeopleLink::class)->findBy([
            'company' => $mainCompany,
            'linkType' => 'franchisee',
            'enable' => true,
        ]);

        foreach ($links as $link) {
            if (!$link instanceof PeopleLink || !$link->getEnabled()) {
                continue;
            }

            $company = $link->getPeople();
            if (!$company instanceof People || !$company->getEnabled()) {
                continue;
            }

            $companyId = (int) ($company->getId() ?? 0);
            if ($companyId > 0) {
                $providers[$companyId] = $company;
            }
        }

        return array_values($providers);
    }

    private function canAccessClient(mixed $user, People $client): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $userPeople = $user->getPeople();
        if ($userPeople instanceof People && (int) $userPeople->getId() === (int) $client->getId()) {
            return true;
        }

        return $this->manager
            ->getRepository(PeopleLink::class)
            ->hasLinkWith($user, $client);
    }
}
