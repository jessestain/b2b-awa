<?php

declare(strict_types=1);

namespace GrupoAwamotos\SmartSuggestions\Model\Whatsapp;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\SmartSuggestions\Helper\Config;
use Magento\Framework\HTTP\Client\Curl;
use GrupoAwamotos\SmartSuggestions\Model\WhatsappQueueFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * WhatsApp Sender Service
 */
class Sender implements WhatsappSenderInterface
{
    private Config $config;
    private Curl $curl;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private WhatsappQueueFactory $queueFactory;

    public function __construct(
        Config $config,
        Curl $curl,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        WhatsappQueueFactory $queueFactory
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->queueFactory = $queueFactory;
    }

    /**
     * @inheritDoc
     */
    public function sendSuggestion(string $phoneNumber, array $suggestionData): array
    {
        if (!$this->config->isWhatsappEnabled()) {
            return [
                'success' => false,
                'message' => 'WhatsApp integration is disabled'
            ];
        }

        $message = $this->formatSuggestionMessage($suggestionData);
        return $this->sendMessage($phoneNumber, $message);
    }

    /**
     * @inheritDoc
     */
    public function sendMessage(string $phoneNumber, string $message): array
    {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);

        if (empty($phoneNumber)) {
            return [
                'success' => false,
                'message' => 'Invalid phone number'
            ];
        }
        $provider = $this->config->getWhatsappProvider();

