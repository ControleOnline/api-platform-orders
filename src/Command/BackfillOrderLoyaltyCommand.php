<?php

namespace ControleOnline\Command;

use ControleOnline\Entity\Order;
use ControleOnline\Service\OrderLoyaltyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'orders:loyalty:backfill',
    description: 'Reconstrói cartões de fidelidade a partir de vendas fechadas ainda não vinculadas.',
)]
class BackfillOrderLoyaltyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly OrderLoyaltyService $loyaltyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persiste cartões e vínculos. Sem esta opção, apenas simula.')
            ->addOption('client-id', null, InputOption::VALUE_OPTIONAL, 'Limita a um cliente.')
            ->addOption('provider-id', null, InputOption::VALUE_OPTIONAL, 'Limita a uma franquia/provider.')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Data inicial inclusiva (YYYY-MM-DD).')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Data final inclusiva (YYYY-MM-DD).')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Máximo de vendas por execução.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(1, min(5000, (int) $input->getOption('limit')));

        try {
            $queryBuilder = $this->buildQuery($input, $limit);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $sales = $queryBuilder->getQuery()->getResult();
        $eligible = 0;
        $processed = 0;
        $skipped = 0;

        foreach ($sales as $sale) {
            if (!$sale instanceof Order || !$this->loyaltyService->canProcessOrder($sale)) {
                $skipped++;
                continue;
            }

            $eligible++;
            if ($apply && $this->loyaltyService->processOrder($sale)) {
                $processed++;
            }
        }

        $io->definitionList(
            ['Modo' => $apply ? 'APLICAR' : 'SIMULAÇÃO'],
            ['Vendas carregadas' => count($sales)],
            ['Vendas elegíveis' => $eligible],
            ['Vendas processadas' => $processed],
            ['Vendas ignoradas' => $skipped],
        );

        if (!$apply) {
            $io->note('Nenhuma alteração foi persistida. Revise o total e repita com --apply para executar.');
        }

        return Command::SUCCESS;
    }

    private function buildQuery(InputInterface $input, int $limit)
    {
        $queryBuilder = $this->manager->getRepository(Order::class)->createQueryBuilder('sale')
            ->innerJoin('sale.status', 'sale_status')
            ->andWhere('sale.orderType = :saleType')
            ->andWhere('sale.client IS NOT NULL')
            ->andWhere('sale.provider IS NOT NULL')
            ->andWhere('sale.mainOrderId IS NULL')
            ->andWhere('(sale_status.status = :closed OR sale_status.realStatus = :closed)')
            ->setParameter('saleType', Order::ORDER_TYPE_SALE)
            ->setParameter('closed', 'closed')
            ->orderBy('sale.orderDate', 'ASC')
            ->addOrderBy('sale.id', 'ASC')
            ->setMaxResults($limit);

        $clientId = $this->normalizeIdOption($input->getOption('client-id'), 'client-id');
        if ($clientId !== null) {
            $queryBuilder
                ->andWhere('IDENTITY(sale.client) = :clientId')
                ->setParameter('clientId', $clientId);
        }

        $providerId = $this->normalizeIdOption($input->getOption('provider-id'), 'provider-id');
        if ($providerId !== null) {
            $queryBuilder
                ->andWhere('IDENTITY(sale.provider) = :providerId')
                ->setParameter('providerId', $providerId);
        }

        $from = $this->normalizeDateOption($input->getOption('from'), 'from');
        if ($from instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('sale.orderDate >= :fromDate')
                ->setParameter('fromDate', $from->setTime(0, 0));
        }

        $to = $this->normalizeDateOption($input->getOption('to'), 'to');
        if ($to instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('sale.orderDate <= :toDate')
                ->setParameter('toDate', $to->setTime(23, 59, 59));
        }

        return $queryBuilder;
    }

    private function normalizeIdOption(mixed $value, string $option): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $id = (int) $value;
        if ($id <= 0) {
            throw new \InvalidArgumentException(sprintf('A opção --%s deve ser um ID positivo.', $option));
        }

        return $id;
    }

    private function normalizeDateOption(mixed $value, string $option): ?\DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) $value));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException(sprintf('A opção --%s deve usar o formato YYYY-MM-DD.', $option));
        }

        return $date;
    }
}
