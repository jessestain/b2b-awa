<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use GrupoAwamotos\B2B\Helper\CnpjValidator;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ClearCnpjCacheCommand extends Command
{
    private const OPTION_CNPJ = 'cnpj';

    private CnpjValidator $cnpjValidator;
    private State $state;

    public function __construct(
        CnpjValidator $cnpjValidator,
        State $state,
        ?string $name = null
    ) {
        $this->cnpjValidator = $cnpjValidator;
        $this->state = $state;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:cnpj-cache:clear')
            ->setDescription('Limpa o cache das consultas de CNPJ (global ou por CNPJ específico)')
            ->addOption(
                self::OPTION_CNPJ,
                'c',
                InputOption::VALUE_OPTIONAL,
                'CNPJ específico para remover do cache'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Throwable $exception) {
            // Área já definida.
        }

        $cnpjOption = trim((string) $input->getOption(self::OPTION_CNPJ));

        if ($cnpjOption !== '') {
            $cleanCnpj = $this->cnpjValidator->clean($cnpjOption);

            if (strlen($cleanCnpj) !== 14) {
                $output->writeln('<error>CNPJ inválido. Informe 14 dígitos.</error>');
                return Command::FAILURE;
            }

            $cleared = $this->cnpjValidator->clearCache($cleanCnpj);
            if (!$cleared) {
                $output->writeln(
                    sprintf('<comment>Nenhuma entrada de cache removida para %s.</comment>', $cleanCnpj)
                );
                return Command::SUCCESS;
            }

            $output->writeln(
                sprintf('<info>Cache de CNPJ removido: %s</info>', $this->cnpjValidator->format($cleanCnpj))
            );

            return Command::SUCCESS;
        }

        $cleared = $this->cnpjValidator->clearCache();
        if (!$cleared) {
            $output->writeln('<comment>Nenhuma entrada de cache de CNPJ encontrada para limpar.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Cache global de consultas de CNPJ limpo com sucesso.</info>');
        return Command::SUCCESS;
    }
}
