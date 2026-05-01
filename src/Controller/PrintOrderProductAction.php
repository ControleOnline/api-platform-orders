<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Spool;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderPrintService;
use ControleOnline\Service\OrderProductService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PrintOrderProductAction
{
    public function __construct(
        private OrderPrintService $print,
        private HydratorService $hydratorService,
        private OrderProductService $orderProductService
    ) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $orderProduct = $this->orderProductService->findOrderProductById($id);

            if (!$orderProduct instanceof OrderProduct) {
                return new JsonResponse(['error' => 'Order product not found'], 404);
            }

            $printData = $this->print->generateOrderProductPrintDataFromContent(
                $orderProduct,
                $request->getContent()
            );

            if (!$printData instanceof Spool) {
                return new JsonResponse(
                    ['error' => 'Nothing to print for the selected product'],
                    422
                );
            }

            return new JsonResponse(
                $this->hydratorService->item(
                    Spool::class,
                    $printData->getId(),
                    "spool_item:read"
                ),
                Response::HTTP_OK
            );
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        } catch (Exception $e) {
            return new JsonResponse($this->hydratorService->error($e));
        }
    }
}
