<?php
namespace ControleOnline\Entity\Traits;

use Symfony\Component\Serializer\Attribute\{Groups, SerializedName};

/** Compact links into the flat order collection, without recursively serializing it. */
trait OrderProductDetailsHierarchy
{
    #[Groups(['order_details:read', 'order_cart_hierarchy:read'])]
    #[SerializedName('hierarchyComplete')]
    public function getDetailsHierarchyComplete(): bool
    {
        // JSON-LD can omit null links; consumers must still know this is authoritative.
        return true;
    }

    #[Groups(['order_details:read', 'order_cart_hierarchy:read'])]
    #[SerializedName('orderProduct')]
    public function getDetailsOrderProduct(): ?string
    {
        $id = $this->getOrderProduct()?->getId();
        return $id ? '/order_products/' . $id : null;
    }

    #[Groups(['order_details:read', 'order_cart_hierarchy:read'])]
    #[SerializedName('parentProduct')]
    public function getDetailsParentProduct(): ?string
    {
        $id = $this->getParentProduct()?->getId();
        return $id ? '/products/' . $id : null;
    }

    #[Groups(['order_details:read', 'order_cart_hierarchy:read'])]
    #[SerializedName('showInParentQueue')]
    public function getDetailsShowInParentQueue(): bool
    {
        return $this->getShowInParentQueue();
    }

    #[Groups(['order_details:read', 'order_cart_hierarchy:read'])]
    #[SerializedName('productGroup')]
    public function getDetailsProductGroup(): ?array
    {
        $group = $this->getProductGroup();
        if (!$group) return null;
        // Keep the existing presentation fields, without loading catalog parent mappings.
        return [
            'id' => $group->getId(),
            '@id' => '/product_groups/' . $group->getId(),
            'productGroup' => $group->getProductGroup(),
            'priceCalculation' => $group->getPriceCalculation(),
            'required' => $group->getRequired(),
            'minimum' => $group->getMinimum(),
            'maximum' => $group->getMaximum(),
            'groupOrder' => $group->getGroupOrder(),
            'showInDisplay' => $group->getShowInDisplay(),
            'showInPrint' => $group->getShowInPrint(),
            'showUnitQuantity' => $group->getShowUnitQuantity(),
            'customizationType' => $group->getCustomizationType(),
        ];
    }
}
