<?php

namespace ControleOnline\Service;

use DateTimeImmutable;
use ControleOnline\Entity\Category;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

/** Methods shared by the original class; contracts and visibility are unchanged. */
trait OrderActionOperations1
{
    private function getCancellationReasonCompany(Order $order, ?People $company = null): ?People
    {
        if ($company instanceof People) {
            return $company;
        }

        $provider = $order->getProvider();

        return $provider instanceof People ? $provider : null;
    }

    private function resolveCancellationReason(Order $order, mixed $reasonId, ?People $company = null): ?Category
    {
        $normalizedReasonId = $this->normalizeOptionalNumericId($reasonId);
        if (!$normalizedReasonId) {
            return null;
        }

        $reasonCompany = $this->getCancellationReasonCompany($order, $company);
        $repository = $this->entityManager->getRepository(Category::class);

        if ($reasonCompany instanceof People) {
            $category = $repository->findOneBy([
                'id' => $normalizedReasonId,
                'company' => $reasonCompany,
                'context' => self::ORDER_CANCELLATION_REASON_CONTEXT,
            ]);
            if ($category instanceof Category) {
                return $category;
            }
        }

        // Fallback: reason id is already scoped by the cancel-reasons list; accept by id+context.
        $category = $repository->findOneBy([
            'id' => $normalizedReasonId,
            'context' => self::ORDER_CANCELLATION_REASON_CONTEXT,
        ]);

        return $category instanceof Category ? $category : null;
    }

    private function applyCancellationAudit(
        Order $order,
        ?Category $cancellationReason = null,
        ?People $canceledBy = null,
    ): void {
        $order->setCancellationReason($cancellationReason);
        $order->setCanceledBy($canceledBy);
    }

    public function getCapabilities(Order $order): array
    {
        $realStatus = $this->normalizeStatusValue($order->getStatus()?->getRealStatus());
        $status = $this->normalizeStatusValue($order->getStatus()?->getStatus());
        $terminal = in_array($realStatus, ['canceled', 'cancelled', 'closed'], true);

        if ($this->isDeliveryOrder($order)) {
            $awaitingAcceptance = in_array($status, [
                'aguardando aceite',
                'awaiting acceptance',
                'waiting acceptance',
                'pending acceptance',
                'acceptance pending',
                'pending',
                'pendente',
            ], true);
            $accepted = in_array($status, ['aceito', 'accepted', 'accept'], true) || $realStatus === 'accepted';
            $inRoute = in_array($status, [
                'way',
                'away',
                'en route',
                'in route',
                'on route',
                'picked up',
                'pickup',
                'dispatch',
                'delivery',
                'delivering',
            ], true);
            $deliveryTerminal = $terminal || in_array($status, [
                'delivered',
                'entregue',
                'finished',
                'finalizado',
                'canceled',
                'cancelado',
                'cancelled',
                'cancel',
            ], true);

            return [
                'realStatus' => $realStatus,
                'can_cancel' => $awaitingAcceptance && !$deliveryTerminal,
                'can_confirm' => $awaitingAcceptance && !$deliveryTerminal,
                'can_ready' => false,
                'can_delivered' => ($accepted || $inRoute) && !$deliveryTerminal,
                'is_delivering' => $accepted || $inRoute,
                'is_terminal' => $deliveryTerminal,
            ];
        }

        $isInitialPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'open'
            && in_array($status, ['', 'open', 'paid', 'confirmed'], true);
        $isPreparingPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'open'
            && $status === 'preparing';
        $isReadyPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'pending'
            && $status === 'ready';
        $isDeliveringPosOrShopState = $this->isPosOrShopOrder($order)
            && $realStatus === 'pending'
            && $status === 'way';

        $canConfirm = !$terminal && (!$this->isPosOrShopOrder($order) || $isInitialPosOrShopState);
        $canReady = !$terminal && (!$this->isPosOrShopOrder($order) || $isPreparingPosOrShopState);
        $canDelivered = !$terminal && (!$this->isPosOrShopOrder($order) || $isReadyPosOrShopState || $isDeliveringPosOrShopState);

        return [
            'realStatus' => $realStatus,
            'can_cancel' => !$terminal,
            'can_confirm' => $canConfirm,
            'can_ready' => $canReady,
            'can_delivered' => $canDelivered,
            'is_delivering' => $isDeliveringPosOrShopState,
            'is_terminal' => $terminal,
        ];
    }

    public function getCancelReasons(Order $order, ?People $company = null): array
    {
        if ($this->isIfoodOrder($order) && $this->iFoodService instanceof iFoodService) {
            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'data' => [
                    'reasons' => $this->iFoodService->getIfoodCancellationReasons($order),
                ],
            ];
        }

        if ($this->isFood99Order($order) && $this->food99Service instanceof Food99Service) {
            return $this->food99Service->getOrderCancelReasons($order);
        }

        $reasonCompany = $this->getCancellationReasonCompany($order, $company);
        if (!$reasonCompany instanceof People) {
            return ['errno' => 0, 'errmsg' => 'ok', 'data' => ['reasons' => []]];
        }

        $categories = $this->entityManager->getRepository(Category::class)->findBy(
            [
                'company' => $reasonCompany,
                'context' => self::ORDER_CANCELLATION_REASON_CONTEXT,
            ],
            ['name' => 'ASC']
        );

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'reasons' => array_map(
                    static fn (Category $category): array => [
                        'id' => $category->getId(),
                        'reason_id' => $category->getId(),
                        'description' => $category->getName(),
                        'label' => $category->getName(),
                        'requires_description' => false,
                    ],
                    $categories
                ),
            ],
        ];
    }
}
