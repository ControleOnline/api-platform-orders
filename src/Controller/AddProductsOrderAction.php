<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

class AddProductsOrderAction
{
    public function __construct(
        private HydratorService $hydratorService,
        private OrderProductService $orderProductService,
        private OrderService $orderService,
        private Security $security,
        private PeopleService $peopleService,
    ) {
    }

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order instanceof Order) {
                return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
            }

            if (!$this->canAccessOrder($order)) {
                return new JsonResponse(
                    ['error' => 'Pedido não encontrado ou acesso negado'],
                    Response::HTTP_FORBIDDEN
                );
            }

            $this->orderProductService->addProductsToOrderFromContent(
                $order,
                $request->getContent()
            );

            return new JsonResponse(
                $this->hydratorService->item(Order::class, $order->getId(), 'order:write'),
                Response::HTTP_OK
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }

    private function canAccessOrder(Order $order): bool
    {
        $user = $this->security->getToken()?->getUser();
        if (!$user || !method_exists($user, 'getPeople')) {
            return false;
        }

        $userPeople = $user->getPeople();
        if (!$userPeople instanceof People) {
            return false;
        }

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        return $this->peopleService->canAccessCompany($provider, $userPeople);
    }
}
