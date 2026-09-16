<?php

declare(strict_types=1);

namespace GrupoAwamotos\ProductIntelligence\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class EmailNotifier extends AbstractHelper
{
    protected $transportBuilder;
    protected $inlineTranslation;
    protected $storeManager;
    protected $emulation;
    protected $customerRepository;
    protected $productRepository;
    protected $resource;
    protected $logger;
    protected $appState;

    public function __construct(
        Context $context,
        TransportBuilder $transportBuilder,
        StateInterface $inlineTranslation,
        StoreManagerInterface $storeManager,
        Emulation $emulation,
        CustomerRepositoryInterface $customerRepository,
        ProductRepositoryInterface $productRepository,
        ResourceConnection $resource,
        LoggerInterface $logger,
        AppState $appState
    ) {
        parent::__construct($context);
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->storeManager = $storeManager;
        $this->emulation = $emulation;
        $this->customerRepository = $customerRepository;
        $this->productRepository = $productRepository;
        $this->resource = $resource;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    /**
     * Enviar alerta de oportunidades de Churn
     *
     * @param array $rows Raw recommendation rows from DB
     * @return bool
     */
    public function sendChurnAlert(array $rows): bool
    {
        return $this->sendAlert($rows, 'rexisml_churn_alert', 'churn');
    }

    /**
     * Enviar alerta de oportunidades de Cross-sell
     *
     * @param array $rows Raw recommendation rows from DB
     * @return bool
     */
    public function sendCrosssellAlert(array $rows): bool
    {
        return $this->sendAlert($rows, 'rexisml_crosssell_alert', 'crosssell');
    }

    /**
     * Send alert email with opportunity data
     */
    private function sendAlert(array $rows, string $templateId, string $type): bool
    {
        if (empty($rows)) {
            return false;
        }

        // Emulate frontend area for cron context — required for template resolution
        return $this->appState->emulateAreaCode(
            \Magento\Framework\App\Area::AREA_FRONTEND,
            function () use ($rows, $templateId, $type) {
                return $this->doSendAlert($rows, $templateId, $type);
            }
        );
    }

    /**
     * Internal email sending logic, must run within frontend area context
     */
    private function doSendAlert(array $rows, string $templateId, string $type): bool
    {
        try {
            $this->inlineTranslation->suspend();

            $opportunities = [];
            foreach ($rows as $row) {
                $erpCode = $row['identificador_cliente'] ?? '';
                $productCode = $row['identificador_produto'] ?? '';

                // Resolve customer name via ERP mapping
                $customerInfo = $this->resolveCustomerInfo($erpCode);
                $productInfo = $this->resolveProductInfo($productCode);

                $score = round(((float)($row['pred'] ?? 0)) * 100, 1);
                $predictedValue = number_format((float)($row['previsao_gasto_round_up'] ?? 0), 2, ',', '.');

                $opportunities[] = [
                    'customer_name' => $customerInfo['name'],
                    'customer_email' => $customerInfo['email'],
                    'product_name' => $productInfo['name'],
                    'product_sku' => $productCode,
                    'score' => $score,
                    'predicted_value' => $predictedValue,
                    'recency_days' => (int)($row['recencia'] ?? 0),
                    'lift' => round((float)($row['lift'] ?? 0), 2),
                    'tipo' => $type,
                ];
            }

            if (empty($opportunities)) {
                return false;
            }

            $configPath = $type === 'churn'
                ? 'rexisml/alerts/churn_email_recipients'
                : 'rexisml/alerts/crosssell_email_recipients';

            $emailTo = $this->scopeConfig->getValue(
                $configPath,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            if (!$emailTo) {
                $emailTo = 'comercial@grupoawamotos.com.br';
            }

            $totalValue = 0;
            foreach ($opportunities as $opp) {
                $totalValue += (float)str_replace(['.', ','], ['', '.'], $opp['predicted_value']);
            }

            $opportunitiesHtml = $type === 'churn'
                ? $this->buildChurnRows($opportunities)
                : $this->buildCrossSellRows($opportunities);

            $templateVars = [
                'opportunities_html' => $opportunitiesHtml,
                'total_value' => number_format($totalValue, 2, ',', '.'),
                'alert_date' => date('d/m/Y H:i'),
                'type_label' => $type === 'churn' ? 'Churn (Reativacao)' : 'Cross-sell',
                'count' => (string) count($opportunities),
            ];

            $storeId = (int) $this->storeManager->getDefaultStoreView()->getId();

            $this->emulation->startEnvironmentEmulation(
                $storeId,
                \Magento\Framework\App\Area::AREA_FRONTEND,
                true
            );

            try {
                $transport = $this->transportBuilder
                    ->setTemplateIdentifier($templateId)
                    ->setTemplateOptions([
                        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                        'store' => $storeId
                    ])
                    ->setTemplateVars($templateVars)
                    ->setFromByScope('general')
                    ->addTo(explode(',', $emailTo))
                    ->getTransport();

                $transport->sendMessage();
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
            $this->inlineTranslation->resume();

            $this->logger->info(sprintf(
                '[ProductIntelligence Email] %s alert sent with %d opportunities to %s',
                ucfirst($type),
                count($opportunities),
                $emailTo
            ));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('[ProductIntelligence Email] Error sending ' . $type . ' alert: ' . $e->getMessage());
            $this->inlineTranslation->resume();
            return false;
        }
    }

    /**
     * Resolve ERP customer code to name/email
     * Uses erp_entity_map → Magento customer, with ERP code as fallback
     */
    private function resolveCustomerInfo(string $erpCode): array
    {
        $default = ['name' => 'Cliente ' . $erpCode, 'email' => ''];

        try {
            $connection = $this->resource->getConnection();

            // Try entity map first
            $mapTable = $this->resource->getTableName('grupoawamotos_erp_entity_map');
            $magentoId = $connection->fetchOne(
                $connection->select()
                    ->from($mapTable, 'magento_entity_id')
                    ->where('entity_type = ?', 'customer')
                    ->where('erp_code = ?', $erpCode)
                    ->limit(1)
            );

            if ($magentoId) {
                $customer = $this->customerRepository->getById((int)$magentoId);
                return [
                    'name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                    'email' => $customer->getEmail(),
                ];
            }

            // Try loading directly if erpCode is numeric (might be Magento ID)
            if (is_numeric($erpCode)) {
                try {
                    $customer = $this->customerRepository->getById((int)$erpCode);
                    return [
                        'name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                        'email' => $customer->getEmail(),
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('[EmailNotifier::resolveCustomerInfo] Exceção ignorada: ' . $e->getMessage());
                    // Not a Magento ID
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('[ProductIntelligence Email] Could not resolve customer ' . $erpCode . ': ' . $e->getMessage());
        }

        return $default;
    }

    /**
     * Resolve product code to name
     */
    private function resolveProductInfo(string $productCode): array
    {
        try {
            $product = $this->productRepository->get($productCode);
            return ['name' => $product->getName()];
        } catch (\Exception $e) {
            return ['name' => $productCode];
        }
    }

    /**
     * Gera HTML das linhas de oportunidades de churn para o email.
     * Evita passar array PHP ao template filter (Array to string conversion).
     */
    private function buildChurnRows(array $opportunities): string
    {
        $html = '';
        foreach ($opportunities as $opp) {
            $customerName  = htmlspecialchars((string)($opp['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $customerEmail = htmlspecialchars((string)($opp['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8');
            $productName   = htmlspecialchars((string)($opp['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $productSku    = htmlspecialchars((string)($opp['product_sku'] ?? ''), ENT_QUOTES, 'UTF-8');
            $score         = number_format((float)($opp['score'] ?? 0), 1);
            $predictedValue = htmlspecialchars((string)($opp['predicted_value'] ?? '0,00'), ENT_QUOTES, 'UTF-8');
            $recencyDays   = (int)($opp['recency_days'] ?? 0);

            $scoreBg    = (float)($opp['score'] ?? 0) >= 90 ? '#fee2e2' : '#fef3c7';
            $scoreColor = (float)($opp['score'] ?? 0) >= 90 ? '#991b1b' : '#92400e';
            $recencyColor = $recencyDays > 90 ? '#dc2626' : '#d97706';

            $html .= '<tr style="border-bottom: 1px solid #e5e7eb;">';
            $html .= '<td style="padding: 12px; border: 1px solid #e5e7eb;">';
            $html .= '<strong style="display: block; margin-bottom: 4px;">' . $customerName . '</strong>';
            $html .= '<span style="font-size: 12px; color: #6b7280;">' . $customerEmail . '</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; border: 1px solid #e5e7eb;">';
            $html .= '<strong style="display: block; margin-bottom: 4px;">' . $productName . '</strong>';
            $html .= '<span style="font-size: 12px; color: #6b7280;">SKU: ' . $productSku . '</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; text-align: center; border: 1px solid #e5e7eb;">';
            $html .= '<span style="display: inline-block; padding: 4px 12px; background: ' . $scoreBg . '; color: ' . $scoreColor . '; border-radius: 12px; font-weight: 600; font-size: 13px;">' . $score . '%</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; text-align: right; font-weight: 600; border: 1px solid #e5e7eb;">R$ ' . $predictedValue . '</td>';
            $html .= '<td style="padding: 12px; text-align: center; border: 1px solid #e5e7eb;">';
            $html .= '<span style="color: ' . $recencyColor . '; font-weight: 600;">' . $recencyDays . ' dias</span>';
            $html .= '</td>';
            $html .= '</tr>';
        }
        return $html;
    }

    /**
     * Gera HTML das linhas de oportunidades de cross-sell para o email.
     * Evita passar array PHP ao template filter (Array to string conversion).
     */
    private function buildCrossSellRows(array $opportunities): string
    {
        $html = '';
        foreach ($opportunities as $opp) {
            $customerName  = htmlspecialchars((string)($opp['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $customerEmail = htmlspecialchars((string)($opp['customer_email'] ?? ''), ENT_QUOTES, 'UTF-8');
            $productName   = htmlspecialchars((string)($opp['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $productSku    = htmlspecialchars((string)($opp['product_sku'] ?? ''), ENT_QUOTES, 'UTF-8');
            $score         = number_format((float)($opp['score'] ?? 0), 1);
            $predictedValue = htmlspecialchars((string)($opp['predicted_value'] ?? '0,00'), ENT_QUOTES, 'UTF-8');

            $scoreBg    = (float)($opp['score'] ?? 0) >= 70 ? '#d1fae5' : '#e0f2fe';
            $scoreColor = (float)($opp['score'] ?? 0) >= 70 ? '#065f46' : '#075985';

            $html .= '<tr style="border-bottom: 1px solid #e5e7eb;">';
            $html .= '<td style="padding: 12px; border: 1px solid #e5e7eb;">';
            $html .= '<strong style="display: block; margin-bottom: 4px;">' . $customerName . '</strong>';
            $html .= '<span style="font-size: 12px; color: #6b7280;">' . $customerEmail . '</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; border: 1px solid #e5e7eb;">';
            $html .= '<strong style="display: block; margin-bottom: 4px;">' . $productName . '</strong>';
            $html .= '<span style="font-size: 12px; color: #6b7280;">SKU: ' . $productSku . '</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; text-align: center; border: 1px solid #e5e7eb;">';
            $html .= '<span style="display: inline-block; padding: 4px 12px; background: ' . $scoreBg . '; color: ' . $scoreColor . '; border-radius: 12px; font-weight: 600; font-size: 13px;">' . $score . '%</span>';
            $html .= '</td>';
            $html .= '<td style="padding: 12px; text-align: right; font-weight: 600; border: 1px solid #e5e7eb;">R$ ' . $predictedValue . '</td>';
            $html .= '</tr>';
        }
        return $html;
    }
}
