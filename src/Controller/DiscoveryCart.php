<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use Symfony\Component\HttpFoundation\Response;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\StatusService;
use Exception;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;

class DiscoveryCart
{
    public function __construct(
        private HydratorService $hydratorService,
        private EntityManagerInterface $manager,
        private StatusService $statusService,
        private Security $security
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {

            /**
             * @var \ControleOnline\Entity\People
             */
            $user = $this->security->getToken()?->getUser();
            $clientId = $request->get('client');
            $providerId = $request->get('provider');

            if (!$clientId) {
                return new JsonResponse(['error' => 'Client é obrigatório'], Response::HTTP_BAD_REQUEST);
            }

            $client = $this->manager->getRepository(People::class)->find($clientId);
            
            if (!$client) {
                return new JsonResponse(['error' => 'Client inválido'], Response::HTTP_BAD_REQUEST);
            }

            if (
                !$this->manager->getRepository(PeopleLink::class)->hasLinkWith($user, $client) &&
                $user->getPeople()->getId() != $client->getId()
            ) {
                return new JsonResponse(['error' => 'Você não possui vínculo com esse cliente'], Response::HTTP_FORBIDDEN);
            }
            
            if (!$providerId) {
                return new JsonResponse(['error' => 'Provider é obrigatório'], Response::HTTP_BAD_REQUEST);
            }

            $provider = $this->manager->getRepository(People::class)->find($providerId);
            if (!$provider) {
                return new JsonResponse(['error' => 'Provider inválido'], Response::HTTP_BAD_REQUEST);
            }


            $order = null;
            if ($client) {
                $status = $this->statusService->discoveryStatus('open', 'open', 'order');

                $order = $this->manager->getRepository(Order::class)->findOneBy([
                    'client' => $client,
                    'provider' => $provider,
                    'status' => $status
                ]);

                if (!$order) {
                    $order = new Order();
                    $order->setStatus($status);
                    $order->setClient($client);
                    $order->setOrderType('order');
                    $order->setApp('SHOP');
                    $order->setProvider($provider);                    
                    $this->manager->persist($order);
                    $this->manager->flush();
                }
            }

            return new JsonResponse($this->hydratorService->item(Order::class, $order->getId(), 'order_details:read'), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
