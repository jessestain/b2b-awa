<?php

declare(strict_types=1);

namespace GrupoAwamotos\WhatsAppCommerce\Model;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\WhatsAppCommerce\Helper\Config;
use GrupoAwamotos\WhatsAppCommerce\Model\AppBuilderDispatcher;
use Psr\Log\LoggerInterface;

/**
 * WhatsApp message sender — delegates to SmartSuggestions WhatsApp sender.
 *
 * This class wraps the existing WhatsappSenderInterface to avoid duplicating
 * the multi-provider logic (Evolution API, Meta, Twilio, Custom).
 */
class MessageSender
{
    public function __construct(
        private readonly WhatsappSenderInterface $whatsappSender,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly AppBuilderDispatcher $appBuilderDispatcher,
    ) {
    }

    /**
     * Send a text message via WhatsApp
     *
     * @param string $phone Phone with country code (e.g. 5516991234567)
     * @param string $message Message text
     * @return bool Success status
     */
    public function send(string $phone, string $message): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        try {
            $result = $this->whatsappSender->sendMessage($phone, $message);
            $success = $result['success'] ?? false;

            if (!$success) {
                $this->logger->warning('WhatsApp message failed', [
                    'phone' => $this->maskPhone($phone),
                    'error' => $result['message'] ?? 'unknown',
                ]);
            }

            return (bool) $success;
        } catch (\Exception $e) {
            $this->logger->error('MessageSender::send error', [
                'phone' => $this->maskPhone($phone),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send order notification
     *
     * @param string $phone Phone number
     * @param string $orderId Increment ID (e.g. "000000042")
     * @param string $event Event type (placed, paid, shipped, refunded)
     * @param array $data Additional data (tracking code, etc.)
     * @return bool
     */
    public function sendOrderNotification(string $phone, string $orderId, string $event, array $data = []): bool
    {
        if ($this->config->isAppBuilderOffloadEnabled()
            && $this->appBuilderDispatcher->dispatch($phone, $orderId, $event, $data)
        ) {
            // Job accepted by the Adobe I/O Runtime action — the actual
            // WhatsApp API call now happens off this request entirely.
            return true;
        }

        $total = $data['total'] ?? '';
        $tracking = $data['tracking'] ?? 'em breve';

        $messages = [
            'placed' => "✅ Pedido #{$orderId} confirmado! Valor: {$total}",
            'paid' => "💰 Pagamento do pedido #{$orderId} confirmado!",
            'shipped' => "🚚 Pedido #{$orderId} enviado! Rastreio: {$tracking}",
            'refunded' => "🔄 Reembolso do pedido #{$orderId} processado",
        ];

        $message = $messages[$event] ?? "📦 Atualização do pedido #{$orderId}";

        return $this->send($phone, $message);
    }

    /**
     * Test connection to WhatsApp API
     *
     * @return array Result with success status
     */
    public function testConnection(): array
    {
        return $this->whatsappSender->testConnection();
    }

    /**
     * Mask phone for logging (LGPD compliance)
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) <= 4) {
            return '****';
        }
        return substr($digits, 0, 4) . str_repeat('*', strlen($digits) - 4);
    }
}
