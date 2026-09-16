<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderQrConsume;
use ControleOnline\Entity\OrderQrContext;
use ControleOnline\Entity\People;
use ControleOnline\Repository\OrderQrConsumeRepository;
use ControleOnline\Repository\OrderQrContextRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Secure QR context emission, resolution and consumption for Shop.
 *
 * Tokens are opaque random strings; only SHA-256 hashes are persisted.
 * Client-supplied company/local/mainOrder are never trusted as authority.
 */
class OrderQrService
{
    public const DEFAULT_SESSION_TTL_SECONDS = 86400; // 24h
    public const PUBLIC_TOKEN_BYTES = 32;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderQrContextRepository $contextRepository,
        private OrderQrConsumeRepository $consumeRepository,
        private OrderService $orderService,
        private StatusService $statusService,
    ) {
    }

    /**
     * Emit a new QR context. Returns [context, rawToken].
     * Raw token is shown only once to the authorized issuer.
     *
     * @param array{
     *   purpose?: string,
     *   linkType?: string,
     *   provider: People,
     *   externalCode?: string|null,
     *   rootOrder?: Order|null,
     *   expiresInSeconds?: int|null,
     *   issuedBy?: People|null,
     *   keyVersion?: int
     * } $input
     * @return array{0: OrderQrContext, 1: string}
     */
    public function emit(array $input): array
    {
        $purpose = strtolower(trim((string) ($input['purpose'] ?? OrderQrContext::PURPOSE_SESSION)));
        $linkType = strtolower(trim((string) ($input['linkType'] ?? OrderQrContext::LINK_NONE)));

        if (!in_array($purpose, OrderQrContext::PURPOSES, true)) {
            throw new BadRequestHttpException('Invalid purpose. Allowed: session, permanent.');
        }
        if (!in_array($linkType, OrderQrContext::LINK_TYPES, true)) {
            throw new BadRequestHttpException('Invalid linkType. Allowed: none, table, tab.');
        }

        $provider = $input['provider'] ?? null;
        if (!$provider instanceof People) {
            throw new BadRequestHttpException('provider is required.');
        }

        $rootOrder = $input['rootOrder'] ?? null;
        $externalCode = isset($input['externalCode']) ? trim((string) $input['externalCode']) : null;
        if ($externalCode === '') {
            $externalCode = null;
        }

        if ($purpose === OrderQrContext::PURPOSE_SESSION) {
            if (!$rootOrder instanceof Order) {
                throw new BadRequestHttpException('Session QR requires an open rootOrder.');
            }
            $this->assertRootBelongsToProvider($rootOrder, $provider);
            if (!$this->contextRepository->isOrderOpenRoot($rootOrder)) {
                throw new BadRequestHttpException('Session QR requires the root order to be open.');
            }
            if ($linkType === OrderQrContext::LINK_NONE) {
                // session + none is allowed (bind to a specific independent root if desired)
            }
        }

        if (in_array($linkType, [OrderQrContext::LINK_TABLE, OrderQrContext::LINK_TAB], true) && $externalCode === null && $rootOrder === null) {
            throw new BadRequestHttpException('table/tab link requires externalCode or rootOrder.');
        }

        if ($rootOrder instanceof Order && $externalCode === null) {
            $externalCode = $rootOrder->getExternalCode();
        }

        $rawToken = $this->generateRawToken();
        $tokenHash = $this->hashToken($rawToken);

        $expiresIn = $input['expiresInSeconds'] ?? null;
        if ($expiresIn === null) {
            $expiresIn = $purpose === OrderQrContext::PURPOSE_SESSION
                ? self::DEFAULT_SESSION_TTL_SECONDS
                : null; // permanent: no forced expiry (rotation via revoke)
        }

        $context = new OrderQrContext();
        $context->setTokenHash($tokenHash);
        $context->setPurpose($purpose);
        $context->setLinkType($linkType);
        $context->setProvider($provider);
        $context->setExternalCode($externalCode);
        $context->setRootOrder($rootOrder instanceof Order ? $rootOrder : null);
        $context->setIssuedBy(($input['issuedBy'] ?? null) instanceof People ? $input['issuedBy'] : null);
        $context->setKeyVersion((int) ($input['keyVersion'] ?? 1));
        $context->setStatus(OrderQrContext::STATUS_ACTIVE);

        if ($expiresIn !== null && (int) $expiresIn > 0) {
            $context->setExpiresAt(new \DateTime(sprintf('+%d seconds', (int) $expiresIn)));
        }

        $this->entityManager->persist($context);
        $this->entityManager->flush();

        return [$context, $rawToken];
    }

    /**
     * Resolve public token to minimal public context (no internal IDs).
     *
     * @return array<string, mixed>
     */
    public function resolve(string $rawToken): array
    {
        $context = $this->loadUsableContext($rawToken);

        return $this->toPublicContext($context);
    }

    /**
     * Consume token: create or reuse cart/round idempotently.
     *
     * @param array{token: string, idempotencyKey: string} $input
     * @return array{context: array<string, mixed>, order: array<string, mixed>, rootOrder: array<string, mixed>|null, reused: bool}
     */
    public function consume(array $input): array
    {
        $rawToken = trim((string) ($input['token'] ?? ''));
        $idempotencyKey = trim((string) ($input['idempotencyKey'] ?? ''));

        if ($rawToken === '') {
            throw new BadRequestHttpException('token is required.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new BadRequestHttpException('idempotencyKey is required (max 128 chars).');
        }

        // Idempotent replay: return existing consume
        $existing = $this->consumeRepository->findOneByIdempotencyKey($idempotencyKey);
        if ($existing instanceof OrderQrConsume) {
            $order = $existing->getOrder();
            $root = $existing->getRootOrder();
            $ctx = $existing->getQrContext();

            return [
                'context' => $ctx instanceof OrderQrContext ? $this->toPublicContext($ctx) : [],
                'order' => $order instanceof Order ? $this->toPublicOrder($order) : [],
                'rootOrder' => $root instanceof Order ? $this->toPublicOrder($root) : null,
                'reused' => true,
            ];
        }

        $context = $this->loadUsableContext($rawToken);
        $provider = $context->getProvider();
        if (!$provider instanceof People) {
            throw new BadRequestHttpException('QR context has no provider.');
        }

        $rootOrder = null;
        $childOrder = null;

        if ($context->getLinkType() === OrderQrContext::LINK_NONE) {
            $childOrder = $this->createIndependentCart($provider, $context);
            $rootOrder = null;
        } else {
            $rootOrder = $this->resolveOrCreateRoot($context, $provider);
            $childOrder = $this->createOrReuseRound($provider, $context, $rootOrder, $idempotencyKey);
        }

        $consume = new OrderQrConsume();
        $consume->setQrContext($context);
        $consume->setOrder($childOrder);
        $consume->setRootOrder($rootOrder);
        $consume->setIdempotencyKey($idempotencyKey);

        $context->incrementConsumeCount();
        $context->setLastIdempotencyKey($idempotencyKey);

        try {
            $this->entityManager->persist($consume);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent same idempotency key: reload winner
            $this->entityManager->clear();
            $existing = $this->consumeRepository->findOneByIdempotencyKey($idempotencyKey);
            if ($existing instanceof OrderQrConsume) {
                $order = $existing->getOrder();
                $root = $existing->getRootOrder();
                $ctx = $existing->getQrContext();

                return [
                    'context' => $ctx instanceof OrderQrContext ? $this->toPublicContext($ctx) : [],
                    'order' => $order instanceof Order ? $this->toPublicOrder($order) : [],
                    'rootOrder' => $root instanceof Order ? $this->toPublicOrder($root) : null,
                    'reused' => true,
                ];
            }
            throw $e;
        }

        return [
            'context' => $this->toPublicContext($context),
            'order' => $this->toPublicOrder($childOrder),
            'rootOrder' => $rootOrder instanceof Order ? $this->toPublicOrder($rootOrder) : null,
            'reused' => false,
        ];
    }

    public function revoke(OrderQrContext $context): OrderQrContext
    {
        $context->revoke();
        $this->entityManager->flush();
        return $context;
    }

    public function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::PUBLIC_TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function loadUsableContext(string $rawToken): OrderQrContext
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 16) {
            // Uniform not-found to avoid enumeration
            throw new NotFoundHttpException('QR context not found.');
        }

        $hash = $this->hashToken($rawToken);
        $context = $this->contextRepository->findOneByTokenHash($hash);
        if (!$context instanceof OrderQrContext) {
            throw new NotFoundHttpException('QR context not found.');
        }

        if ($context->getStatus() === OrderQrContext::STATUS_REVOKED) {
            throw new AccessDeniedHttpException('QR context revoked.');
        }

        if ($context->isExpired()) {
            if ($context->getStatus() !== OrderQrContext::STATUS_EXPIRED) {
                $context->setStatus(OrderQrContext::STATUS_EXPIRED);
                $context->touch();
                $this->entityManager->flush();
            }
            throw new AccessDeniedHttpException('QR context expired.');
        }

        if (!$context->isUsable()) {
            throw new AccessDeniedHttpException('QR context not usable.');
        }

        return $context;
    }

    private function resolveOrCreateRoot(OrderQrContext $context, People $provider): Order
    {
        // Session QR: must use the bound root and reject if closed
        if ($context->getPurpose() === OrderQrContext::PURPOSE_SESSION) {
            $root = $context->getRootOrder();
            if (!$root instanceof Order) {
                throw new BadRequestHttpException('Session QR has no bound root order.');
            }
            $this->assertRootBelongsToProvider($root, $provider);
            if (!$this->contextRepository->isOrderOpenRoot($root)) {
                throw new AccessDeniedHttpException('Session QR root order is closed.');
            }
            return $root;
        }

        // Permanent QR: find open root by externalCode or create one
        $externalCode = $context->getExternalCode();
        if ($externalCode === null || $externalCode === '') {
            throw new BadRequestHttpException('Permanent table/tab QR requires externalCode.');
        }

        $existing = $this->contextRepository->findOpenRootByProviderAndExternalCode($provider, $externalCode);
        if ($existing instanceof Order) {
            return $existing;
        }

        return $this->createRootOrder($provider, $externalCode, $context);
    }

    private function createRootOrder(People $provider, string $externalCode, OrderQrContext $context): Order
    {
        $order = new Order();
        $order->setProvider($provider);
        $order->setExternalCode($externalCode);
        $order->setApp('SHOP');
        if (method_exists($order, 'setChannel')) {
            $order->setChannel('shop');
        }
        // An independent cart intentionally has no main order. The entity
        // setter accepts only concrete Order instances, so leave it unset.

        // Prefer starting as cart/open via OrderService when available
        if (method_exists($this->orderService, 'shouldStartAsCart')) {
            // status assignment is handled by callers that know Status entity; set minimal
        }

        $status = null;
        if (method_exists($this->statusService, 'getStatus')) {
            try {
                $status = $this->statusService->getStatus('order', 'open');
            } catch (\Throwable $e) {
                try {
                    $status = $this->statusService->getStatus('order', 'cart');
                } catch (\Throwable $e2) {
                    $status = null;
                }
            }
        }
        if ($status !== null && method_exists($order, 'setStatus')) {
            $order->setStatus($status);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createIndependentCart(People $provider, OrderQrContext $context): Order
    {
        $order = new Order();
        $order->setProvider($provider);
        $order->setApp('SHOP');
        if (method_exists($order, 'setChannel')) {
            $order->setChannel('shop');
        }
        // unique external code for anonymous cart
        $order->setExternalCode('shop-qr:' . bin2hex(random_bytes(8)));

        $status = $this->tryResolveStatus(['cart', 'open', 'draft']);
        if ($status !== null) {
            $order->setStatus($status);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createOrReuseRound(
        People $provider,
        OrderQrContext $context,
        Order $rootOrder,
        string $idempotencyKey
    ): Order {
        // Always create a new child cart linked to root; idempotency is on OrderQrConsume.
        $order = new Order();
        $order->setProvider($provider);
        $order->setApp('SHOP');
        if (method_exists($order, 'setChannel')) {
            $order->setChannel('shop');
        }
        $order->setMainOrder($rootOrder);
        $order->setExternalCode('shop-round:' . substr(hash('sha256', $idempotencyKey), 0, 16));

        $status = $this->tryResolveStatus(['cart', 'open', 'draft']);
        if ($status !== null) {
            $order->setStatus($status);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function tryResolveStatus(array $candidates): mixed
    {
        if (!method_exists($this->statusService, 'getStatus')) {
            return null;
        }
        foreach ($candidates as $name) {
            try {
                $status = $this->statusService->getStatus('order', $name);
                if ($status !== null) {
                    return $status;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return null;
    }

    private function assertRootBelongsToProvider(Order $root, People $provider): void
    {
        $rootProvider = $root->getProvider();
        if (!$rootProvider instanceof People || (int) $rootProvider->getId() !== (int) $provider->getId()) {
            throw new AccessDeniedHttpException('Root order does not belong to provider.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicContext(OrderQrContext $context): array
    {
        return [
            'purpose' => $context->getPurpose(),
            'linkType' => $context->getLinkType(),
            'externalCode' => $context->getExternalCode(),
            'status' => $context->getStatus(),
            'expiresAt' => $context->getExpiresAt()?->format(DATE_ATOM),
            'keyVersion' => $context->getKeyVersion(),
            // Never expose internal ids, token hash, or root order id
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicOrder(Order $order): array
    {
        $payload = [
            'id' => $order->getId(),
            'externalCode' => $order->getExternalCode(),
            'app' => method_exists($order, 'getApp') ? $order->getApp() : null,
        ];
        if (method_exists($order, 'getChannel')) {
            $payload['channel'] = $order->getChannel();
        }
        $main = $order->getMainOrder();
        if ($main instanceof Order) {
            $payload['mainOrder'] = [
                'id' => $main->getId(),
                'externalCode' => $main->getExternalCode(),
            ];
        } else {
            $payload['mainOrder'] = null;
        }
        return $payload;
    }
}
