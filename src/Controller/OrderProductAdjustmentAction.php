<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\People;
use ControleOnline\Service\OrderProductAdjustmentService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /order_product_adjustments/preview
 * POST /order_product_adjustments/commit
 *
 * Body: { orderProductId, quantityAfter, reason, idempotencyKey, deviceId? }
 */
class OrderProductAdjustmentAction
{
    public function __construct(
        private readonly OrderProductAdjustmentService $adjustmentService,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $mode = (string) $request->attributes->get('_adjustment_mode', 'preview');
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(
                ['ok' => false, 'errno' => 20000, 'errmsg' => 'Invalid JSON body.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $this->security->getUser();
        $actor = $user instanceof People ? $user : null;

        $result = $mode === 'commit'
            ? $this->adjustmentService->commit($payload, $actor, true)
            : $this->adjustmentService->preview($payload, $actor, true);

        if (!($result['ok'] ?? false)) {
            $status = match ($result['errno'] ?? 0) {
                20005, 20007 => Response::HTTP_NOT_FOUND,
                20009 => Response::HTTP_FORBIDDEN,
                20010 => Response::HTTP_CONFLICT,
                default => Response::HTTP_BAD_REQUEST,
            };

            return new JsonResponse([
                'ok' => false,
                'errno' => $result['errno'] ?? 0,
                'errmsg' => $result['errmsg'] ?? 'Adjustment failed.',
            ], $status);
        }

        $body = [
            'ok' => true,
            'mode' => $mode,
            'replayed' => (bool) ($result['replayed'] ?? false),
            'preview' => $result['preview'] ?? null,
        ];

        if (isset($result['entry'])) {
            $entry = $result['entry'];
            $body['entry'] = [
                'id' => $entry->getId(),
                'orderId' => $entry->getOrder()?->getId(),
                'orderProductId' => $entry->getOrderProduct()?->getId(),
                'quantityBefore' => $entry->getQuantityBefore(),
                'quantityAfter' => $entry->getQuantityAfter(),
                'fulfilledAtAdjustment' => $entry->getFulfilledAtAdjustment(),
                'reason' => $entry->getReason(),
                'status' => $entry->getStatus(),
                'idempotencyKey' => $entry->getIdempotencyKey(),
                'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse($body, Response::HTTP_OK);
    }
}
