<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Helper\CnpjValidator;
use GrupoAwamotos\B2B\Model\Cnpj\RequestRateLimiter;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnrichRegistrationReceitaCommand extends Command
{
    private const LOG_FILE = '/var/log/b2b_registration_backfill.log';

    public function __construct(
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CnpjValidator $cnpjValidator,
        private readonly RequestRateLimiter $rateLimiter,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('b2b:registration:enrich-receita')
            ->setDescription('Enriquece clientes B2B via Receita Federal com rate limit')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem gravar')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Aplica alterações')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Máximo de clientes', '50')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reconsulta mesmo com situação preenchida');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception) {
            // Area code já definido pelo bootstrap do CLI — seguro ignorar.
        }

        $apply = (bool) $input->getOption('apply');
        $dryRun = (bool) $input->getOption('dry-run') || !$apply;
        $force = (bool) $input->getOption('force');
        $limit = max(1, min(200, (int) $input->getOption('limit')));

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['email', 'b2b_cnpj', 'b2b_receita_situacao', 'b2b_receita_validated']);
        $collection->addAttributeToFilter('b2b_cnpj', ['notnull' => true]);
        $collection->addAttributeToFilter('b2b_cnpj', ['neq' => '']);
        if (!$force) {
            $collection->addAttributeToFilter(
                [
                    ['attribute' => 'b2b_receita_situacao', 'null' => true],
                    ['attribute' => 'b2b_receita_situacao', 'eq' => ''],
                ]
            );
        }
        $collection->setPageSize($limit);

        $analyzed = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($collection as $row) {
            $analyzed++;
            $customerId = (int) $row->getId();
            $cnpj = preg_replace('/\D/', '', (string) $row->getData('b2b_cnpj'));

            if (strlen($cnpj) !== 14) {
                $skipped++;
                continue;
            }

            $rate = $this->rateLimiter->consume('b2b_backfill_receita');
            if (!$rate['allowed']) {
                $output->writeln('<comment>Rate limit atingido — interrompendo.</comment>');
                break;
            }

            $api = $this->cnpjValidator->validateApi($cnpj);
            if ($api === null || empty($api['valid'])) {
                $skipped++;
                $this->writeLog(sprintf('SKIP customer #%d — Receita indisponível/inválida', $customerId));
                continue;
            }

            $situacao = (string) ($api['data']['situacao'] ?? $api['situacao'] ?? '');
            $output->writeln(sprintf(
                '%s customer #%d situacao=%s',
                $dryRun ? '[DRY-RUN]' : '[APPLY]',
                $customerId,
                $situacao ?: '—'
            ));

            if ($dryRun) {
                $updated++;
                continue;
            }

            try {
                $customer = $this->customerRepository->getById($customerId);
                if ($situacao !== '') {
                    $customer->setCustomAttribute('b2b_receita_situacao', $situacao);
                }
                $customer->setCustomAttribute('b2b_receita_validated', '1');
                $this->customerRepository->save($customer);
                $updated++;
            } catch (\Throwable $e) {
                $skipped++;
                $this->writeLog(sprintf('ERROR customer #%d: %s', $customerId, $e->getMessage()));
            }

            usleep(500000);
        }

        $output->writeln('');
        $output->writeln(sprintf('Analisados: %d | Atualizados: %d | Ignorados: %d', $analyzed, $updated, $skipped));

        return Command::SUCCESS;
    }

    private function writeLog(string $message): void
    {
        $line = sprintf('[%s] [RECEITA] %s', date('Y-m-d H:i:s'), $message);
        @file_put_contents(BP . self::LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
