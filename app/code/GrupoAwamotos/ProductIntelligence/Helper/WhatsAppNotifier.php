<?php

declare(strict_types=1);

namespace GrupoAwamotos\ProductIntelligence\Helper;

use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class WhatsAppNotifier extends AbstractHelper
{
    private const CONFIG_ENABLED    = 'rexisml/whatsapp/enabled';
    private const CONFIG_RECIPIENTS = 'rexisml/whatsapp/recipients';

    public function __construct(
        Context $context,
        private readonly WhatsappSenderInterface $whatsappSender,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function sendCrosssellAlert(array $rows): bool
    {
        if (empty($rows)) {
            return false;
        }

        try {
            $message = "*Product Intelligence - Oportunidades de Cross-sell*\n\n";
            $message .= "*" . count($rows) . " oportunidades detectadas*\n\n";

            $count = 0;
            foreach ($rows as $row) {
                if ($count >= 5) {
                    break;
                }
                $erpCode     = $row['identificador_cliente'] ?? '';
                $productCode = $row['identificador_produto'] ?? '';
                $score       = round(((float)($row['pred'] ?? 0)) * 100, 1);
                $value       = number_format((float)($row['previsao_gasto_round_up'] ?? 0), 2, ',', '.');

                $message .= sprintf(
                    "*%s*\n%s\nR$ %s | Score: %.1f%%\n\n",
                    $this->resolveCustomerName($erpCode),
                    $this->resolveProductName($productCode),
                    $value,
                    $score
                );
                $count++;
            }

            return $this->sendToRecipients($message);
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence WhatsApp] CrossSell: ' . $e->getMessage());
            return false;
        }
    }

    public function sendChurnRecovery(array $rows): bool
    {
        if (empty($rows)) {
            return false;
        }

        try {
            $message    = "*Product Intelligence - Alerta de Churn*\n\n";
            $message   .= "*" . count($rows) . " clientes em risco de churn*\n\n";
            $totalValue = 0;
            $count      = 0;

            foreach ($rows as $row) {
                if ($count >= 5) {
                    break;
                }
                $erpCode = $row['identificador_cliente'] ?? '';
                $score   = round(((float)($row['pred'] ?? 0)) * 100, 1);
                $value   = (float)($row['previsao_gasto_round_up'] ?? 0);
                $recency = (int)($row['recencia'] ?? 0);
                $totalValue += $value;

                $message .= sprintf(
                    "*%s*\nRisco: %.1f%% | Valor: R$ %s | %d dias\n\n",
                    $this->resolveCustomerName($erpCode),
                    $score,
                    number_format($value, 2, ',', '.'),
                    $recency
                );
                $count++;
            }

            $message .= sprintf(
                "*Valor total em risco: R$ %s*\n\n",
                number_format($totalValue, 2, ',', '.')
            );
            $message .= "Acesse o painel Product Intelligence para detalhes.";

            return $this->sendToRecipients($message);
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence WhatsApp] ChurnRecovery: ' . $e->getMessage());
            return false;
        }
    }

    public function sendTestMessage(string $phone): bool
    {
        try {
            $message  = "*Product Intelligence - Teste de WhatsApp*\n\n";
            $message .= "Integração funcionando!\n";
            $message .= "Data/Hora: " . date('d/m/Y H:i:s') . "\n\n";
            $message .= "Este é um teste do sistema de alertas Product Intelligence.";

            $result = $this->whatsappSender->sendMessage($phone, $message);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence WhatsApp] Test: ' . $e->getMessage());
            return false;
        }
    }

    private function sendToRecipients(string $message): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_ENABLED)) {
            return false;
        }

        $recipients = $this->scopeConfig->getValue(self::CONFIG_RECIPIENTS);
        if (!$recipients) {
            $this->logger->warning('[ProductIntelligence WhatsApp] No recipients configured');
            return false;
        }

        $sent  = 0;
        $total = 0;
        foreach (explode(',', $recipients) as $number) {
            $number = trim($number);
            if (!$number) {
                continue;
            }
            $total++;
            $result = $this->whatsappSender->sendMessage($number, $message);
            if ($result['success'] ?? false) {
                $sent++;
            }
        }

        $this->logger->info(sprintf(
            '[ProductIntelligence WhatsApp] Message sent to %d/%d recipients',
            $sent,
            $total
        ));

        return $sent > 0;
    }

    private function resolveCustomerName(string $erpCode): string
    {
        if (empty($erpCode)) {
            return 'Cliente desconhecido';
        }
        try {
            $connection = $this->resource->getConnection();
            $mapTable   = $this->resource->getTableName('grupoawamotos_erp_entity_map');
            $magentoId  = $connection->fetchOne(
                $connection->select()
                    ->from($mapTable, 'magento_entity_id')
                    ->where('entity_type = ?', 'customer')
                    ->where('erp_code = ?', $erpCode)
                    ->limit(1)
            );
            if ($magentoId) {
                $customer = $this->customerRepository->getById((int)$magentoId);
                return $customer->getFirstname() . ' ' . $customer->getLastname();
            }
        } catch (\Exception $e) {
            $this->logger->warning('[WhatsAppNotifier::resolveCustomerName] Exceção ignorada: ' . $e->getMessage());
            // fallback
        }
        return 'Cliente ' . $erpCode;
    }

    private function resolveProductName(string $productCode): string
    {
        if (empty($productCode)) {
            return 'Produto desconhecido';
        }
        try {
            return $this->productRepository->get($productCode)->getName();
        } catch (\Exception $e) {
            return $productCode;
        }
    }
}
