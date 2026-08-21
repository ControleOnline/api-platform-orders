<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderQrService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * POST /order_qr_contexts/emit — authorized staff issues opaque QR token.
 */
class OrderQrEmitAction
{
    public function __construct(
        private EntityManagerInterface $manager,
        private OrderQrService $orderQrService,
        private PeopleService $peopleService,
        private HydratorService $hydratorService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->decode($request);

            $providerId = $payload['provider'] ?? $payload['providerId'] ?? null;
            if (is_array($providerId)) {
                $providerId = $providerId['id'] ?? null;
            }
            if ($providerId === null || $providerId === '') {
                throw new BadRequestHttpException('provider is required.');
            }

            $provider = $this->manager->getRepository(People::class)->find((int) $providerId);
            if (!$provider instanceof People) {
                throw new BadRequestHttpException('provider not found.');
            }

            $rootOrder = null;
            $rootOrderId = $payload['rootOrder'] ?? $payload['rootOrderId'] ?? null;
            if (is_array($rootOrderId)) {
                $rootOrderId = $rootOrderId['id'] ?? null;
            }
            if ($rootOrderId !== null && $rootOrderId !== '') {
                $rootOrder = $this->manager->getRepository(Order::class)->find((int) $rootOrderId);
                if (!$rootOrder instanceof Order) {
                    throw new BadRequestHttpException('rootOrder not found.');
                }
            }

            $issuedBy = null;
            try {
                if (method_exists($this->peopleService, 'getLoggedPeople')) {
                    $issuedBy = $this->peopleService->getLoggedPeople();
                }
            } catch (\Throwable $e) {
                $issuedBy = null;
            }

            [$context, $rawToken] = $this->orderQrService->emit([
                'purpose' => $payload['purpose'] ?? null,
                'linkType' => $payload['linkType'] ?? $payload['link_type'] ?? null,
                'provider' => $provider,
                'externalCode' => $payload['externalCode'] ?? $payload['external_code'] ?? null,
                'rootOrder' => $rootOrder,
                'expiresInSeconds' => $payload['expiresInSeconds'] ?? $payload['expires_in'] ?? null,
                'issuedBy' => $issuedBy instanceof People ? $issuedBy : null,
                'keyVersion' => $payload['keyVersion'] ?? 1,
            ]);

            return new JsonResponse([
                'token' => $rawToken,
                'context' => [
                    'id' => $context->getId(),
                    'purpose' => $context->getPurpose(),
                    'linkType' => $context->getLinkType(),
                    'externalCode' => $context->getExternalCode(),
                    'status' => $context->getStatus(),
                    'expiresAt' => $context->getExpiresAt()?->format(DATE_ATOM),
                    'keyVersion' => $context->getKeyVersion(),
                ],
            ], Response::HTTP_CREATED);
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
