<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Device;
use ControleOnline\Entity\Inventory;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\DeviceService;
use ControleOnline\Service\OrderProductQueueService;
use ControleOnline\Service\OrderCommercialContextService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;

#[AllowMockObjectsWithoutExpectations]
class OrderServiceTest extends TestCase
{
    use OrderServiceAssertions1, OrderServiceAssertions2, OrderServiceAssertions3;










    private function buildService(
        string $path,
        ?EntityManagerInterface $entityManager = null,
        ?StatusService $statusService = null,
        ?OrderProductQueueService $orderProductQueueService = null,
        ?IntegrationService $integrationService = null,
        array $defaultCompanies = [101, 202],
        array $courierCompanies = [],
        ?People $currentPeople = null,
        array $query = [],
        ?SerializerInterface $serializer = null,
        array $roleNames = ['ROLE_HUMAN'],
        ?OrderCommercialContextService $commercialContextService = null,
    ): OrderService
    {
        $peopleService = $this->createMock(PeopleService::class);
        $peopleService
            ->method('getMyCompanies')
            ->willReturnCallback(
                function (?array $companyTypes = null) use ($defaultCompanies, $courierCompanies) {
                    if ($companyTypes === ['courier']) {
                        return $courierCompanies;
                    }

                    return $defaultCompanies;
                }
            );
        $peopleService
            ->method('getMyPeople')
            ->willReturn($currentPeople);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path, 'GET', $query));

        $token = $this->createMock(TokenInterface::class);
        $token
            ->method('getRoleNames')
            ->willReturn($roleNames);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        return new OrderService(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $tokenStorage,
            $peopleService,
            $statusService ?? $this->createMock(StatusService::class),
            $orderProductQueueService ?? $this->createMock(OrderProductQueueService::class),
            $this->createMock(WebsocketClient::class),
            $this->createMock(MessageBusInterface::class),
            $serializer ?? $this->createMock(SerializerInterface::class),
            $requestStack,
            $commercialContextService ?? $this->createMock(OrderCommercialContextService::class),
            $integrationService,
        );
    }

    private function setEntityId(string $className, object $entity, int $id): void
    {
        $property = new \ReflectionProperty($className, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function createProductGroup(int $id, string $name): ProductGroup
    {
        $productGroup = (new ProductGroup())
            ->setProductGroup($name)
            ->setShowInDisplay(false);

        $this->setEntityId(ProductGroup::class, $productGroup, $id);

        return $productGroup;
    }

    private function createProductGroupProductLink(
        int $id,
        ProductGroup $productGroup,
        bool $showInParentQueue,
    ): ProductGroupProduct {
        $groupProduct = (new ProductGroupProduct())
            ->setProductGroup($productGroup)
            ->setShowInParentQueue($showInParentQueue);

        $this->setEntityId(ProductGroupProduct::class, $groupProduct, $id);

        return $groupProduct;
    }

    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($object, ...$arguments);
    }

    private function createStatusMock(string $realStatus): Status
    {
        $status = $this->createMock(Status::class);
        $status
            ->method('getRealStatus')
            ->willReturn($realStatus);

        return $status;
    }

    private function createStatusEntity(int $id, string $realStatus, ?string $status = null): Status
    {
        $entity = new Status();
        $entity->setRealStatus($realStatus);
        $entity->setStatus($status ?? $realStatus);
        $entity->setContext('order');
        $this->setEntityId(Status::class, $entity, $id);

        return $entity;
    }
}
