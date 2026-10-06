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
trait OrderAccessors1
{
    public function resetId()
    {
        $this->id = null;
        $this->orderDate = new DateTime('now');
        $this->alterDate = new DateTime('now');
    }

    public function getId()
    {
        return $this->id;
    }

    public function setStatus(Status $status = null)
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setClient(People $client = null)
    {
        $this->client = $client;
        return $this;
    }

    public function getClient()
    {
        return $this->client;
    }

    public function setContract(?Contract $contract = null)
    {
        $this->contract = $contract;
        return $this;
    }

    public function getContract(): ?Contract
    {
        return $this->contract;
    }

    public function setProvider(People $provider = null)
    {
        $this->provider = $provider;
        return $this;
    }

    public function getProvider()
    {
        return $this->provider;
    }

    public function setPrice($price)
    {
        $this->price = $price;
        return $this;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function setAddressOrigin(Address $address_origin = null)
    {
        $this->addressOrigin = $address_origin;
        return $this;
    }

    public function getAddressOrigin()
    {
        return $this->addressOrigin;
    }

    public function setAddressDestination(Address $address_destination = null)
    {
        $this->addressDestination = $address_destination;
        return $this;
    }

    public function getAddressDestination()
    {
        return $this->addressDestination;
    }

    public function getRetrieveContact()
    {
        return $this->retrieveContact;
    }

    public function setRetrieveContact(People $retrieve_contact = null)
    {
        $this->retrieveContact = $retrieve_contact;
        return $this;
    }

    public function getDeliveryContact()
    {
        return $this->deliveryContact;
    }

    public function setDeliveryContact(People $delivery_contact = null)
    {
        $this->deliveryContact = $delivery_contact;
        return $this;
    }

    public function getDeliveryPeople(): ?People
    {
        return $this->deliveryPeople;
    }

    public function setDeliveryPeople(People $delivery_people = null)
    {
        $this->deliveryPeople = $delivery_people;
        return $this;
    }

    public function setPayer(People $payer = null)
    {
        $this->payer = $payer;
        return $this;
    }

    public function getPayer()
    {
        return $this->payer;
    }

    public function setComments($comments)
    {
        $this->comments = $comments;
        return $this;
    }

    public function getComments()
    {
        return $this->comments;
    }

    public function getOtherInformations($decode = false)
    {
        return $decode ? (object) json_decode((is_array($this->otherInformations) ? json_encode($this->otherInformations) : $this->otherInformations)) : $this->otherInformations;
    }

    public function addOtherInformations($key, $value)
    {
        $otherInformations = $this->getOtherInformations(true);
        if (!is_object($otherInformations)) {
            $otherInformations = (object) [];
        }
        $otherInformations->$key = $value;
        return $this->setOtherInformations($otherInformations);
    }

    public function setOtherInformations($otherInformations)
    {
        // Column type is Doctrine "json": store PHP array/object, not a pre-encoded string
        // (json_encode here would double-encode and break subsequent reads/writes).
        if (is_string($otherInformations)) {
            $decoded = json_decode($otherInformations, true);
            $this->otherInformations = $decoded !== null ? $decoded : $otherInformations;
            return $this;
        }

        if (is_object($otherInformations)) {
            $this->otherInformations = json_decode(json_encode($otherInformations), true) ?: [];
            return $this;
        }

        $this->otherInformations = $otherInformations;
        return $this;
    }

    public function getCancellationReason(): ?Category
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?Category $cancellationReason): self
    {
        $this->cancellationReason = $cancellationReason;
        return $this;
    }

    public function getCanceledBy(): ?People
    {
        return $this->canceledBy;
    }

    public function setCanceledBy(?People $canceledBy): self
    {
        $this->canceledBy = $canceledBy;
        return $this;
    }

    public function getOrderDate()
    {
        return $this->orderDate;
    }

    public function setOrderDate(DateTimeInterface $order_date = null): self
    {
        $this->orderDate = $order_date;
        return $this;
    }

    public function setAlterDate(DateTimeInterface $alter_date = null): self
    {
        $this->alterDate = $alter_date;
        return $this;
    }

    public function getAlterDate(): ?DateTimeInterface
    {
        return $this->alterDate;
    }

    public function addAInvoiceTax(OrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax[] = $invoice_tax;
        return $this;
    }

    public function removeInvoiceTax(OrderInvoiceTax $invoice_tax)
    {
        $this->invoiceTax->removeElement($invoice_tax);
    }

    public function getInvoiceTax()
    {
        return $this->invoiceTax;
    }

    public function getClientInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 55) {
                return $invoice;
            }
        }
    }

    public function getCarrierInvoiceTax()
    {
        foreach ($this->getInvoiceTax() as $invoice) {
            if ($invoice->getInvoiceType() == 57) {
                return $invoice->getInvoiceTax();
            }
        }
    }

    public function addInvoice(OrderInvoice $invoice)
    {
        $this->invoice[] = $invoice;
        return $this;
    }

    public function removeInvoice(OrderInvoice $invoice)
    {
        $this->invoice->removeElement($invoice);
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function addOrderFile(OrderFile $orderFile): self
    {
        $this->orderFiles[] = $orderFile;
        return $this;
    }

    public function removeOrderFile(OrderFile $orderFile): self
    {
        $this->orderFiles->removeElement($orderFile);
        return $this;
    }

    public function getOrderFiles()
    {
        return $this->orderFiles;
    }

    public function getNotified()
    {
        return $this->notified;
    }

    public function setNotified($notified)
    {
        $this->notified = $notified ? 1 : 0;
        return $this;
    }

    public function setOrderType($order_type)
    {
        $this->orderType = $order_type;
        return $this;
    }

    public function getOrderType()
    {
        return $this->orderType;
    }

    public function setApp($app)
    {
        $this->app = $app;
        return $this;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function setChannel(?string $channel): self
    {
        $normalizedChannel = strtolower(trim((string) $channel));
        $this->channel = $normalizedChannel !== '' ? $normalizedChannel : null;

        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setFulfillmentType(?string $fulfillmentType): self
    {
        $normalizedType = strtolower(trim((string) $fulfillmentType));
        $this->fulfillmentType = $normalizedType !== '' ? $normalizedType : null;

        return $this;
    }

    public function getFulfillmentType(): ?string
    {
        return $this->fulfillmentType;
    }
}
