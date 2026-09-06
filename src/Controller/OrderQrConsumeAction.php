<?php

namespace ControleOnline\Controller;

use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderQrService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /order_qr_contexts/consume — public consume: create/reuse cart or round idempotently.
 */
class OrderQrConsumeAction
{
    public function __construct(
        private OrderQrService $orderQrService,
        private HydratorService $hydratorService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->decode($request);

            // Reject client authority fields
            foreach (['company', 'provider', 'providerId', 'mainOrder', 'mainOrderId', 'local', 'rootOrder'] as $forbidden) {
                if (array_key_exists($forbidden, $payload)) {
                    throw new BadRequestHttpException(sprintf('Client must not send %s; server is authority.', $forbidden));
                }
            }

            $result = $this->orderQrService->consume([
                'token' => $payload['token'] ?? '',
                'idempotencyKey' => $payload['idempotencyKey'] ?? $payload['idempotency_key'] ?? '',
            ]);

            return new JsonResponse($result, Response::HTTP_OK);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => 'QR context not found.'], Response::HTTP_NOT_FOUND);
        } catch (AccessDeniedHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (BadRequestHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse(
                $this->hydratorService->error($e),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function decode(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }
        return $data;
    }
}
