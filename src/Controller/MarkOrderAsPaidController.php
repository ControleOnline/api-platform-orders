<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\MarkOrderAsPaidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /orders/{orderId}/mark-as-paid — app-community#797
 */
#[IsGranted('ROLE_HUMAN')]
class MarkOrderAsPaidController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private MarkOrderAsPaidService $markOrderAsPaidService,
        private HydratorService $hydratorService,
    ) {}

    #[Route('/orders/{orderId}/mark-as-paid', name: 'order_mark_as_paid', methods: ['POST'])]
    public function __invoke(int $orderId, Request $request): JsonResponse
    {
        try {
            $order = $this->manager->getRepository(Order::class)->find($orderId);
            if (!$order instanceof Order) {
                return $this->json([
                    'outcome' => 'refused',
                    'message' => 'Pedido nao encontrado.',
                ], Response::HTTP_NOT_FOUND);
            }

            $actor = $this->getAuthenticatedPeople();
            if (!$actor instanceof People) {
                return $this->json([
                    'outcome' => 'refused',
                    'message' => 'Usuario nao autenticado.',
                ], Response::HTTP_FORBIDDEN);
            }

            $payload = $this->decodePayload($request);
            $result = $this->markOrderAsPaidService->markAsPaid($order, $payload, $actor);

            return $this->json($result, Response::HTTP_OK);
        } catch (AccessDeniedHttpException $e) {
            return $this->json([
                'outcome' => 'refused',
                'message' => $e->getMessage(),
            ], Response::HTTP_FORBIDDEN);
        } catch (BadRequestHttpException $e) {
            return $this->json([
                'outcome' => 'refused',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $body = [
                'outcome' => 'error',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'detail' => $e->getMessage(),
                'exception' => $e::class,
            ];
            try {
                $hydrated = $this->hydratorService->error($e);
                if (is_array($hydrated)) {
                    $body = array_merge($body, $hydrated);
                }
            } catch (\Throwable) {
                // keep body
            }

            return $this->json($body, Response::HTTP_INTERNAL_SERVER_ERROR);
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
}
