<?php

/**
 * Comando CLI para testar conexão WhatsApp
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppService;
use Magento\Framework\App\State;

class TestWhatsAppCommand extends Command
{
    private WhatsAppService $whatsAppService;
    private State $state;

    public function __construct(
        WhatsAppService $whatsAppService,
        State $state,
        ?string $name = null
    ) {
        $this->whatsAppService = $whatsAppService;
        $this->state = $state;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('b2b:whatsapp:test')
            ->setDescription('Testa a conexão do WhatsApp enviando uma mensagem de teste')
            ->addOption('phone', 'p', InputOption::VALUE_REQUIRED, 'Número para enviar teste (com DDD, ex: 11999999999)')
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'Mensagem personalizada', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception $e) {
            // Área já definida
        }

        $phone = $input->getOption('phone');

        if (!$phone) {
            $output->writeln('<error>Por favor, informe o número de telefone com --phone=NUMERO</error>');
            $output->writeln('<info>Exemplo: bin/magento b2b:whatsapp:test --phone=11999999999</info>');
            return Command::FAILURE;
        }

        $output->writeln('<info>🔄 Testando conexão WhatsApp...</info>');
        $output->writeln('');

        // Verifica se está habilitado
        if (!$this->whatsAppService->isEnabled()) {
            $output->writeln('<error>❌ WhatsApp não está habilitado nas configurações.</error>');
            $output->writeln('<comment>Habilite em: Admin → Stores → Configuration → Grupo Awamotos → B2B → WhatsApp</comment>');
            return Command::FAILURE;
        }

        $customMessage = $input->getOption('message');
        $message = $customMessage ?: "✅ *Teste de Conexão WhatsApp*\n\n" .
            "Esta é uma mensagem de teste do sistema B2B da Grupo Awamotos.\n\n" .
            "Se você recebeu esta mensagem, a integração está funcionando corretamente! 🎉\n\n" .
            "📅 Data: " . date('d/m/Y H:i:s');

        $output->writeln("📱 Enviando para: {$phone}");
        $output->writeln('');

        $result = $this->whatsAppService->sendText($phone, $message);

        if ($result['success']) {
            $output->writeln('<info>✅ Mensagem enviada com sucesso!</info>');
            if (isset($result['message_id'])) {
                $output->writeln("<comment>ID da mensagem: {$result['message_id']}</comment>");
            }
            return Command::SUCCESS;
        } else {
            $output->writeln('<error>❌ Falha ao enviar mensagem</error>');
            $output->writeln('<error>Erro: ' . ($result['message'] ?? 'Erro desconhecido') . '</error>');

            if (isset($result['response'])) {
                $output->writeln('');
                $output->writeln('<comment>Resposta da API:</comment>');
                $output->writeln(json_encode($result['response'], JSON_PRETTY_PRINT));
            }

            return Command::FAILURE;
        }
    }
}
