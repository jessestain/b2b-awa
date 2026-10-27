<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Service\GroupNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Copia o aviso de NOVO CADASTRO B2B para o grupo de WhatsApp configurado.
 * Escuta o mesmo evento do CustomerRegistrationNotification, sem alterar
 * o fluxo existente (vendedoras/equipe continuam recebendo normalmente).
 */
class RegistrationGroupNotificationObserver implements ObserverInterface
{
    public function __construct(
        private readonly GroupNotifier $groupNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getCustomer();
            if (!$customer || !$customer->getId()) {
                return;
            }
            if (!$this->groupNotifier->isConfigured()) {
                return;
            }

            $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
            $razaoAttr = $customer->getCustomAttribute('b2b_razao_social');
            $razao = $razaoAttr ? trim((string) $razaoAttr->getValue()) : '';
            $cnpjAttr = $customer->getCustomAttribute('b2b_cnpj');
            $cnpj = $cnpjAttr ? trim((string) $cnpjAttr->getValue()) : '';
            $phoneAttr = $customer->getCustomAttribute('b2b_phone');
            $phone = $phoneAttr ? trim((string) $phoneAttr->getValue()) : '';

            $lines = ['🆕 *Novo cadastro B2B*'];
            if ($name !== '') {
                $lines[] = '👤 ' . $name;
            }
            if ($razao !== '') {
                $lines[] = '🏢 ' . $razao;
            }
            if ($cnpj !== '') {
                $lines[] = '🧾 CNPJ: ' . $cnpj;
            }
            if ($phone !== '') {
                $lines[] = '📱 ' . $phone;
            }

            $this->groupNotifier->send(implode("\n", $lines));
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Cadastro→Grupo] ' . $e->getMessage());
        }
    }
}
