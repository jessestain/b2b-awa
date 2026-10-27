<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\WhatsAppCommerce\Api\AdminDashboardInterface;

/**
 * Tool: detalha um pedido pelo número de incremento (Admin only, somente leitura).
 */
class AdminOrderDetailTool implements ToolInterface
{
    public function __construct(
        private readonly AdminDashboardInterface $adminDashboard,
        private readonly \GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope $portfolioScope,
        private readonly \Magento\Framework\App\ResourceConnection $resource
    ) {
    }

    public function getName(): string
    {
        return 'admin_order_detail';
    }

    public function getDescription(): string
    {
        return 'Retorna os detalhes de um pedido (cliente, itens, status, pagamento, envio) pelo número do pedido. Somente leitura.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'order_number' => [
                    'type'        => 'string',
                    'description' => 'Número do pedido (ex: 100012345).',
                ],
            ],
            'required' => ['order_number'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $incrementId = trim((string) ($arguments['order_number'] ?? ''));
        if ($incrementId === '') {
            return ['error' => 'Número do pedido não informado.'];
        }

        /* Escopo por carteira: o pedido só é consultável se o cliente dono
         * estiver na carteira visível. Mensagem única para inexistente e
         * fora de escopo (anti-enumeração). */
        if (!$this->portfolioScope->canBypassPortfolioScope()) {
            $connection = $this->resource->getConnection();
            $customerId = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order'), ['customer_id'])
                    ->where('increment_id = ?', $incrementId)
                    ->limit(1)
            );
            if (!$customerId || !$this->portfolioScope->canAccessCustomer((int) $customerId)) {
                return ['error' => 'Pedido não encontrado na sua carteira.'];
            }
        }

        return $this->adminDashboard->orderDetail($incrementId);
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
