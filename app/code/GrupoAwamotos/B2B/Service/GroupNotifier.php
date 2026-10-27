<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Service;

use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Envia notificacoes operacionais para o GRUPO de WhatsApp configurado em
 * grupoawamotos_b2b/whatsapp/notify_group_id (cadastros, buscas, carrinho,
 * login e digest). No-op quando o grupo nao esta configurado.
 * O ID do grupo e o retornado pela API Z-API (formato "...-group").
 */
class GroupNotifier
{
    private const CONFIG_GROUP_ID = 'grupoawamotos_b2b/whatsapp/notify_group_id';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WhatsAppPublisher $whatsAppPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->scopeConfig->getValue(
            self::CONFIG_GROUP_ID,
            ScopeInterface::SCOPE_STORE
        )) !== '';
    }

    public function send(string $message): bool
    {
        try {
            $groupId = trim((string) $this->scopeConfig->getValue(
                self::CONFIG_GROUP_ID,
                ScopeInterface::SCOPE_STORE
            ));
            if ($groupId === '' || $message === '') {
                return false;
            }
            $this->whatsAppPublisher->publishText($groupId, $message);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Grupo Notificacoes] ' . $e->getMessage());

            return false;
        }
    }
}
