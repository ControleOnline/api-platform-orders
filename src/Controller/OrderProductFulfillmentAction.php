<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderProductFulfillment;
use ControleOnline\Entity\People;
use ControleOnline\Entity\User;
use ControleOnline\Service\OrderFulfillmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * POST /order_product_fulfillments/execute
 * Body: { orderProductId, action, quantity?, idempotencyKey, deviceOrigin?, reason? }
 */
class OrderProductFulfillmentAction
{
    public function __construct(
        private OrderFulfillmentService $fulfillmentService,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true);
            if (!is_array($payload)) {
                throw new BadRequestHttpException('Invalid JSON body.');
            }

            $token = $this->tokenStorage->getToken();
            $user = $token?->getUser();
            $actor = null;
            $isClient = false;

            if ($user instanceof User) {
                $people = method_exists($user, 'getPeople') ? $user->getPeople() : null;
                if ($people instanceof People) {
                    $actor = $people;
                }
            }

            // ROLE_CLIENT alone cannot complete fulfillment (authorization rule).
            $roles = $token?->getRoleNames() ?? [];
            if (in_array('ROLE_CLIENT', $roles, true)
                && !in_array('ROLE_HUMAN', $roles, true)
                && !in_array('ROLE_ADMIN', $roles, true)
                && !in_array('ROLE_SUPER', $roles, true)
            ) {
                $isClient = true;
            }

            $entry = $this->fulfillmentService->execute($payload, $actor, $isClient);

            return new JsonResponse(
                $this->serialize($entry),
                Response::HTTP_OK
            );
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                $e->getStatusCode()
            );
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function serialize(OrderProductFulfillment $entry): array
    {
        return [
            'id' => $entry->getId(),
            'order' => $entry->getOrder()->getId(),
            'orderProduct' => $entry->getOrderProduct()->getId(),
            'action' => $entry->getAction(),
            'quantity' => $entry->getQuantity(),
            'status' => $entry->getStatus(),
            'idempotencyKey' => $entry->getIdempotencyKey(),
            'deviceOrigin' => $entry->getDeviceOrigin(),
            'reason' => $entry->getReason(),
            'actor' => $entry->getActor()?->getId(),
            'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
