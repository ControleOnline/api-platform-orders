<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderConferencePrintService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\RequestPayloadService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AutoConferencePrintOrderAction
{
    public function __construct(
        private OrderConferencePrintService $orderConferencePrintService,
        private HydratorService $hydratorService,
        private OrderService $orderService,
        private RequestPayloadService $requestPayloadService,
    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order instanceof Order) {
                return new JsonResponse(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
            }

            $content = trim((string) $request->getContent());
            $payload = $content !== ''
                ? $this->requestPayloadService->decodeJsonContent($content)
                : [];

            $result = $this->orderConferencePrintService->autoPrintIfNeeded(
                $order,
                $payload
            );

            return new JsonResponse([
                ...$result,
                'order' => $this->hydratorService->item(
                    Order::class,
                    $order->getId(),
                    'order:read'
                ),
            ], Response::HTTP_OK);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
