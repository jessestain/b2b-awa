<?php

/**
 * Approval Status Source Model
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Customer\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class ApprovalStatus extends AbstractSource
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DATA_REVIEW = 'data_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUSPENDED = 'suspended';

    /** Alias estável para o vocabulário operacional (under_review). */
    public const STATUS_UNDER_REVIEW = self::STATUS_DATA_REVIEW;

    /** Alias estável para solicitação de informações adicionais. */
    public const STATUS_NEEDS_INFORMATION = self::STATUS_DATA_REVIEW;

    /** Alias estável para bloqueio comercial (blocked). */
    public const STATUS_BLOCKED = self::STATUS_SUSPENDED;

    /**
     * Get all options
     *
     * @return array
     */
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::STATUS_PENDING, 'label' => __('Pendente de Aprovação')],
                ['value' => self::STATUS_DATA_REVIEW, 'label' => __('Revisão de Cadastro')],
                ['value' => self::STATUS_APPROVED, 'label' => __('Aprovado')],
                ['value' => self::STATUS_REJECTED, 'label' => __('Rejeitado')],
                ['value' => self::STATUS_SUSPENDED, 'label' => __('Suspenso')],
            ];
        }
        return $this->_options;
    }

    /**
     * Get option text by value
     *
     * @param string $value
     * @return string|bool
     */
    public function getOptionText($value)
    {
        foreach ($this->getAllOptions() as $option) {
            if ($option['value'] == $value) {
                return $option['label'];
            }
        }
        return false;
    }

    /**
     * Check if status allows purchase
     *
     * @param string $status
     * @return bool
     */
    public static function canPurchase(string $status): bool
    {
        return $status === self::STATUS_APPROVED;
    }

    /**
     * Check if status allows viewing prices
     *
     * @param string $status
     * @param bool $showPricePending
     * @return bool
     */
    public static function canViewPrices(string $status, bool $showPricePending = false): bool
    {
        if ($status === self::STATUS_APPROVED) {
            return true;
        }

        if ($status === self::STATUS_PENDING && $showPricePending) {
            return true;
        }

        return false;
    }
}
