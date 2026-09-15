<?php

/**
 * Customer Approval Interface
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Api;

interface CustomerApprovalInterface
{
    /**
     * Set customer as pending approval
     *
     * @param int $customerId
     * @return bool
     */
    public function setCustomerPending(int $customerId): bool;

    /**
     * Approve customer
     *
     * @param int $customerId
     * @param int|null $adminUserId
     * @param string|null $comment
     * @return bool
     */
    public function approveCustomer(int $customerId, ?int $adminUserId = null, ?string $comment = null): bool;

    /**
     * Reject customer
     *
     * @param int $customerId
     * @param int|null $adminUserId
     * @param string|null $reason
     * @return bool
     */
    public function rejectCustomer(int $customerId, ?int $adminUserId = null, ?string $reason = null): bool;

    /**
     * Suspend customer
     *
     * @param int $customerId
     * @param int|null $adminUserId
     * @param string|null $reason
     * @return bool
     */
    public function suspendCustomer(int $customerId, ?int $adminUserId = null, ?string $reason = null): bool;

    /**
     * Solicita revisão / informações adicionais e move o cadastro para data_review.
     *
     * @param int $customerId
     * @param int|null $adminUserId
     * @param string|null $message
     * @return bool
     */
    public function requestDataReview(int $customerId, ?int $adminUserId = null, ?string $message = null): bool;

    /**
     * Get customer approval status
     *
     * @param int $customerId
     * @return string|null
     */
    public function getApprovalStatus(int $customerId): ?string;

    /**
     * Check if customer is approved
     *
     * @param int $customerId
     * @return bool
     */
    public function isApproved(int $customerId): bool;

    /**
     * Notify admin about new customer registration
     *
     * @param int $customerId
     * @return bool
     */
    public function notifyAdminNewCustomer(int $customerId): bool;

    /**
     * Send approval notification to customer
     *
     * @param int $customerId
     * @return bool
     */
    public function sendApprovalEmail(int $customerId): bool;

    /**
     * Send rejection notification to customer
     *
     * @param int $customerId
     * @param string|null $reason
     * @return bool
     */
    public function sendRejectionEmail(int $customerId, ?string $reason = null): bool;
}
