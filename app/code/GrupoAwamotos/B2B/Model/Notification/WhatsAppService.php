<?php

/**
 * Serviço de notificações B2B via WhatsApp
 *
 * Contém os templates e regras de negócio B2B.
 * O envio real é delegado ao SmartSuggestions\WhatsappSenderInterface,
 * eliminando duplicação de providers (Z-API, Evolution, Twilio, Meta).
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Notification;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class WhatsAppService
{
    private const CONFIG_PATH_ENABLED = 'grupoawamotos_b2b/whatsapp/enabled';
    private const CONFIG_PATH_DEFAULT_NUMBER = 'grupoawamotos_b2b/whatsapp/default_number';

    public function __construct(
        private readonly WhatsappSenderInterface $whatsappSender,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Envia mensagem de texto simples via sender unificado.
     */
    public function sendText(string $phone, string $message): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'WhatsApp desabilitado'];
        }

        return $this->whatsappSender->sendMessage($this->formatPhone($phone), $message);
    }

    /**
     * Envia mensagem com template.
     */
    public function sendTemplate(string $phone, string $templateName, array $params = []): array
    {
        $message = $this->buildMessage($templateName, $params);
        return $this->sendText($phone, $message);
    }

    /**
     * Envia notificação para equipe de atendimento.
     */
    public function notifyTeam(string $message, ?string $department = null): array
    {
        $results = [];
        foreach ($this->getTeamNumbers($department) as $number) {
            $results[] = $this->sendText($number, $message);
        }
        return $results;
    }

    public function notifyNewB2BRegistration(array $customerData): array
    {
        $message = $this->buildMessage('new_registration', $customerData);
        return $this->notifyTeam($message, 'b2b');
    }

    public function notifyNewQuote(array $quoteData): array
    {
        $message = $this->buildMessage('new_quote', $quoteData);
        return $this->notifyTeam($message, 'sales');
    }

    public function notifyNewOrder(array $orderData): array
    {
        $message = $this->buildMessage('new_order', $orderData);
        $teamResult = $this->notifyTeam($message, 'sales');

        $customerResult = [];
        if (!empty($orderData['customer_phone'])) {
            $customerMessage = $this->buildMessage('order_confirmation', $orderData);
            $customerResult = $this->sendText($orderData['customer_phone'], $customerMessage);
        }

        return ['team' => $teamResult, 'customer' => $customerResult];
    }

    public function notifyCustomerApproval(array $customerData): array
    {
        if (empty($customerData['phone'])) {
            return ['success' => false, 'message' => 'Telefone não informado'];
        }
        $message = $this->buildMessage('customer_approved', $customerData);
        return $this->sendText($customerData['phone'], $message);
    }

    public function notifyCustomerRejection(array $customerData): array
    {
        if (empty($customerData['phone'])) {
            return ['success' => false, 'message' => 'Telefone não informado'];
        }
        $message = $this->buildMessage('customer_rejected', $customerData);
        return $this->sendText($customerData['phone'], $message);
    }

    public function notifyQuoteResponse(array $quoteData): array
    {
        if (empty($quoteData['customer_phone'])) {
            return ['success' => false, 'message' => 'Telefone não informado'];
        }
        $message = $this->buildMessage('quote_response', $quoteData);
        return $this->sendText($quoteData['customer_phone'], $message);
    }

    public function notifyOrderStatusUpdate(array $orderData): array
    {
        if (empty($orderData['customer_phone'])) {
            return ['success' => false, 'message' => 'Telefone não informado'];
        }
        $message = $this->buildMessage('order_status', $orderData);
        return $this->sendText($orderData['customer_phone'], $message);
    }

    // ==================== HELPERS ====================

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 11 || strlen($phone) === 10) {
            $phone = '55' . $phone;
        }
        return $phone;
    }

    private function getTeamNumbers(?string $department = null): array
    {
        $path = 'grupoawamotos_b2b/whatsapp/team_numbers';
        if ($department) {
            $deptPath = "grupoawamotos_b2b/whatsapp/team_{$department}_numbers";
            $numbers = $this->scopeConfig->getValue($deptPath, ScopeInterface::SCOPE_STORE);
        }

        if (empty($numbers)) {
            $numbers = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        }

        return array_filter(array_map('trim', explode(',', $numbers ?? '')));
    }

    private function buildMessage(string $type, array $data): string
    {
        $templates = [
            'new_registration' => "🆕 *Novo Cadastro B2B*\n\n" .
                "👤 *Nome:* {customer_name}\n" .
                "🏢 *Empresa:* {razao_social}\n" .
                "📋 *CNPJ:* {cnpj}\n" .
                "📧 *Email:* {email}\n" .
                "📱 *Telefone:* {phone}\n\n" .
                "{status_note}",

            'new_quote' => "📋 *Nova Solicitação de Cotação*\n\n" .
                "👤 *Cliente:* {customer_name}\n" .
                "🏢 *Empresa:* {company}\n" .
                "📦 *Itens:* {items_count}\n" .
                "💰 *Valor estimado:* {estimated_total}\n\n" .
                "🔗 Acesse o painel para responder.",

            'new_order' => "🛒 *Novo Pedido B2B*\n\n" .
                "📦 *Pedido:* #{order_id}\n" .
                "👤 *Cliente:* {customer_name}\n" .
                "🏢 *Empresa:* {company}\n" .
                "💰 *Total:* {total}\n" .
                "📍 *Cidade:* {city}\n\n" .
                "✅ Pedido registrado com sucesso!",

            'order_confirmation' => "✅ *Pedido Confirmado!*\n\n" .
                "Olá {customer_name}!\n\n" .
                "Seu pedido *#{order_id}* foi recebido com sucesso.\n\n" .
                "💰 *Total:* {total}\n" .
                "📦 *Itens:* {items_count}\n\n" .
                "Acompanhe o status do seu pedido em nossa loja.\n\n" .
                "Obrigado pela preferência! 🙏",

            'customer_approved' => "✅ *Cadastro B2B Aprovado!*\n\n" .
                "Olá {customer_name}!\n\n" .
                "Seu cadastro B2B foi *aprovado*! 🎉\n\n" .
                "Agora você tem acesso a:\n" .
                "• Preços exclusivos\n" .
                "• Descontos especiais\n" .
                "• Condições de pagamento diferenciadas\n\n" .
                "Acesse nossa loja e aproveite!",

            'customer_rejected' => "❌ *Cadastro B2B Não Aprovado*\n\n" .
                "Olá {customer_name},\n\n" .
                "Infelizmente seu cadastro B2B não foi aprovado no momento.\n\n" .
                "Se tiver dúvidas, entre em contato conosco.",

            'quote_response' => "📋 *Cotação Respondida!*\n\n" .
                "Olá {customer_name}!\n\n" .
                "Sua cotação *#{quote_id}* foi respondida.\n\n" .
                "💰 *Valor:* {quoted_total}\n" .
                "📅 *Válido até:* {expires_at}\n\n" .
                "Acesse sua conta para ver os detalhes e aprovar.",

            'order_status' => "📦 *Atualização do Pedido*\n\n" .
                "Olá {customer_name}!\n\n" .
                "O pedido *#{order_id}* teve uma atualização:\n\n" .
                "📌 *Status:* {status}\n" .
                "{tracking_info}\n\n" .
                "Acompanhe em nossa loja.",
        ];

        $message = $templates[$type] ?? $templates['new_registration'];

        if ($type === 'new_registration' && trim((string) ($data['status_note'] ?? '')) === '') {
            $data['status_note'] = '⏳ Aguardando aprovação no painel admin.';
        }

        foreach ($data as $key => $value) {
            $message = str_replace("{{$key}}", (string) $value, $message);
        }

        // Remove placeholders não preenchidos
        $message = preg_replace('/\{[a-z_]+\}/', '', $message ?? '');

        return $message;
    }
}
