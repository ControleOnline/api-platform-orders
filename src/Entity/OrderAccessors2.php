<?php

namespace ControleOnline\Entity;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ControleOnline\Controller\AddProductsOrderAction;
use ControleOnline\Controller\AnonymousCartController;
use ControleOnline\Controller\AutoConferencePrintOrderAction;
use ControleOnline\Controller\CreateNFeAction;
use ControleOnline\Controller\DiscoveryCart;
use ControleOnline\Controller\FidelityByIdController;
use ControleOnline\Controller\OrderConferenceController;
use ControleOnline\Controller\PrintOrderAction;
use ControleOnline\Controller\ReplaceProductsOrderAction;
use ControleOnline\Controller\UpdateOrderAction;
use ControleOnline\Attribute\CollectionSummary;
use ControleOnline\Filter\CustomOrFilter;

use ControleOnline\Repository\OrderRepository;
use ControleOnline\State\HydratedReadProvider;
use ControleOnline\Service\OrderReportSummaryResolver;
use ControleOnline\Service\OrderSalesSummaryResolver;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use stdClass;
use Symfony\Component\Validator\Constraints as Assert;

/** Methods shared by the original class; contracts and visibility are unchanged. */
trait OrderAccessors2
{
    public function setPayBeforeProduction(?bool $payBeforeProduction): self
    {
        $this->payBeforeProduction = $payBeforeProduction;

        return $this;
    }

    public function getPayBeforeProduction(): ?bool
    {
        return $this->payBeforeProduction;
    }

    public function isPayBeforeProductionRequired(): bool
    {
        return $this->payBeforeProduction === true;
    }

    public function setOperationalSnapshot(?array $operationalSnapshot): self
    {
        $this->operationalSnapshot = $operationalSnapshot;

        return $this;
    }

    public function getOperationalSnapshot(): ?array
    {
        return $this->operationalSnapshot;
    }

    public function setExternalCode($externalCode)
    {
        $this->externalCode = $externalCode;
        return $this;
    }

    public function getExternalCode()
    {
        return $this->externalCode;
    }

    public function setMainOrder(self $main_order)
    {
        $this->mainOrder = $main_order;
        return $this;
    }

    public function getMainOrder()
    {
        return $this->mainOrder;
    }

    #[SerializedName('mainOrder')]
    #[Groups(['order_details:read'])]
    public function getMainOrderSummary(): ?array
    {
        $mainOrder = $this->getMainOrder();
        if (!$mainOrder instanceof self) {
            return null;
        }

        $mainOrderId = $mainOrder->getId();
        if (!$mainOrderId) {
            return null;
        }

        return [
            'id' => $mainOrderId,
            'externalCode' => $mainOrder->getExternalCode(),
        ];
    }

    public function getChargeCapability(): ?array
    {
        return $this->chargeCapability;
    }

    public function setChargeCapability(?array $capability): self
    {
        $this->chargeCapability = $capability;
        return $this;
    }

    public function setMainOrderId($mainOrderId)
    {
        $this->mainOrderId = $mainOrderId;
        return $this;
    }

    public function getMainOrderId()
    {
        return $this->mainOrderId;
    }

    public function getInvoiceByStatus(array $status)
    {
        foreach ($this->getInvoice() as $purchasingOrderInvoice) {
            $invoice = $purchasingOrderInvoice->getInvoice();
            if (in_array($invoice->getStatus()->getStatus(), $status)) {
                return $invoice;
            }
        }
    }

    public function canAccess($currentUser): bool
    {
        if (($provider = $this->getProvider()) === null) {
            return false;
        }

        return $currentUser->getPeople()->getLink()->exists(
            fn($key, $element) => $element->getCompany() === $provider
        );
    }

    public function justOpened(): bool
    {
        return $this->getStatus()?->getStatus() === 'open';
    }

    public function getOneInvoice()
    {
        return (($invoiceOrders = $this->getInvoice()->first()) === false) ?
            null : $invoiceOrders->getInvoice();
    }

    public function addTask(Task $task)
    {
        $this->task[] = $task;
        return $this;
    }

    public function removeTask(Task $task)
    {
        $this->task->removeElement($task);
    }

    public function getTask()
    {
        return $this->task;
    }

    public function isOriginAndDestinationTheSame(): ?bool
    {
        if (($origin = $this->getAddressOrigin()) === null) {
            return null;
        }

        if (($destination = $this->getAddressDestination()) === null) {
            return null;
        }

        $origCity = $origin->getStreet()->getDistrict()->getCity();
        $destCity = $destination->getStreet()->getDistrict()->getCity();

        if ($origCity === $destCity) {
            return true;
        }

        return false;
    }

    public function isOriginAndDestinationTheSameState(): ?bool
    {
        if (($origin = $this->getAddressOrigin()) === null) {
            return null;
        }

        if (($destination = $this->getAddressDestination()) === null) {
            return null;
        }

        $origState = $origin->getStreet()->getDistrict()->getCity()->getState();
        $destState = $destination->getStreet()->getDistrict()->getCity()->getState();

        if ($origState === $destState) {
            return true;
        }

        return false;
    }

    public function getOrderProducts()
    {
        return $this->orderProducts;
    }

    public function addOrderProduct(OrderProduct $orderProduct): self
    {
        $this->orderProducts[] = $orderProduct;
        return $this;
    }

    public function removeOrderProduct(OrderProduct $orderProduct): self
    {
        $this->orderProducts->removeElement($orderProduct);
        return $this;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getDevice()
    {
        return $this->device;
    }

    public function setDevice($device): self
    {
        $this->device = $device;
        return $this;
    }

    public function setExtraData($extraData): self
    {
        $this->extraData = $extraData;
        return $this;
    }

    public function getExtraData()
    {
        return $this->extraData;
    }
}
