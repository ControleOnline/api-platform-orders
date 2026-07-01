<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\OrderProduct;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MarkOrderProductCheckedAction
{
    public function __construct(
        private EntityManagerInterface $manager,
        private StatusService $statusService,
        private HydratorService $hydratorService,
    ) {
    }

    public function __invoke(int $id): JsonResponse
    {
        $orderProduct = $this->manager->getRepository(OrderProduct::class)->find($id);
        if (!$orderProduct instanceof OrderProduct) {
            return new JsonResponse(
                ['error' => 'Order product not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $checkedStatus = $this->statusService->discoveryStatus(
            'conferido',
            'conferido',
            'order_product'
        );

        if ($orderProduct->getStatus()?->getId() !== $checkedStatus->getId()) {
            $orderProduct->setStatus($checkedStatus);
            $this->manager->persist($orderProduct);
            $this->manager->flush();
        }

        return new JsonResponse(
            $this->hydratorService->item(
                OrderProduct::class,
                $id,
                'order_product:read',
            ),
            Response::HTTP_OK,
        );
    }
}
