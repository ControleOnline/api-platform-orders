<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderService;
use Exception;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

class UpdateOrderAction
{
    public function __construct(
        private readonly HydratorService $hydratorService,
        private readonly OrderService $orderService,
    ) {
    }

    public function __invoke(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->orderService->findOrderById($id);
            if (!$order instanceof Order) {
                return new JsonResponse(
                    $this->hydratorService->error(new Exception('Order not found')),
                    Response::HTTP_NOT_FOUND,
                );
            }

            $payload = json_decode(
                trim((string) $request->getContent()) ?: '[]',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (!is_array($payload)) {
                throw new BadRequestHttpException('Payload do pedido invalido.');
            }

            // PUT so aceita apenas edicao de dados de negocio; transicoes de status ficam no fluxo operacional.
            $updatedOrder = $this->orderService->updateOrderFromPayload($order, $payload);

            return new JsonResponse(
                $this->hydratorService->item(
                    Order::class,
                    $updatedOrder->getId(),
                    'order:write',
                ),
                Response::HTTP_OK,
            );
        } catch (BadRequestHttpException | JsonException | SerializerExceptionInterface $exception) {
            return new JsonResponse(
                $this->hydratorService->error(
                    new Exception(
                        $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception,
                    ),
                ),
                Response::HTTP_BAD_REQUEST,
            );
        } catch (Exception $exception) {
            return new JsonResponse(
                $this->hydratorService->error($exception),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
