<?php
declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\Support\B2bAccessExplainer;
use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;

/**
 * "Por que não vejo preço / não consigo comprar?" — a dor nº 1 do cliente B2B.
 *
 * Somente leitura. Delega a decisão a PriceVisibilityInterface (a mesma regra
 * que a loja usa para ocultar o preço) e só traduz o motivo; nunca decide,
 * libera nem altera nada. A identidade vem SEMPRE do contexto da sessão — o
 * modelo não passa nenhum identificador, então não há como consultar outra conta.
 */
class B2BPriceTableStatusTool implements ToolInterface
{
    public function __construct(
        private readonly PriceVisibilityInterface $priceVisibility,
        private readonly CustomerApprovalInterface $customerApproval,
        private readonly AttendantManager $attendantManager,
        private readonly B2bAccessExplainer $explainer,
        private readonly Config $config
    ) {
    }

    public function getName(): string
    {
        return 'b2b_price_table_status';
    }

    public function getDescription(): string
    {
        return 'Explica por que o cliente NÃO vê preço ou NÃO consegue comprar (visitante sem login, cadastro em '
            . 'análise, aprovado aguardando a tabela de preços, reprovado ou suspenso) e qual é o próximo passo. '
            . 'Use quando perguntarem "por que não vejo preço", "fui aprovado mas não consigo comprar", '
            . '"onde está o botão de comprar". Não recebe parâmetros: consulta a conta da pessoa que está '
            . 'conversando. Somente leitura — nunca libera preço nem aprova cadastro.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $loggedIn   = $customerId > 0;

        $approval = $loggedIn ? $this->customerApproval->getApprovalStatus($customerId) : null;
        $state    = $this->explainer->priceState(
            $loggedIn,
            $approval,
            $this->priceVisibility->canViewPrices(),
            $loggedIn && $this->priceVisibility->isApprovedPendingErp()
        );
        $info = $this->explainer->describe($state);

        $result = [
            'logged_in'       => $loggedIn,
            'state'           => $state,
            'can_view_prices' => $state === B2bAccessExplainer::STATE_CAN_VIEW,
            'explanation'     => $info['explanation'],
            'next_step'       => $info['next_step'],
            'note'            => 'Explique em linguagem simples, sem prometer prazo e sem decidir pela equipe.',
        ];

        if ($info['needs_human']) {
            $result['contact'] = $this->contactFor($customerId);
        }

        return $result;
    }

    public function getAllowedChannels(): array
    {
        // Cliente PENDENTE cai no canal storefront; APROVADO cai no B2B.
        return [
            AssistantOrchestratorInterface::CHANNEL_STOREFRONT,
            AssistantOrchestratorInterface::CHANNEL_B2B,
        ];
    }

    /**
     * Atendente da própria conta (dado do próprio cliente) ou atendimento geral.
     *
     * @return array<string, string>
     */
    private function contactFor(int $customerId): array
    {
        if ($customerId > 0) {
            try {
                $attendant = $this->attendantManager->getCustomerAttendant($customerId);
            } catch (\Throwable) {
                $attendant = null;
            }
            if (is_array($attendant)) {
                $link = $this->explainer->whatsappLink((string) ($attendant['whatsapp'] ?? $attendant['phone'] ?? ''));
                $name = trim((string) ($attendant['name'] ?? ''));
                if ($link !== '' && $name !== '') {
                    return ['attendant' => $name, 'whatsapp_url' => $link];
                }
            }
        }

        return ['whatsapp_url' => $this->config->getHumanHandoffUrl()];
    }
}
