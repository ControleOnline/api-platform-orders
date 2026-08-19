<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Service\ProposalProductCategoryGuard;
use PHPUnit\Framework\TestCase;

final class ProposalProductCategoryGuardTest extends TestCase
{
    public function testSkipsWhenProposalModelHasNoCategory(): void
    {
        $guard = new ProposalProductCategoryGuard();
        $order = $this->buildOrderWithModelCategory(null);
        $product = $this->buildProductWithCategoryIds([10]);

        $guard->assertOrderProductAllowed($order, $product);

        self::assertTrue(true);
    }

    public function testAllowsProductsInsideProposalModelCategory(): void
    {
        $guard = new ProposalProductCategoryGuard();
        $order = $this->buildOrderWithModelCategory(25);
        $product = $this->buildProductWithCategoryIds([10, 25, 30]);

        $guard->assertOrderProductAllowed($order, $product);

        self::assertTrue(true);
    }

    public function testRejectsProductsOutsideProposalModelCategory(): void
    {
        $guard = new ProposalProductCategoryGuard();
        $order = $this->buildOrderWithModelCategory(25);
        $product = $this->buildProductWithCategoryIds([10, 30]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O produto selecionado nao pertence a categoria do modelo da proposta.');

        $guard->assertOrderProductAllowed($order, $product);
    }

    private function buildOrderWithModelCategory(?int $categoryId): object
    {
        $category = $categoryId === null
            ? null
            : new class ($categoryId) {
                public function __construct(private int $id) {}

                public function getId(): int
                {
                    return $this->id;
                }
            };

        $model = new class ($category) {
            public function __construct(private mixed $category) {}

            public function getCategory(): mixed
            {
                return $this->category;
            }
        };

        $contract = new class ($model) {
            public function __construct(private object $model) {}

            public function getContractModel(): object
            {
                return $this->model;
            }
        };

        return new class ($contract) {
            public function __construct(private object $contract) {}

            public function getContract(): object
            {
                return $this->contract;
            }
        };
    }

    private function buildProductWithCategoryIds(array $categoryIds): object
    {
        $links = array_map(
            static function (int $id) {
                $category = new class ($id) {
                    public function __construct(private int $id) {}

                    public function getId(): int
                    {
                        return $this->id;
                    }
                };

                return new class ($category) {
                    public function __construct(private object $category) {}

                    public function getCategory(): object
                    {
                        return $this->category;
                    }
                };
            },
            $categoryIds
        );

        return new class ($links) {
            public function __construct(private array $links) {}

            public function getProductCategory(): array
            {
                return $this->links;
            }
        };
    }
}
