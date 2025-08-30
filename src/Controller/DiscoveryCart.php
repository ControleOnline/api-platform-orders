<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
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
            $userPeople = $this->security->getToken()?->getUser()?->getPeople();

            $providerId = $request->get('provider');
            if (!$providerId) {
                return new JsonResponse(['error' => 'Provider é obrigatório'], Response::HTTP_BAD_REQUEST);
            }
            $provider = $this->manager->getRepository(People::class)->find($providerId);
            if (!$provider) {
                return new JsonResponse(['error' => 'Provider inválido'], Response::HTTP_BAD_REQUEST);
            }

            $order = null;
            if ($userPeople) {
                $status = $this->statusService->discoveryStatus('open', 'open', 'order');

                $order = $this->manager->getRepository(Order::class)->findOneBy([
                    'client' => $userPeople,
                    'provider' => $provider,
                    'status' => $status
                ]);

                if (!$order) {
                    $order = new Order();
                    $order->setStatus($status);
                    $order->setClient($userPeople);
                    $order->setOrderType('order');
                    $order->setApp('SHOP');
                    $order->setProvider($provider);
                    //$order->setPayer();
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