        // Set reasonable timeouts for API calls
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 5);
        $this->curl->setTimeout(10);

        try {
            switch ($provider) {
                case 'meta':
                    return $this->sendViaMeta($phoneNumber, $message);
                case 'twilio':
                    return $this->sendViaTwilio($phoneNumber, $message);
                case 'evolution':
                    return $this->sendViaEvolution($phoneNumber, $message);
                case 'zapi':
                    return $this->sendViaZApi($phoneNumber, $message);
                case 'custom':
                    return $this->sendViaCustomApi($phoneNumber, $message);
                default:
                    return [
                        'success' => false,
                        'message' => 'Unknown WhatsApp provider: ' . $provider
                    ];
            }
        } catch (\Exception $e) {
            $this->logger->error('WhatsApp send error: ' . $e->getMessage(), [
                'phone' => $phoneNumber,
                'provider' => $provider
            ]);

            return [
                'success' => false,
                'message' => 'Error sending message: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): array
    {
        $provider = $this->config->getWhatsappProvider();

        try {
            switch ($provider) {
                case 'meta':
                    return $this->testMetaConnection();
                case 'twilio':
                    return $this->testTwilioConnection();
                case 'evolution':
                    return $this->testEvolutionConnection();
                case 'zapi':
                    return $this->testZApiConnection();
                default:
                    return [
                        'success' => false,
                        'message' => 'Unknown provider for connection test'
                    ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function formatSuggestionMessage(array $suggestionData): string
    {
        $template = $this->config->getWhatsappMessageTemplate();

        if (empty($template)) {
            $template = $this->getDefaultTemplate();
        }

        // Extract customer data
        $customerName = $suggestionData['customer']['trade_name']
            ?? $suggestionData['customer']['customer_name']
            ?? 'Cliente';

        // Build products list
        $productsList = $this->buildProductsList($suggestionData);

        // Calculate total
        $totalValue = $this->formatPrice($suggestionData['cart_summary']['total_value'] ?? 0);

        // Get store name
        $storeName = $this->storeManager->getStore()->getName();

        // Replace placeholders
        $message = str_replace(
            ['{{customer_name}}', '{{products_list}}', '{{total_value}}', '{{store_name}}'],
            [$customerName, $productsList, $totalValue, $storeName],
            $template
        );

        return $message;
    }

    /**
     * Send message via Meta Cloud API
     */
    private function sendViaMeta(string $phoneNumber, string $message): array
    {
        $apiUrl = $this->config->getWhatsappApiUrl();
        $phoneNumberId = $this->config->getWhatsappPhoneNumberId();
        $token = $this->config->getWhatsappApiToken();

        if (empty($phoneNumberId) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Meta API credentials not configured'
            ];
        }

        $url = rtrim($apiUrl, '/') . '/' . $phoneNumberId . '/messages';

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phoneNumber,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message
            ]
        ];

        $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, json_encode($payload));

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300 && isset($response['messages'][0]['id'])) {
            return [
                'success' => true,
                'message' => 'Message sent successfully',
                'message_id' => $response['messages'][0]['id']
            ];
        }

        return [
            'success' => false,
            'message' => $response['error']['message'] ?? 'Unknown error from Meta API',
            'response' => $response
        ];
    }

    /**
     * Send message via Twilio
     */
    private function sendViaTwilio(string $phoneNumber, string $message): array
    {
        $sid = $this->config->getTwilioSid();
        $token = $this->config->getWhatsappApiToken();
        $from = $this->config->getTwilioFrom();

        if (empty($sid) || empty($token) || empty($from)) {
            return [
                'success' => false,
                'message' => 'Twilio credentials not configured'
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $payload = [
            'From' => $from,
            'To' => 'whatsapp:+' . $phoneNumber,
            'Body' => $message
        ];

        $this->curl->setCredentials($sid, $token);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->post($url, http_build_query($payload));

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300 && isset($response['sid'])) {
            return [
                'success' => true,
                'message' => 'Message sent successfully via Twilio',
                'message_id' => $response['sid']
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Unknown error from Twilio',
            'response' => $response
        ];
    }

    /**
     * Send message via Evolution API
     */
    private function sendViaEvolution(string $phoneNumber, string $message): array
    {
        $apiUrl = $this->config->getWhatsappApiUrl();
        $token = $this->config->getWhatsappApiToken();

        if (empty($apiUrl) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Evolution API credentials not configured'
            ];
        }

        $instance = $this->config->getEvolutionInstance();
        $url = rtrim($apiUrl, '/') . '/message/sendText/' . $instance;

        $payload = [
            'number' => $phoneNumber,
            'text' => $message
        ];

        $this->curl->addHeader('apikey', $token);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, json_encode($payload));

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'message' => 'Message sent successfully via Evolution API',
                'message_id' => $response['key']['id'] ?? null
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Unknown error from Evolution API',
            'response' => $response
        ];
    }

    /**
     * Send message via custom API
     */
    private function sendViaCustomApi(string $phoneNumber, string $message): array
    {
        $apiUrl = $this->config->getWhatsappApiUrl();
        $token = $this->config->getWhatsappApiToken();

        if (empty($apiUrl)) {
            return [
                'success' => false,
                'message' => 'Custom API URL not configured'
            ];
        }

        $payload = [
            'phone' => $phoneNumber,
            'message' => $message
        ];

        if ($token) {
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        }
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($apiUrl, json_encode($payload));

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'message' => 'Message sent successfully',
                'response' => $response
            ];
        }

        return [
            'success' => false,
            'message' => 'Custom API error',
            'response' => $response
        ];
    }

    /**
     * Test Meta API connection
     */
    private function testMetaConnection(): array
    {
        $apiUrl = $this->config->getWhatsappApiUrl();
        $phoneNumberId = $this->config->getWhatsappPhoneNumberId();
        $token = $this->config->getWhatsappApiToken();

        if (empty($phoneNumberId) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Meta API credentials not configured'
            ];
        }

        $url = rtrim($apiUrl, '/') . '/' . $phoneNumberId;

        $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        $this->curl->get($url);

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300 && isset($response['id'])) {
            return [
                'success' => true,
                'message' => 'Meta API connection successful',
                'phone_number' => $response['display_phone_number'] ?? 'N/A'
            ];
        }

        return [
            'success' => false,
            'message' => $response['error']['message'] ?? 'Connection test failed'
        ];
    }

    /**
     * Test Twilio connection
     */
    private function testTwilioConnection(): array
    {
        $sid = $this->config->getTwilioSid();
        $token = $this->config->getWhatsappApiToken();

        if (empty($sid) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Twilio credentials not configured'
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}.json";

        $this->curl->setCredentials($sid, $token);
        $this->curl->get($url);

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300 && isset($response['sid'])) {
            return [
                'success' => true,
                'message' => 'Twilio connection successful',
                'account_name' => $response['friendly_name'] ?? 'N/A'
            ];
        }

        return [
            'success' => false,
            'message' => $response['message'] ?? 'Connection test failed'
        ];
    }

    /**
     * Test Evolution API connection
     */
    private function testEvolutionConnection(): array
    {
        $apiUrl = $this->config->getWhatsappApiUrl();
        $token = $this->config->getWhatsappApiToken();

        if (empty($apiUrl) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Evolution API credentials not configured'
            ];
        }

        $url = rtrim($apiUrl, '/') . '/instance/connectionState/awamotos';

        $this->curl->addHeader('apikey', $token);
        $this->curl->get($url);

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'message' => 'Evolution API connection successful',
                'state' => $response['state'] ?? 'unknown'
            ];
        }

        return [
            'success' => false,
            'message' => 'Connection test failed'
        ];
    }

    /**
     * Normalize phone number to international format
     */
    private function normalizePhoneNumber(string $phone): string
    {
        $phone = trim($phone);

        // IDs de GRUPO do WhatsApp (ex.: 120363...-group / 1203...@g.us): preservar.
        if (str_contains($phone, '-group') || str_contains($phone, '@g.us')) {
            return $phone;
        }

        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Brazilian number without country code
        if (strlen($phone) === 11 || strlen($phone) === 10) {
            $phone = '55' . $phone;
        }

        return $phone;
    }

    /**
     * Build products list for message
     */
    private function buildProductsList(array $suggestionData): string
    {
        $lines = [];

        // Repurchase items
        if (!empty($suggestionData['suggestions']['repurchase'])) {
            $lines[] = "*Reposição:*";
            foreach (array_slice($suggestionData['suggestions']['repurchase'], 0, 5) as $item) {
                $lines[] = sprintf(
                    "• %s - %d un - %s",
                    $item['sku'],
                    $item['suggested_qty'],
                    $this->formatPrice($item['suggested_value'])
                );
            }
            $lines[] = "";
        }

        // Cross-sell items
        if (!empty($suggestionData['suggestions']['cross_sell'])) {
            $lines[] = "*Sugestões:*";
            foreach (array_slice($suggestionData['suggestions']['cross_sell'], 0, 3) as $item) {
                $lines[] = sprintf(
                    "• %s - %s",
                    $item['sku'],
                    $this->formatPrice($item['unit_price'])
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format price in Brazilian format
     */
    private function formatPrice(float $price): string
    {
        return 'R$ ' . number_format($price, 2, ',', '.');
    }

    /**
     * Get default message template
     */
    private function getDefaultTemplate(): string
    {
        return "Olá {{customer_name}}!\n\n" .
            "Preparamos uma seleção especial de produtos para você:\n\n" .
            "{{products_list}}\n\n" .
            "Total estimado: *{{total_value}}*\n\n" .
            "Entre em contato para fazer seu pedido!\n\n" .
            "_{{store_name}}_";
    }

    /**
     * Send message via Z-API
     */
    private function sendViaZApi(string $phoneNumber, string $message): array
    {
        $instanceId = $this->config->getZApiInstanceId();
        $token = $this->config->getZApiToken();
        $clientToken = $this->config->getZApiClientToken();

        if (empty($instanceId) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Z-API credentials not configured'
            ];
        }

        $url = sprintf(
            'https://api.z-api.io/instances/%s/token/%s/send-text',
            $instanceId,
            $token
        );

        $payload = [
            'phone' => $phoneNumber,
            'message' => $message
        ];

        if ($clientToken) {
            $this->curl->addHeader('Client-Token', $clientToken);
        }
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, json_encode($payload));

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'message' => 'Message sent via Z-API',
                'message_id' => $response['zaapId'] ?? null
            ];
        }

        return [
            'success' => false,
            'message' => $response['value'] ?? 'Unknown Z-API error',
            'response' => $response
        ];
    }

    /**
     * Test Z-API connection
     */
    private function testZApiConnection(): array
    {
        $instanceId = $this->config->getZApiInstanceId();
        $token = $this->config->getZApiToken();
        $clientToken = $this->config->getZApiClientToken();

        if (empty($instanceId) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Z-API credentials not configured'
            ];
        }

        $url = sprintf(
            'https://api.z-api.io/instances/%s/token/%s/status',
            $instanceId,
            $token
        );

        if ($clientToken) {
            $this->curl->addHeader('Client-Token', $clientToken);
        }
        $this->curl->get($url);

        $response = json_decode($this->curl->getBody(), true);
        $status = $this->curl->getStatus();

        if ($status >= 200 && $status < 300 && ($response['connected'] ?? false)) {
            return [
                'success' => true,
                'message' => 'Z-API connected',
                'state' => 'connected'
            ];
        }

        return [
            'success' => false,
            'message' => $response['value'] ?? 'Z-API not connected or not configured'
        ];
    }

    /**
     * @inheritDoc
     */
    public function queueMessage(string $phoneNumber, string $message, int $priority = 5): bool
    {
        try {
            $queue = $this->queueFactory->create();
            $queue->setPhoneNumber($phoneNumber);
            $queue->setMessageContent($message);
            $queue->setStatus($queue::STATUS_PENDING);
            $queue->setPriority($priority);
            $queue->save();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('WhatsApp queue error: ' . $e->getMessage());
            return false;
        }
    }
}
