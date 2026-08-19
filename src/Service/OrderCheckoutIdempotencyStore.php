<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Order;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Persists checkout idempotency keys on Order.otherInformations.
 * Same key + same payload replays the stored result; same key + different payload conflicts.
 */
class OrderCheckoutIdempotencyStore
{
    private const STORE_KEY = 'checkout_idempotency';
    private const TTL_SECONDS = 86400;

    public function normalizeKey(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $key = trim((string) $value);
        if ($key === '') {
            return null;
        }

        return strlen($key) > 128 ? substr($key, 0, 128) : $key;
    }

    public function fingerprint(array $payload): string
    {
        $relevant = [
            'payment' => $payload['payment'] ?? null,
            'confirm' => array_key_exists('confirm', $payload) ? (bool) $payload['confirm'] : true,
            'mode' => is_scalar($payload['mode'] ?? null) ? trim((string) $payload['mode']) : '',
        ];

        return hash('sha256', (string) json_encode($relevant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function find(Order $order, string $key, array $payload): ?array
    {
        $info = $order->getOtherInformations(true);
        if (!is_object($info) || !isset($info->{self::STORE_KEY})) {
            return null;
        }

        $store = (object) $info->{self::STORE_KEY};
        if (!isset($store->{$key})) {
            return null;
        }

        $entry = (object) $store->{$key};
        $createdAt = isset($entry->createdAt) ? strtotime((string) $entry->createdAt) : false;
        if ($createdAt !== false && (time() - $createdAt) > self::TTL_SECONDS) {
            return null;
        }

        if (isset($entry->fingerprint) && (string) $entry->fingerprint !== $this->fingerprint($payload)) {
            throw new ConflictHttpException(
                'Idempotency key was already used with a different checkout payload.'
            );
        }

        if (!isset($entry->result)) {
            return null;
        }

        $result = (array) $entry->result;
        $result['idempotentReplay'] = true;

        return $result;
    }

    public function store(Order $order, string $key, array $payload, array $result): void
    {
        $info = $order->getOtherInformations(true);
        if (!is_object($info)) {
            $info = (object) [];
        }

        $store = isset($info->{self::STORE_KEY}) ? (array) $info->{self::STORE_KEY} : [];
        $store[$key] = [
            'fingerprint' => $this->fingerprint($payload),
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'result' => [
                'outcome' => $result['outcome'] ?? null,
                'errno' => $result['errno'] ?? null,
                'errmsg' => $result['errmsg'] ?? null,
                'order' => $result['order'] ?? null,
                'invoice' => $result['invoice'] ?? null,
                'confirmation' => $result['confirmation'] ?? null,
            ],
        ];

        $info->{self::STORE_KEY} = $store;
        $order->setOtherInformations($info);
    }
}
