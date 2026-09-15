<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Helper;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\ResourceConnection;

/**
 * Verifica se o usuário admin logado é um atendente B2B e retorna seu ID.
 */
class CurrentAttendant
{
    private bool $resolved = false;
    private ?int $attendantId = null;

    public function __construct(
        private readonly AdminSession $adminSession,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Retorna true se o usuário admin logado é um atendente ativo.
     */
    public function isAttendant(): bool
    {
        return $this->getId() !== null;
    }

    /**
     * Retorna o attendant_id do usuário logado, ou null se não for atendente.
     */
    public function getId(): ?int
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->attendantId = $this->resolveAttendantId();
        }

        return $this->attendantId;
    }

    /**
     * Atendente da sessão, ou o da carteira do cliente quando o admin não é vendedora.
     */
    public function getIdForCustomer(int $customerId): ?int
    {
        $own = $this->getId();
        if ($own !== null) {
            return $own;
        }

        if ($customerId <= 0) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $mappingTable = $this->resource->getTableName('grupoawamotos_b2b_customer_attendant');
        $attendantTable = $this->resource->getTableName('grupoawamotos_b2b_attendants');

        $attendantId = $connection->fetchOne(
            $connection->select()
                ->from(['ca' => $mappingTable], ['attendant_id'])
                ->joinInner(
                    ['att' => $attendantTable],
                    'att.attendant_id = ca.attendant_id',
                    []
                )
                ->where('ca.customer_id = ?', $customerId)
                ->where('att.is_active = ?', 1)
                ->limit(1)
        );

        return $attendantId ? (int) $attendantId : null;
    }

    /**
     * Retorna os dados completos do atendente logado, ou null se não for atendente.
     *
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $id = $this->getId();
        if (!$id) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('grupoawamotos_b2b_attendants');

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('attendant_id = ?', $id)
                ->limit(1)
        );

        return $row ?: null;
    }

    private function resolveAttendantId(): ?int
    {
        $user = $this->adminSession->getUser();
        if (!$user || !$user->getId()) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('grupoawamotos_b2b_attendants');

        $attendantId = $connection->fetchOne(
            $connection->select()
                ->from($table, 'attendant_id')
                ->where('admin_user_id = ?', (int) $user->getId())
                ->where('is_active = ?', 1)
                ->limit(1)
        );

        return $attendantId ? (int) $attendantId : null;
    }
}
