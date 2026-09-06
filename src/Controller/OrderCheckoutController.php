<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderCheckoutService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

/**
 * Canonical checkout endpoint for order finalization across operational modes.
 *
 * POST /orders/{orderId}/checkout
 *
 * @see https://github.com/ControleOnline/api-community/issues/60
 */
#[SecurityAttribute("is_granted('ROLE_HUMAN')")]
class OrderCheckoutController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleService $peopleService,
        private OrderCheckoutService $orderCheckoutService,
        private HydratorService $hydratorService,
    ) {}

    #[Route('/orders/{orderId}/checkout', name: 'order_checkout', methods: ['POST'])]
    public function checkout(int $orderId, Request $request): JsonResponse
    {
        try {
            $order = $this->manager->getRepository(Order::class)->find($orderId);
            if (!$order instanceof Order) {
                return $this->json([
                    'outcome' => OrderCheckoutService::OUTCOME_REFUSED,
                    'errno' => 404,
                    'errmsg' => 'Order not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            if (!$this->canAccessOrder($order)) {
                return $this->json([
                    'outcome' => OrderCheckoutService::OUTCOME_REFUSED,
                    'errno' => 403,
                    'errmsg' => 'Access denied to this order.',
                ], Response::HTTP_FORBIDDEN);
            }

            $payload = $this->decodePayload($request);
            $headerKey = $request->headers->get('Idempotency-Key');
            if ($headerKey && empty($payload['idempotencyKey'])) {
                $payload['idempotencyKey'] = $headerKey;
            }

            $result = $this->orderCheckoutService->checkout(
                $order,
                $payload,
                $this->getAuthenticatedPeople()
            );

            $status = match ($result['outcome'] ?? '') {
                OrderCheckoutService::OUTCOME_SUCCESS => Response::HTTP_OK,
                OrderCheckoutService::OUTCOME_CONFLICT => Response::HTTP_CONFLICT,
                OrderCheckoutService::OUTCOME_REFUSED => Response::HTTP_BAD_REQUEST,
                OrderCheckoutService::OUTCOME_CANCELED => Response::HTTP_CONFLICT,
                OrderCheckoutService::OUTCOME_PENDING => Response::HTTP_ACCEPTED,
                default => Response::HTTP_INTERNAL_SERVER_ERROR,
            };

            return $this->json($result, $status);
        } catch (\Throwable $e) {
            return $this->json($this->hydratorService->error($e), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function decodePayload(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getAuthenticatedPeople(): ?People
    {
        $user = $this->security->getToken()?->getUser();
        if (!is_object($user) || !method_exists($user, 'getPeople')) {
            return null;
        }

        $people = $user->getPeople();
        return $people instanceof People ? $people : null;
    }

    private function canAccessOrder(Order $order): bool
    {
        $userPeople = $this->getAuthenticatedPeople();
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
