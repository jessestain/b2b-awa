<?php

/**
 * B2B Order Approval Service
 *
 * CORRIGIDO (auditoria 2026-10):
 *  - approve()/reject() separam o caminho do PORTAL DO CLIENTE (identidade = customer_id,
 *    autorização por empresa) do caminho ADMIN (identidade = admin_user_id, autorização por
 *    ALÇADA/ACL). Antes, ProcessAction passava admin_user.user_id para um método que o
 *    interpretava como customer_id e consultava company_user.customer_id — a autorização era
 *    decidida por colisão numérica entre IDs de tabelas diferentes.
 *  - approveByAdmin()/rejectByAdmin() exigem que o aprovador tenha nível de alçada
 *    >= required_level do pedido (recursos ACL order_approval_manager|finance|director).
 *    Antes não havia nenhuma validação de nível: qualquer conta com o recurso aprovava
 *    qualquer valor.
 *  - bloqueio de autoaprovação no portal do cliente (solicitante não aprova o próprio pedido).
 *  - createApprovalRequest() é idempotente e atômico (linha de aprovação + retenção do pedido
 *    na mesma transação), evitando o estado divergente "aprovação pendente sem pedido retido".
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use GrupoAwamotos\B2B\Model\OrderApprovalFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use GrupoAwamotos\B2B\Model\ResourceModel\OrderApproval\CollectionFactory;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Psr\Log\LoggerInterface;

class OrderApprovalService
{
    /**
     * Recursos ACL que definem a alçada máxima do aprovador (admin).
     * Ordem: do maior para o menor, usado só para documentação/mapa.
     */
    private const ACL_LEVEL_MAP = [
        'GrupoAwamotos_B2B::order_approval_director' => OrderApproval::LEVEL_DIRECTOR,
        'GrupoAwamotos_B2B::order_approval_finance' => OrderApproval::LEVEL_FINANCE,
        'GrupoAwamotos_B2B::order_approval_manager' => OrderApproval::LEVEL_MANAGER,
    ];

    /**
     * Recurso "tudo" do Magento_Backend: a engine de ACL concede QUALQUER recurso a um papel
     * que o possua (Administrators, AWA TI, SECTRA ERP). Mantemos a mesma semântica aqui para
     * não remover de fato uma capacidade que já existia.
     */
    private const ACL_ALL_RESOURCE = 'Magento_Backend::all';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var OrderApprovalFactory
     */
    private $approvalFactory;

    /**
     * @var OrderApprovalResource
     */
    private $approvalResource;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var B2BHelper
     */
    private $b2bHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CompanyService
     */
    private $companyService;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CustomerSession $customerSession,
        OrderApprovalFactory $approvalFactory,
        OrderApprovalResource $approvalResource,
        CollectionFactory $collectionFactory,
        B2BHelper $b2bHelper,
        LoggerInterface $logger,
        CompanyService $companyService
    ) {
        $this->orderRepository = $orderRepository;
        $this->customerSession = $customerSession;
        $this->approvalFactory = $approvalFactory;
        $this->approvalResource = $approvalResource;
        $this->collectionFactory = $collectionFactory;
        $this->b2bHelper = $b2bHelper;
        $this->logger = $logger;
        $this->companyService = $companyService;
    }

    /**
     * Ensure the approver belongs to the same company as the order's requester.
     *
     * SOMENTE para o caminho do portal do cliente, onde o segundo parâmetro é de fato um
     * customer_id. NÃO usar no caminho admin: admin_user_id e customer_id são espaços de
     * identificador distintos e a comparação cruzada gerava autorização por colisão numérica.
     *
     * @param OrderApproval $approval
     * @param int $approverCustomerId
     * @throws AuthorizationException
     */
    private function assertApproverBelongsToCompany(OrderApproval $approval, int $approverCustomerId): void
    {
        $requesterId = (int) $approval->getData('customer_id');

        $requesterCompanyIds = $this->companyService->getCompanyIdsForCustomer($requesterId);
        $approverCompanyIds = $this->companyService->getCompanyIdsForCustomer($approverCustomerId);
        $sharedCompanyIds = array_intersect($requesterCompanyIds, $approverCompanyIds);

        if ($requesterCompanyIds === [] || $approverCompanyIds === [] || $sharedCompanyIds === []) {
            $this->logger->warning('B2B Order Approval: cross-company access blocked', [
                'approval_id' => $approval->getId(),
                'requester_id' => $requesterId,
                'requester_company_ids' => $requesterCompanyIds,
                'approver_id' => $approverCustomerId,
                'approver_company_ids' => $approverCompanyIds,
            ]);
            throw new AuthorizationException(__('Você não tem permissão para esta ação.'));
        }
    }

    /**
     * Create approval request for order.
     *
     * Idempotente: se já existe solicitação para o pedido, devolve a existente.
     * Atômico: a linha de aprovação e a retenção do pedido são gravadas na mesma transação.
     *
     * @param int $orderId
     * @param int $requiredLevel
     * @return OrderApproval
     * @throws LocalizedException
     */
    public function createApprovalRequest(int $orderId, int $requiredLevel = OrderApproval::LEVEL_MANAGER): OrderApproval
    {
        $existing = $this->getByOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $order = $this->orderRepository->get($orderId);

        // Pré-condição verificada ANTES de gravar qualquer linha: se o pedido não puder ser
        // retido, nada é criado (evita "aprovação pendente sem pedido retido").
        $mustHold = $order->getState() !== Order::STATE_HOLDED;
        if ($mustHold && !$order->canHold()) {
            throw new LocalizedException(
                __('O pedido não pode ser retido para aprovação no estado atual (%1).', (string) $order->getState())
            );
        }

        $connection = $this->approvalResource->getConnection();
        // O observer roda DENTRO da transação de AbstractDb::save(); rollBack() aninhado no
        // Magento é no-op. Então só abrimos transação própria quando não há uma em curso e,
        // em qualquer caso, compensamos removendo a linha se a retenção falhar.
        $ownTransaction = $connection->getTransactionLevel() === 0;
        if ($ownTransaction) {
            $connection->beginTransaction();
        }

        $approval = null;
        try {
            $approval = $this->approvalFactory->create();
            $approval->setData([
                'order_id' => $orderId,
                'customer_id' => $order->getCustomerId(),
                'status' => OrderApproval::STATUS_PENDING,
                'current_level' => OrderApproval::LEVEL_BUYER,
                'required_level' => $requiredLevel,
                'order_total' => $order->getGrandTotal(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $this->approvalResource->save($approval);

            if ($mustHold) {
                // FIX 2026-10-20 (incidente Sigma, checkout 400 11:49/11:50Z):
                // NÃO re-salvar o pedido aqui. Este método roda de dentro de
                // sales_order_save_after (transação do save original do pedido) e,
                // nesse ponto, o pedido recarregado via OrderRepository::get() vem SEM
                // payment. O re-save com state=holded disparava o observer core
                // Magento\Payment\Observer\SalesOrderBeforeSaveObserver
                // ("Please provide payment for the order."), cujo rollback aninhado
                // envenenava o adapter ("Rolled back transaction has not been completed
                // correctly.") e derrubava o save do pedido inteiro (checkout 400,
                // pedido descartado).
                //
                // A retenção passa a ser gravada por UPDATE direto na MESMA conexão e,
                // portanto, na MESMA transação do save original (ou na transação própria
                // aberta acima, quando não há transação em curso): atômica com a linha de
                // aprovação e sem re-entrância. Semântica preservada: state/status holded,
                // hold_before_state/status e comentário no histórico do pedido.
                // O gate de importação do Sectra continua protegido: a view oc_order só
                // expõe pedidos com state in (new,pending_payment,processing) — pedido
                // retido não vai ao ERP até ser desretido após aprovação.
                $holdStatus = $order->getConfig()->getStateDefaultStatus(Order::STATE_HOLDED);
                $connection->update(
                    'sales_order',
                    [
                        'state' => Order::STATE_HOLDED,
                        'status' => $holdStatus,
                        'hold_before_state' => $order->getState(),
                        'hold_before_status' => $order->getStatus(),
                    ],
                    ['entity_id = ?' => $orderId]
                );
                $connection->insert('sales_order_status_history', [
                    'parent_id' => $orderId,
                    'comment' => (string) __(
                        'Pedido aguardando aprovação B2B. Nível requerido: %1',
                        $this->getLevelName($requiredLevel)
                    ),
                    'entity_name' => 'order',
                    'is_customer_notified' => 0,
                    'is_visible_on_front' => 0,
                    'status' => $holdStatus,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if ($ownTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($approval !== null && $approval->getId()) {
                try {
                    $this->approvalResource->delete($approval);
                } catch (\Throwable $cleanupError) {
                    $this->logger->error('B2B Order Approval: falha ao compensar linha de aprovação: ' . $cleanupError->getMessage());
                }
            }
            if ($ownTransaction) {
                $connection->rollBack();
            }
            $this->logger->error(sprintf(
                'B2B Order Approval: falha ao criar solicitação para o pedido %s: %s',
                (string) $orderId,
                $e->getMessage()
            ));
            if ($e instanceof LocalizedException) {
                throw $e;
            }
            throw new LocalizedException(__('Não foi possível criar a solicitação de aprovação.'), $e);
        }

        $this->logger->info("B2B Order Approval created for order #$orderId, required level: $requiredLevel");

        return $approval;
    }

    /**
     * Approve order at current level — CAMINHO DO PORTAL DO CLIENTE.
     *
     * Autorização: mesma empresa do solicitante + não pode ser o próprio solicitante.
     *
     * @param int $approvalId
     * @param int $approverId customer_id do aprovador
     * @param string $comment
     * @return bool
     * @throws LocalizedException
     */
    public function approve(int $approvalId, int $approverId, string $comment = ''): bool
    {
        $approval = $this->loadPendingApproval($approvalId);
        $this->assertApproverBelongsToCompany($approval, $approverId);
        $this->assertNotRequester($approval, $approverId);

        return $this->applyApproval($approval, $approverId, 'customer', OrderApproval::LEVEL_BUYER, $comment);
    }

    /**
     * Approve order — CAMINHO ADMIN, com validação de ALÇADA.
     *
     * @param int $approvalId
     * @param int $adminUserId admin_user.user_id (NUNCA customer_id)
     * @param string $comment
     * @return bool
     * @throws LocalizedException
     */
    public function approveByAdmin(int $approvalId, int $adminUserId, string $comment = ''): bool
    {
        $approval = $this->loadPendingApproval($approvalId);
        $approverLevel = $this->resolveApproverLevel($adminUserId);
        $this->assertLevelSufficient($approval, $approverLevel, $adminUserId);

        return $this->applyApproval($approval, $adminUserId, 'admin', $approverLevel, $comment);
    }

    /**
     * Reject order approval — CAMINHO DO PORTAL DO CLIENTE.
     *
     * @param int $approvalId
     * @param int $rejectorId customer_id
     * @param string $reason
     * @return bool
     * @throws LocalizedException
     */
    public function reject(int $approvalId, int $rejectorId, string $reason): bool
    {
        $approval = $this->loadApproval($approvalId);
        $this->assertApproverBelongsToCompany($approval, $rejectorId);
        $this->assertNotRequester($approval, $rejectorId);

        return $this->applyRejection($approval, $rejectorId, 'customer', 'customer', $reason);
    }

    /**
     * Reject order approval — CAMINHO ADMIN, com validação de ALÇADA.
     *
     * @param int $approvalId
     * @param int $adminUserId admin_user.user_id
     * @param string $reason
     * @return bool
     * @throws LocalizedException
     */
    public function rejectByAdmin(int $approvalId, int $adminUserId, string $reason): bool
    {
        $approval = $this->loadApproval($approvalId);
        $approverLevel = $this->resolveApproverLevel($adminUserId);
        $this->assertLevelSufficient($approval, $approverLevel, $adminUserId);

        return $this->applyRejection($approval, $adminUserId, 'admin', $approverLevel, $reason);
    }

    /**
     * Nível de alçada do aprovador admin, derivado dos recursos ACL concedidos
     * (mesma fonte de verdade usada pela engine de ACL do admin: authorization_rule).
     *
     * @param int $adminUserId
     * @return int
     */
    public function resolveApproverLevel(int $adminUserId): int
    {
        if ($adminUserId <= 0) {
            return OrderApproval::LEVEL_BUYER;
        }

        $connection = $this->approvalResource->getConnection();
        $roleTable = $this->approvalResource->getTable('authorization_role');
        $ruleTable = $this->approvalResource->getTable('authorization_rule');

        $resources = $connection->fetchCol(
            $connection->select()
                ->from(['ur' => $roleTable], [])
                ->joinInner(['g' => $roleTable], 'g.role_id = ur.parent_id', [])
                ->joinInner(['r' => $ruleTable], 'r.role_id = g.role_id AND r.permission = \'allow\'', ['resource_id'])
                ->where('ur.user_id = ?', $adminUserId)
                ->where('ur.role_type = ?', 'U')
                ->where('r.resource_id IN (?)', array_merge(array_keys(self::ACL_LEVEL_MAP), [self::ACL_ALL_RESOURCE]))
        );

        $level = OrderApproval::LEVEL_BUYER;
        foreach ($resources as $resource) {
            if ($resource === self::ACL_ALL_RESOURCE) {
                return OrderApproval::LEVEL_DIRECTOR;
            }
            $candidate = (int) (self::ACL_LEVEL_MAP[$resource] ?? OrderApproval::LEVEL_BUYER);
            if ($candidate > $level) {
                $level = $candidate;
            }
        }

        return $level;
    }

    /**
     * @param OrderApproval $approval
     * @param int $approverLevel
     * @param int $adminUserId
     * @throws AuthorizationException
     */
    private function assertLevelSufficient(OrderApproval $approval, int $approverLevel, int $adminUserId): void
    {
        $requiredLevel = (int) $approval->getData('required_level');

        if ($approverLevel < $requiredLevel) {
            $this->logger->warning('B2B Order Approval: alcada insuficiente', [
                'approval_id' => $approval->getId(),
                'admin_user_id' => $adminUserId,
                'approver_level' => $approverLevel,
                'required_level' => $requiredLevel,
            ]);
            throw new AuthorizationException(__(
                'Sua alçada (%1) não permite aprovar pedidos de nível %2.',
                $this->getLevelName($approverLevel),
                $this->getLevelName($requiredLevel)
            ));
        }
    }

    /**
     * @param OrderApproval $approval
     * @param int $approverCustomerId
     * @throws AuthorizationException
     */
    private function assertNotRequester(OrderApproval $approval, int $approverCustomerId): void
    {
        if ($approverCustomerId > 0 && $approverCustomerId === (int) $approval->getData('customer_id')) {
            throw new AuthorizationException(__('O solicitante não pode aprovar ou rejeitar o próprio pedido.'));
        }
    }

    /**
     * @param int $approvalId
     * @return OrderApproval
     * @throws LocalizedException
     */
    private function loadApproval(int $approvalId): OrderApproval
    {
        $approval = $this->approvalFactory->create();
        $this->approvalResource->load($approval, $approvalId);

        if (!$approval->getId()) {
            throw new LocalizedException(__('Solicitação de aprovação não encontrada.'));
        }

        return $approval;
    }

    /**
     * @param int $approvalId
     * @return OrderApproval
     * @throws LocalizedException
     */
    private function loadPendingApproval(int $approvalId): OrderApproval
    {
        $approval = $this->loadApproval($approvalId);

        if ($approval->getData('status') !== OrderApproval::STATUS_PENDING) {
            throw new LocalizedException(__('Esta solicitação já foi processada.'));
        }

        return $approval;
    }

    /**
     * Aplica uma aprovação (avança o nível ou conclui), gravando histórico.
     *
     * @param OrderApproval $approval
     * @param int $approverId
     * @param string $approverType
     * @param int $approverLevel
     * @param string $comment
     * @return bool
     * @throws LocalizedException
     */
    private function applyApproval(
        OrderApproval $approval,
        int $approverId,
        string $approverType,
        int $approverLevel,
        string $comment
    ): bool {
        $currentLevel = (int) $approval->getData('current_level');
        $requiredLevel = (int) $approval->getData('required_level');
        $nextLevel = $approval->getNextLevel($currentLevel);

        $history = json_decode($approval->getData('approval_history') ?: '[]', true) ?: [];
        $history[] = [
            'level' => $currentLevel,
            'next_level' => $nextLevel,
            'approver_id' => $approverId,
            'approver_type' => $approverType,
            'approver_level' => $approverLevel,
            'action' => 'approved',
            'comment' => $comment,
            'date' => date('Y-m-d H:i:s')
        ];
        $approval->setData('approval_history', json_encode($history));

        $fullyApproved = !($nextLevel && $nextLevel <= $requiredLevel);
        if ($fullyApproved) {
            $approval->setData('status', OrderApproval::STATUS_APPROVED);
            $approval->setData('approved_at', date('Y-m-d H:i:s'));
        } else {
            $approval->setData('current_level', $nextLevel);
            $approval->setData('updated_at', date('Y-m-d H:i:s'));
        }

        $connection = $this->approvalResource->getConnection();
        $ownTransaction = $connection->getTransactionLevel() === 0;
        if ($ownTransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->approvalResource->save($approval);
            if ($ownTransaction) {
                $connection->commit();
            }
            // Só libera o pedido DEPOIS de a aprovação estar persistida: se a liberação
            // falhar, o estado fica "aprovado, ainda retido" (recuperável) e não o inverso.
            if ($fullyApproved) {
                $this->releaseOrder((int) $approval->getData('order_id'));
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $connection->rollBack();
            }
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(__('Não foi possível registrar a aprovação.'), $e);
        }

        return true;
    }

    /**
     * Aplica uma rejeição (cancela o pedido), gravando histórico.
     *
     * @param OrderApproval $approval
     * @param int $rejectorId
     * @param string $rejectorType
     * @param string $rejectorLevelLabel
     * @param string $reason
     * @return bool
     * @throws LocalizedException
     */
    private function applyRejection(
        OrderApproval $approval,
        int $rejectorId,
        string $rejectorType,
        string $rejectorLevelLabel,
        string $reason
    ): bool {
        $history = json_decode($approval->getData('approval_history') ?: '[]', true) ?: [];
        $history[] = [
            'level' => (int) $approval->getData('current_level'),
            'approver_id' => $rejectorId,
            'approver_type' => $rejectorType,
            'approver_level_label' => $rejectorLevelLabel,
            'action' => 'rejected',
            'comment' => $reason,
            'date' => date('Y-m-d H:i:s')
        ];

        $approval->setData('approval_history', json_encode($history));
        $approval->setData('status', OrderApproval::STATUS_REJECTED);
        $approval->setData('rejection_reason', $reason);
        $approval->setData('updated_at', date('Y-m-d H:i:s'));

        $connection = $this->approvalResource->getConnection();
        $ownTransaction = $connection->getTransactionLevel() === 0;
        if ($ownTransaction) {
            $connection->beginTransaction();
        }

        try {
            $this->approvalResource->save($approval);
            if ($ownTransaction) {
                $connection->commit();
            }
            // Só cancela o pedido depois de a rejeição estar persistida.
            $this->cancelOrder((int) $approval->getData('order_id'), $reason);
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $connection->rollBack();
            }
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(__('Não foi possível registrar a rejeição.'), $e);
        }

        return true;
    }

    /**
     * Get pending approvals for approver level
     *
     * @param int $approverLevel
     * @param int|null $customerId
     * @return \GrupoAwamotos\B2B\Model\ResourceModel\OrderApproval\Collection
     */
    public function getPendingApprovals(int $approverLevel, ?int $customerId = null)
    {
        $collection = $this->collectionFactory->create();
        $collection->filterPending()
                   ->filterByLevel($approverLevel);

        if ($customerId) {
            $collection->filterByCustomer($customerId);
        }

        return $collection;
    }

    /**
     * Get approval by order ID
     *
     * @param int $orderId
     * @return OrderApproval|null
     */
    public function getByOrderId(int $orderId): ?OrderApproval
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);

        $approval = $collection->getFirstItem();
        return $approval->getId() ? $approval : null;
    }

    /**
     * Determine required approval level based on order total
     *
     * @param float $orderTotal
     * @return int
     */
    public function determineRequiredLevel(float $orderTotal): int
    {
        $thresholdDirector = $this->b2bHelper->getThresholdDirector();
        $thresholdFinance = $this->b2bHelper->getThresholdFinance();
        $thresholdManager = $this->b2bHelper->getThresholdManager();

        if ($thresholdDirector > 0 && $orderTotal >= $thresholdDirector) {
            return OrderApproval::LEVEL_DIRECTOR;
        } elseif ($thresholdFinance > 0 && $orderTotal >= $thresholdFinance) {
            return OrderApproval::LEVEL_FINANCE;
        } elseif ($thresholdManager > 0 && $orderTotal >= $thresholdManager) {
            return OrderApproval::LEVEL_MANAGER;
        }

        return OrderApproval::LEVEL_BUYER; // Auto-approved
    }

    /**
     * Release order from hold
     *
     * @param int $orderId
     * @return void
     */
    private function releaseOrder(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $order->unhold();
            $order->addCommentToStatusHistory(__('Pedido aprovado pelo fluxo B2B.'));
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error("Error releasing order $orderId: " . $e->getMessage());
        }
    }

    /**
     * Cancel order
     *
     * @param int $orderId
     * @param string $reason
     * @return void
     */
    private function cancelOrder(int $orderId, string $reason): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
            if ($order->canCancel()) {
                $order->cancel();
                $order->addCommentToStatusHistory(__('Pedido rejeitado no fluxo B2B: %1', $reason));
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->error("Error canceling order $orderId: " . $e->getMessage());
        }
    }

    /**
     * Get level name
     *
     * @param int $level
     * @return string
     */
    private function getLevelName(int $level): string
    {
        $levels = OrderApproval::getLevels();
        return (string) ($levels[$level] ?? __('Desconhecido'));
    }
}
