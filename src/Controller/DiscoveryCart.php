<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
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
            $order = null;
            if ($userPeople) {
                $status = $this->statusService->discoveryStatus('open', 'open', 'order');

                $order = $this->manager->getRepository(Order::class)->findOneBy([
                    'client' => $userPeople,
                    'status' => $status
                ]);

                if (!$order) {
                    $order = new Order();
                    $order->setStatus($status);
                    $order->setClient($userPeople);
                    $order->setOrderType('order');
                    $order->setApp('SHOP');
                    //$order->setProvider();
                    //$order->setPayer();
                    $this->manager->persist($order);

                    $this->manager->flush();
                }
            }

            return new JsonResponse($this->hydratorService->data($order, 'order_details:read'), Response::HTTP_OK);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
