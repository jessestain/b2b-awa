<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Model\OrderApproval;
use GrupoAwamotos\B2B\Model\OrderApprovalService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Gatilho da alçada de aprovação de pedidos B2B.
 *
 * CORRIGIDO (auditoria 2026-10): este observer estava registrado APENAS em
 * etc/frontend/events.xml e no evento sales_order_place_after, o que o deixava
 * inoperante em dois níveis:
 *
 *  1. ÁREA — etc/frontend/events.xml só é carregado na área frontend. Pedido criado
 *     no admin (sales/order_create), via REST/GraphQL ou gravado por
 *     OrderRepositoryInterface::save() nunca disparava o gatilho.
 *  2. MOMENTO — Magento\Sales\Model\Order::place() dispara sales_order_place_after
 *     ANTES de o pedido ser persistido: em Magento\Sales\Model\Service\OrderService::place()
 *     o save acontece DEPOIS de $order->place(). Nesse ponto $order->getEntityId()
 *     ainda é nulo e a solicitação era criada com order_id = 0 (ou falhava).
 *     O próprio módulo já documenta esse comportamento em SectraOrderImportGateObserver.
 *
 * Por isso o gatilho roda agora em sales_order_save_after (área global = todas as
 * áreas) e só age no primeiro save do pedido, com três guardas:
 *   - idempotência: só age se ainda não existir solicitação para o pedido;
 *   - recência: só age em pedido criado dentro de FRESH_WINDOW_SECONDS
 *     (impede reter pedido histórico salvo depois por sync de ERP, fatura, comentário);
 *   - retenção: só age se o pedido puder ser retido (canHold()).
 *
 * O nome da classe foi mantido por compatibilidade com as definições compiladas de
 * produção (renomear exigiria setup:di:compile). Renomear para
 * OrderApprovalTriggerObserver no próximo deploy com compilação.
 */
class OrderPlaceAfterObserver implements ObserverInterface
{
    /** Janela (segundos) em que um pedido é considerado recém-criado. */
    private const FRESH_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly B2BHelper $b2bHelper,
        private readonly OrderApprovalService $approvalService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof OrderInterface || !$order->getEntityId() || !$order->getCustomerId()) {
            return;
        }

        if (!$this->isApprovalEnabled()) {
            return;
        }

        // Guarda anti-retração: pedido antigo (ex.: salvo por sincronização ERP) nunca é retido.
        if (!$this->isFreshOrder($order)) {
            return;
        }

        $total = (float) $order->getGrandTotal();
        $requiredLevel = $this->determineLevel($total);
        if ($requiredLevel <= OrderApproval::LEVEL_BUYER) {
            return; // abaixo da alçada: segue sem aprovação
        }

        // Idempotência: um pedido nunca recebe duas solicitações.
        if ($this->approvalService->getByOrderId((int) $order->getEntityId()) !== null) {
            return;
        }

        if (!$this->b2bHelper->isB2BCustomerById((int) $order->getCustomerId())) {
            return;
        }

        if ($order->getState() === Order::STATE_HOLDED) {
            return;
        }

        if ($order instanceof Order && !$order->canHold()) {
            $this->logger->warning(sprintf(
                '[B2B Approval] pedido %s exige nivel %d mas nao pode ser retido (state=%s)',
                (string) $order->getIncrementId(),
                $requiredLevel,
                (string) $order->getState()
            ));

            return;
        }

        try {
            $this->approvalService->createApprovalRequest((int) $order->getEntityId(), $requiredLevel);
            $this->logger->info(sprintf(
                '[B2B Approval] solicitacao criada: pedido=%s total=%.2f nivel=%d',
                (string) $order->getIncrementId(),
                $total,
                $requiredLevel
            ));
        } catch (\Exception $e) {
            $this->logger->error('[B2B Approval] falha ao criar solicitacao: ' . $e->getMessage());
        }
    }

    private function isApprovalEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('grupoawamotos_b2b/order_approval/enabled');
    }

    /**
     * Pedido criado dentro da janela de recência (created_at é UTC no Magento).
     *
     * ATENÇÃO: sales_order.created_at tem DEFAULT CURRENT_TIMESTAMP no banco e o INSERT
     * pode omitir a coluna, de modo que o objeto recém-salvo em memória pode não ter
     * created_at. Esse é exatamente o caso do pedido novo — e um pedido ANTIGO carregado
     * do banco sempre tem created_at (coluna NOT NULL). Por isso created_at vazio é
     * tratado como recém-criado, nunca como histórico.
     */
    private function isFreshOrder(OrderInterface $order): bool
    {
        $createdAt = trim((string) $order->getCreatedAt());
        if ($createdAt === '') {
            return true;
        }

        $timestamp = strtotime($createdAt . ' UTC');
        if ($timestamp === false) {
            return false;
        }

        return (time() - $timestamp) <= self::FRESH_WINDOW_SECONDS;
    }

    private function determineLevel(float $total): int
    {
        $directorThreshold = (float) $this->scopeConfig->getValue('grupoawamotos_b2b/order_approval/threshold_director');
        $financeThreshold = (float) $this->scopeConfig->getValue('grupoawamotos_b2b/order_approval/threshold_finance');
        $managerThreshold = (float) $this->scopeConfig->getValue('grupoawamotos_b2b/order_approval/threshold_manager');

        if ($directorThreshold > 0 && $total >= $directorThreshold) {
            return OrderApproval::LEVEL_DIRECTOR;
        }
        if ($financeThreshold > 0 && $total >= $financeThreshold) {
            return OrderApproval::LEVEL_FINANCE;
        }
        if ($managerThreshold > 0 && $total >= $managerThreshold) {
            return OrderApproval::LEVEL_MANAGER;
        }

        return OrderApproval::LEVEL_BUYER;
    }
}
