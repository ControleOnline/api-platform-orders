<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\User;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\OrderLoyaltySnapshotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

class FidelityByIdController
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PeopleRoleService $peopleRoleService,
        private readonly OrderLoyaltySnapshotService $orderLoyaltySnapshotService,
        private readonly Security $security,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        $clientId = (int) preg_replace('/\D+/', '', $id);
        if ($clientId <= 0) {
            return new JsonResponse(
                ['error' => 'Client inválido'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $client = $this->manager->getRepository(People::class)->find($clientId);
        if (!$client instanceof People) {
            return new JsonResponse(
                ['error' => 'Client inválido'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user = $this->security->getToken()?->getUser();
        if (!$this->canAccessClient($user, $client)) {
            return new JsonResponse(
                ['error' => 'Você não possui vínculo com esse cliente'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $provider = $this->peopleRoleService->getMainCompany();
        $providerId = (int) ($provider->getId() ?? 0);
        if ($providerId <= 0) {
            return new JsonResponse(
                ['error' => 'Provider inválido'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $showHistory = filter_var(
            $request->query->get('history'),
            FILTER_VALIDATE_BOOL,
        );
        $cards = $this->orderLoyaltySnapshotService->buildForClient(
            $provider,
            $client,
            $showHistory,
        );

        return new JsonResponse([
            'clientId' => $clientId,
            'providerId' => $providerId,
            'count' => count($cards),
            'member' => $cards,
        ]);
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
