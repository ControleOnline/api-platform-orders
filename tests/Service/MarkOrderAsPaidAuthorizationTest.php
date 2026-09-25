<?php

namespace ControleOnline\Orders\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\MarkOrderAsPaidService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class MarkOrderAsPaidAuthorizationTest extends TestCase
{
    public function testServiceRefusesTenantCrossLinkReferences(): void
    {
        $service = (new \ReflectionClass(MarkOrderAsPaidService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MarkOrderAsPaidService::class, 'assertSameCompany');
        $method->setAccessible(true);

        $provider = (new People())->setId(10);
        $other = (new People())->setId(20);

        $this->expectException(AccessDeniedHttpException::class);
        $method->invoke($service, $other, $provider, 'Referencia invalida.');
    }

    public function testServiceRefusesPublicResourceWithoutCompany(): void
    {
        $service = (new \ReflectionClass(MarkOrderAsPaidService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(MarkOrderAsPaidService::class, 'assertSameCompany');
        $method->setAccessible(true);

        $provider = (new People())->setId(10);
        $this->expectException(BadRequestHttpException::class);
        $method->invoke($service, null, $provider, 'Referencia invalida.');
    }
}
