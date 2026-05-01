<?php

namespace ControleOnline\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\Spool;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderPrintService;
use ControleOnline\Service\OrderService;
use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PrintOrderAction
{
    public function __construct(
        private OrderPrintService $print,
        private HydratorService $hydratorService,
        private OrderService $orderService

    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order) {
                return new JsonResponse(['error' => 'Order not found'], 404);
            }

            $printData = $this->print->generatePrintDataFromContent(
                $order,
                $request->getContent()
            );

            if (!$printData) {
                return new JsonResponse(
                    ['error' => 'Nothing to print for the selected queue data'],
                    422
                );
            }

            return new JsonResponse($this->hydratorService->item(Spool::class, $printData->getId(), "spool_item:read"), Response::HTTP_OK);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
