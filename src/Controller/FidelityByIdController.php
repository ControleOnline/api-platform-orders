<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Service\FidelityByIdService;
use ControleOnline\Service\HydratorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;

class FidelityByIdController
{
    public function __construct(
        private readonly FidelityByIdService $fidelityByIdService,
        private readonly HydratorService $hydratorService,
        private readonly Security $security,
    ) {
    }

    public function __invoke(string $id, Request $request): JsonResponse
    {
        try {
            /*
             * @agents History mode is explicit; default mode keeps the payload to the latest open card.
             */
            $showHistory = filter_var(
                $request->query->get('history'),
                FILTER_VALIDATE_BOOL,
            );
            $cards = $this->fidelityByIdService->buildSnapshot(
                $id,
                $this->security->getToken()?->getUser(),
                $showHistory,
            );

            $payload = $this->hydratorService->collectionData(
                $cards,
                Order::class,
                'order:read',
                [],
                count($cards),
            );

            return new JsonResponse($payload, Response::HTTP_OK);
        } catch (\Throwable $exception) {
            $status = $exception instanceof \RuntimeException && $exception->getMessage() === 'Você não possui vínculo com esse cliente'
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(
                $this->hydratorService->error(
                    new \Exception(
                        $exception->getMessage(),
                        (int) $exception->getCode(),
                        $exception,
                    ),
                ),
                $status,
            );
        }
    }
}
