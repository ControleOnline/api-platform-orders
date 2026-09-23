<?php

namespace ControleOnline\EventSubscriber;

use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\PeopleRoleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class DeviceFinancialConfigAuthorizationSubscriber implements EventSubscriberInterface
{
    private const PROTECTED_DEVICE_TYPES = ['PDV', 'MANAGER'];
    private const PROTECTED_CONFIG_KEYS = [
        OrderCommercialContextService::CHARGE_CONFIG_KEY,
        OrderCommercialContextService::PAY_BEFORE_PRODUCTION_CONFIG_KEY,
    ];

    public function __construct(
        private EntityManagerInterface $manager,
        private PeopleRoleService $peopleRoleService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Run after routing/authentication and before API Platform deserializes
        // a managed DeviceConfig, otherwise a hostile PUT could hide its old
        // tenant/type before the authorization decision.
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $method = strtoupper($request->getMethod());
        $path = rtrim($request->getPathInfo(), '/');
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return;
        }

        $isCollection = $path === '/device_configs';
        $isAddConfigs = $path === '/device_configs/add-configs';
        $isItem = preg_match('#^/device_configs/(\d+)$#', $path, $matches) === 1;
        if (!$isCollection && !$isAddConfigs && !$isItem) {
            return;
        }

        $payload = $this->decodePayload($request);
        $current = $isItem
            ? $this->manager->getRepository(DeviceConfig::class)->find((int) $matches[1])
            : null;
        if ($isItem && !$current instanceof DeviceConfig) {
            throw new BadRequestHttpException('Configuracao de device nao encontrada.');
        }

        if (!$this->isProtectedMutation($request, $payload, $current, $isAddConfigs)) {
            return;
        }

        $companies = [];
        if ($current instanceof DeviceConfig) {
            $companies[(int) $current->getPeople()->getId()] = $current->getPeople();
        }

        if (array_key_exists('people', $payload)) {
            $requestedCompany = $this->resolvePeople($payload['people']);
            if (!$requestedCompany instanceof People) {
                throw new BadRequestHttpException('Tenant da configuracao de device nao encontrado.');
            }
            $companies[(int) $requestedCompany->getId()] = $requestedCompany;
        }

        if ($companies === []) {
            throw new BadRequestHttpException('Tenant da configuracao de device e obrigatorio.');
        }

        foreach ($companies as $company) {
            $permissions = $this->peopleRoleService->getCompanyPermissions($company);
            if (array_intersect([...PeopleLink::ADMIN_LINK, 'super'], $permissions) !== []) {
                continue;
            }

            throw new AccessDeniedHttpException(
                'Somente autoridade administrativa do tenant pode alterar contexto ou politica financeira do device.'
            );
        }
    }

    private function isProtectedMutation(
        Request $request,
        array $payload,
        ?DeviceConfig $current,
        bool $isAddConfigs,
    ): bool {
        if ($current instanceof DeviceConfig) {
            if (in_array(strtoupper(trim($current->getType())), self::PROTECTED_DEVICE_TYPES, true)) {
                return true;
            }
            if ($this->containsProtectedConfigKey($current->getConfigs(true))) {
                return true;
            }
        }

        $requestedType = $payload['type']
            ?? ($isAddConfigs
                ? ($request->headers->get('device-type') ?? $request->headers->get('type'))
                : null);
        if (in_array(strtoupper(trim((string) $requestedType)), self::PROTECTED_DEVICE_TYPES, true)) {
            return true;
        }

        return $this->containsProtectedConfigKey($payload['configs'] ?? null);
    }

    private function containsProtectedConfigKey(mixed $configs): bool
    {
        if (is_string($configs)) {
            $configs = json_decode($configs, true);
        }
        if (!is_array($configs)) {
            return false;
        }

        return array_intersect(self::PROTECTED_CONFIG_KEYS, array_keys($configs)) !== [];
    }

    private function resolvePeople(mixed $reference): ?People
    {
        if (is_array($reference)) {
            $reference = $reference['@id'] ?? $reference['id'] ?? null;
        }
        $id = (int) preg_replace('/\D+/', '', (string) $reference);

        return $id > 0
            ? $this->manager->getRepository(People::class)->find($id)
            : null;
    }

    private function decodePayload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return array_replace(
            $request->request->all(),
            is_array($decoded) ? $decoded : [],
        );
    }
}
