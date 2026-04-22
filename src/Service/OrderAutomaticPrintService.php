<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProductQueue;

class OrderAutomaticPrintService
{
    protected static $logger;

    public function __construct(
        private OrderPrintService $orderPrintService,
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('order-auto-print');
    }

    public function dispatchCompletedOrderPrints(
        Order $order,
        array $context = []
    ): array {
        $conferencePrinted = 0;
        $preparationPrinted = 0;

        try {
            $conferencePrinted = $this->orderPrintService->printConferenceCopies(
                $order,
                [],
                [
                    'automaticOrderPrint' => true,
                ]
            );
        } catch (\Throwable $exception) {
            self::$logger?->error(
                'Automatic conference print failed',
                array_merge($this->buildOrderContext($order), $context, [
                    'message' => $exception->getMessage(),
                ])
            );
        }

        foreach ($this->resolveOrderQueueEntries($order) as $orderProductQueue) {
            try {
                $preparationPrinted += $this->orderPrintService->autoPrintOrderProductQueueEntry(
                    $orderProductQueue
                );
            } catch (\Throwable $exception) {
                self::$logger?->error(
                    'Automatic preparation print failed',
                    array_merge($this->buildOrderContext($order), $context, [
                        'orderProductQueue' => $orderProductQueue->getId(),
                        'message' => $exception->getMessage(),
                    ])
                );
            }
        }

        self::$logger?->info(
            'Completed order automatic print dispatch finished',
            array_merge($this->buildOrderContext($order), $context, [
                'conferencePrinted' => $conferencePrinted,
                'preparationPrinted' => $preparationPrinted,
            ])
        );

        return [
            'conferencePrinted' => $conferencePrinted,
            'preparationPrinted' => $preparationPrinted,
        ];
    }

    /**
     * @return OrderProductQueue[]
     */
    private function resolveOrderQueueEntries(Order $order): array
    {
        $queueEntries = [];

        foreach ($order->getOrderProducts() as $orderProduct) {
            foreach ($orderProduct->getOrderProductQueues() as $orderProductQueue) {
                if (!$orderProductQueue instanceof OrderProductQueue) {
                    continue;
                }

                $queueEntryId = (int) ($orderProductQueue->getId() ?? 0);
                if ($queueEntryId <= 0) {
                    continue;
                }

                $queueEntries[$queueEntryId] = $orderProductQueue;
            }
        }

        ksort($queueEntries);

        return array_values($queueEntries);
    }

    private function buildOrderContext(Order $order): array
    {
        return [
            'logEntity' => $order,
            'order' => $order->getId(),
            'provider' => $order->getProvider()?->getId(),
            'app' => $order->getApp(),
        ];
    }
}
