<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\B2B\Api\ApprovalScoreServiceInterface;
use GrupoAwamotos\B2B\Api\Data\ApprovalScoreResultInterface;

/**
 * Tool: explica o score de aprovação B2B de um cliente (Admin only, somente leitura).
 *
 * Reusa GrupoAwamotos\B2B\Api\ApprovalScoreServiceInterface sem modificá-la.
 * A IA NUNCA aprova/reprova — apenas lê e explica o resultado do sistema.
 */
class AdminB2BScoreTool implements ToolInterface
{
    public function __construct(
        private readonly ApprovalScoreServiceInterface $approvalScore,
        private readonly \GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope $portfolioScope
    ) {
    }

    public function getName(): string
    {
        return 'admin_b2b_score';
    }

    public function getDescription(): string
    {
        return 'Avalia e explica o score de aprovação B2B de um cliente pelo customerId. '
            . 'Retorna score (green/yellow/red), motivo e recomendação do sistema. '
            . 'Somente leitura — nunca aprova nem reprova o cadastro.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'customer_id' => [
                    'type'        => 'integer',
                    'description' => 'ID do cliente Magento (customer_id) para avaliar o score B2B.',
                ],
            ],
            'required' => ['customer_id'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $customerId = (int) ($arguments['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return ['error' => 'customer_id inválido.'];
        }

        /* Escopo por carteira (mesma regra do cockpit comercial). Resposta
         * idêntica para "não existe" e "fora da carteira": anti-enumeração. */
        if (!$this->portfolioScope->canBypassPortfolioScope()
            && !$this->portfolioScope->canAccessCustomer($customerId)) {
            return ['error' => 'Cliente não encontrado na sua carteira.'];
        }

        try {
            $result = $this->approvalScore->evaluate($customerId);
            return [
                'customer_id'        => $customerId,
                'score'              => $result->getScore(),
                'score_label'        => $this->scoreLabel($result->getScore()),
                'reason'             => $result->getReason(),
                'suggested_group_id' => $result->getSuggestedGroupId(),
                'should_auto_approve' => $result->shouldAutoApprove(),
                'note'               => 'Este score é calculado automaticamente pelo sistema AWA B2B. '
                    . 'A decisão final de aprovação é responsabilidade da equipe comercial.',
            ];
        } catch (\Exception $e) {
            return ['error' => 'Erro ao avaliar score B2B: ' . $e->getMessage()];
        }
    }

    private function scoreLabel(string $score): string
    {
        return match ($score) {
            ApprovalScoreResultInterface::SCORE_GREEN  => 'Verde (Auto-aprovação recomendada)',
            ApprovalScoreResultInterface::SCORE_YELLOW => 'Amarelo (Revisão manual necessária)',
            ApprovalScoreResultInterface::SCORE_RED    => 'Vermelho (Negação recomendada)',
            default                                    => $score,
        };
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
