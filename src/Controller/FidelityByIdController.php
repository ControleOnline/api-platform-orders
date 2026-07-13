<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\User;
use ControleOnline\Repository\OrderRepository;
use ControleOnline\Service\PeopleRoleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

class FidelityByIdController
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly OrderRepository $orderRepository,
        private readonly PeopleRoleService $peopleRoleService,
        private readonly Security $security,
    ) {
    }

    public function __invoke(string $id): JsonResponse
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

        $rows = $this->orderRepository->findOpenFidelityByClientAndProvider(
            $clientId,
            $providerId,
        );

        return new JsonResponse([
            'clientId' => $clientId,
            'providerId' => $providerId,
            'count' => count($rows),
            'member' => $rows,
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
