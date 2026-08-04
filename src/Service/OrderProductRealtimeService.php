<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;

class OrderProductRealtimeService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private WebsocketClient $websocketClient,
    ) {
    }

    public function notifyChecked(OrderProduct $orderProduct): int
    {
        $order = $orderProduct->getOrder();
        $company = $order?->getProvider();
        if (!$company instanceof People) {
            return 0;
        }

        $companyId = $company->getId();
        $orderId = $order->getId();
        $orderProductId = $orderProduct->getId();
        $baseEvent = [
            'event' => 'order_product.checked',
            'company' => $companyId,
            'companyId' => $companyId,
            'order' => $orderId,
            'orderProduct' => $orderProductId,
            'sentAt' => date(DATE_ATOM),
        ];
        $payload = json_encode([
            array_merge(['store' => 'order_products'], $baseEvent),
            array_merge(['store' => 'orders'], $baseEvent),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return 0;
        }

        $sentDevices = [];
        foreach ($this->manager->getRepository(DeviceConfig::class)->findBy(['people' => $company]) as $deviceConfig) {
            if (!$deviceConfig instanceof DeviceConfig || !$deviceConfig->getDevice()) {
                continue;
            }

            $device = $deviceConfig->getDevice();
            $deviceKey = (string) ($device->getId() ?: spl_object_id($device));
            if (isset($sentDevices[$deviceKey])) {
                continue;
            }

            $sentDevices[$deviceKey] = true;
            $this->websocketClient->push($device, $payload);
        }

        return count($sentDevices);
    }
}
