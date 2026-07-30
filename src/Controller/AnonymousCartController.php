<?php

namespace ControleOnline\Controller;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\OrderProductService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AnonymousCartController
{
    private const EXTERNAL_CODE_PREFIX = 'anonymous-cart:';

    public function __construct(
        private EntityManagerInterface $manager,
        private HydratorService $hydratorService,
        private OrderProductService $orderProductService,
        private OrderService $orderService,
        private StatusService $statusService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodePayload($request);

            if ($request->query->has('client') || array_key_exists('client', $payload)) {
                return new JsonResponse(
                    ['error' => 'Carrinho com client deve usar o fluxo autenticado.'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $provider = $this->resolveProvider($request, $payload);
            $externalCode = $this->resolveExternalCode($request, $payload, $request->isMethod('POST'));
            $cart = $this->findOrCreateCart($provider, $externalCode);

            if ($request->isMethod('POST')) {
                $this->persistItem($cart, $payload);
                $this->manager->refresh($cart);
            }

            return new JsonResponse(
                $this->serializeCart($cart),
                Response::HTTP_OK
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        } catch (\Throwable $exception) {
            return new JsonResponse(
                $this->hydratorService->error($exception),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function decodePayload(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : [];
    }

    private function resolveProvider(Request $request, array $payload): People
    {
        $providerId = $payload['provider'] ?? $request->query->get('provider');
        $providerId = $this->normalizeId($providerId);

        if (!$providerId) {
            throw new \InvalidArgumentException('Provider é obrigatório.');
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People) {
            throw new \InvalidArgumentException('Provider inválido.');
        }

        return $provider;
    }

    private function resolveExternalCode(Request $request, array $payload, bool $required): ?string
    {
        $externalCode = trim((string) ($payload['externalCode'] ?? $request->query->get('externalCode') ?? ''));

        if ($externalCode === '' && $required) {
            throw new \InvalidArgumentException('Segredo do carrinho anônimo é obrigatório.');
        }

        return $externalCode === '' ? null : $externalCode;
    }

    private function findOrCreateCart(People $provider, ?string $externalCode): Order
    {
        $status = $this->statusService->discoveryStatus('open', 'open', 'order');
        $receivedExternalCode = $externalCode;
        $externalCode = $externalCode ?: $this->buildExternalCode($provider);
        $repository = $this->manager->getRepository(Order::class);
        $cart = $repository->findOneBy([
            'client' => null,
            'provider' => $provider,
            'status' => $status,
            'app' => 'SHOP',
            'orderType' => OrderService::ORDER_TYPE_CART,
            'externalCode' => $externalCode,
        ]);

        if ($cart instanceof Order) {
            return $cart;
        }

        if ($receivedExternalCode) {
            throw new \InvalidArgumentException('Carrinho anônimo inválido.');
        }

        $cart = new Order();
        $cart->setStatus($status);
        $cart->setClient(null);
        $cart->setProvider($provider);
        $cart->setOrderType(OrderService::ORDER_TYPE_CART);
        $cart->setApp('SHOP');
        $cart->setExternalCode($externalCode);
        $cart->addOtherInformations('anonymous', true);

        $this->manager->persist($cart);
        $this->manager->flush();

        return $cart;
    }

    private function persistItem(Order $cart, array $payload): void
    {
        $productId = $this->normalizeId($payload['product'] ?? null);
        $quantity = max(0, (float) ($payload['quantity'] ?? 0));

        if (!$productId) {
            throw new \InvalidArgumentException('Product é obrigatório.');
        }

        $product = $this->manager->getRepository(Product::class)->find($productId);
        if (!$product instanceof Product) {
            throw new \InvalidArgumentException('Product inválido.');
        }

        $orderProduct = $this->findOrderProduct($cart, $product);

        if ($quantity <= 0) {
            if ($orderProduct instanceof OrderProduct) {
                $this->manager->remove($orderProduct);
                $this->manager->flush();
                $this->orderService->calculateOrderPrice($cart);
            }

            return;
        }

        if ($orderProduct instanceof OrderProduct) {
            $orderProduct->setQuantity($quantity);
            $orderProduct->setTotal((float) $orderProduct->getPrice() * $quantity);
            $this->manager->persist($orderProduct);
            $this->manager->flush();
            $this->orderService->calculateOrderPrice($cart);

            return;
        }

        $this->orderProductService->addOrderProduct(
            $cart,
            $product,
            $quantity,
            (float) $product->getPrice()
        );
        $this->orderService->calculateOrderPrice($cart);
    }

    private function findOrderProduct(Order $cart, Product $product): ?OrderProduct
    {
        return $this->manager->getRepository(OrderProduct::class)->findOneBy([
            'order' => $cart,
            'product' => $product,
            'orderProduct' => null,
            'parentProduct' => null,
            'productGroup' => null,
        ]);
    }

    private function serializeCart(Order $cart): array
    {
        $provider = $cart->getProvider();

        return [
            '@id' => sprintf('/orders/%d', $cart->getId()),
            '@type' => 'Order',
            'id' => $cart->getId(),
            'anonymous' => true,
            'app' => $cart->getApp(),
            'externalCode' => $cart->getExternalCode(),
            'orderType' => $cart->getOrderType(),
            'price' => (float) $cart->getPrice(),
            'provider' => $provider instanceof People ? $this->serializePeople($provider) : null,
            'status' => $this->serializeStatus($cart->getStatus()),
            'orderProducts' => $this->serializeOrderProducts($cart),
        ];
    }

    private function serializeOrderProducts(Order $cart): array
    {
        $items = [];

        foreach ($cart->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct) {
                continue;
            }

            $items[] = [
                '@id' => sprintf('/order_products/%d', $orderProduct->getId()),
                '@type' => 'OrderProduct',
                'id' => $orderProduct->getId(),
                'quantity' => (float) $orderProduct->getQuantity(),
                'price' => (float) $orderProduct->getPrice(),
                'total' => (float) $orderProduct->getTotal(),
                'product' => $this->serializeProduct($orderProduct->getProduct()),
                'productShowcaseItem' => $this->serializeProductShowcaseItem($orderProduct),
                'status' => $this->serializeStatus($orderProduct->getStatus()),
            ];
        }

        return $items;
    }

    private function serializeProduct(?Product $product): ?array
    {
        if (!$product instanceof Product) {
            return null;
        }

        return [
            '@id' => sprintf('/products/%d', $product->getId()),
            '@type' => 'Product',
            'id' => $product->getId(),
            'product' => $product->getProduct(),
            'sku' => $product->getSku(),
            'type' => $product->getType(),
            'price' => (float) $product->getPrice(),
            'description' => $product->getDescription(),
            'productFiles' => $this->serializeProductFiles($product),
        ];
    }

    private function serializeProductFiles(Product $product): array
    {
        $files = [];

        foreach ($product->getProductFiles() as $productFile) {
            $file = $productFile->getFile();
            $files[] = [
                '@id' => sprintf('/product_files/%d', $productFile->getId()),
                '@type' => 'ProductFile',
                'id' => $productFile->getId(),
                'file' => [
                    '@id' => sprintf('/files/%d', $file->getId()),
                    '@type' => 'File',
                    'id' => $file->getId(),
                    'fileType' => $file->getFileType(),
                    'fileName' => $file->getFileName(),
                    'extension' => $file->getExtension(),
                    'context' => $file->getContext(),
                    'public' => $file->getPublic(),
                ],
            ];
        }

        return $files;
    }

    private function serializeProductShowcaseItem(OrderProduct $orderProduct): ?array
    {
        $showcaseItem = $orderProduct->getProductShowcaseItem();
        if (!$showcaseItem) {
            return null;
        }

        return [
            '@id' => sprintf('/product_showcase_items/%d', $showcaseItem->getId()),
            '@type' => 'ProductShowcaseItem',
            'id' => $showcaseItem->getId(),
            'price' => (float) $showcaseItem->getPrice(),
            'externalCode' => $showcaseItem->getExternalCode(),
        ];
    }

    private function serializePeople(People $people): array
    {
        return [
            '@id' => sprintf('/people/%d', $people->getId()),
            '@type' => 'People',
            'id' => $people->getId(),
            'name' => $people->getName(),
            'alias' => $people->getAlias(),
        ];
    }

    private function serializeStatus($status): ?array
    {
        if (!$status) {
            return null;
        }

        return [
            '@id' => sprintf('/statuses/%d', $status->getId()),
            '@type' => 'Status',
            'id' => $status->getId(),
            'status' => $status->getStatus(),
            'realStatus' => $status->getRealStatus(),
            'context' => $status->getContext(),
        ];
    }

    private function buildExternalCode(People $provider): string
    {
        return sprintf(
            '%s%s:%s',
            self::EXTERNAL_CODE_PREFIX,
            $provider->getId(),
            bin2hex(random_bytes(24))
        );
    }

    private function normalizeId(mixed $value): int
    {
        $normalized = preg_replace('/\D+/', '', (string) ($value ?? ''));

        return $normalized ? (int) $normalized : 0;
    }
}
