<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model;

use GrupoAwamotos\B2B\CommercialPanel\Api\ContactLogManagementInterface;
use GrupoAwamotos\B2B\CommercialPanel\Api\Data\ContactLogInterface;
use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\ContactLogResource;
use GrupoAwamotos\B2B\Helper\CurrentAttendant;
use Magento\Framework\Exception\LocalizedException;

class ContactLogManagement implements ContactLogManagementInterface
{
    /** @var string[] */
    private const ALLOWED_CONTACT_TYPES = [
        'whatsapp',
        'phone',
        'email',
        'visit',
        'chat',
        'other',
    ];

    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly CurrentAttendant $currentAttendant,
        private readonly ContactLogFactory $contactLogFactory,
        private readonly ContactLogResource $contactLogResource
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function registerContact(array $data, int $adminUserId): ContactLogInterface
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new LocalizedException(__('Cliente inválido.'));
        }

        $canAccess = $this->portfolioScope->canAccessCustomer($customerId);
        if (!$canAccess) {
            throw new LocalizedException(__('Cliente fora da sua carteira comercial.'));
        }

        $contactType = strtolower(trim((string) ($data['contact_type'] ?? '')));
        if (!in_array($contactType, self::ALLOWED_CONTACT_TYPES, true)) {
            throw new LocalizedException(__('Tipo de contato inválido.'));
        }

        $observation = trim((string) ($data['observation'] ?? ''));
        if ($observation === '') {
            throw new LocalizedException(__('Informe a observação do contato.'));
        }

        if (mb_strlen($observation) > 5000) {
            throw new LocalizedException(__('Observação muito longa (máximo 5000 caracteres).'));
        }

        $nextAction = trim((string) ($data['next_action'] ?? ''));
        $nextAction = $nextAction !== '' ? mb_substr($nextAction, 0, 255) : null;

        $nextActionAt = trim((string) ($data['next_action_at'] ?? ''));
        $nextActionAt = $nextActionAt !== '' ? $this->normalizeDateTime($nextActionAt) : null;

        /** @var ContactLogInterface $contactLog */
        $contactLog = $this->contactLogFactory->create();
        $contactLog->setCustomerId($customerId);
        $contactLog->setAttendantId($this->currentAttendant->getIdForCustomer($customerId));
        $contactLog->setAdminUserId($adminUserId);
        $contactLog->setContactType($contactType);
        $contactLog->setObservation($observation);
        $contactLog->setNextAction($nextAction);
        $contactLog->setNextActionAt($nextActionAt);

        $this->contactLogResource->save($contactLog);

        return $contactLog;
    }

    /**
     * @return string[]
     */
    public function getAllowedContactTypes(): array
    {
        return self::ALLOWED_CONTACT_TYPES;
    }

    private function normalizeDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new LocalizedException(__('Data da próxima ação inválida.'));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
