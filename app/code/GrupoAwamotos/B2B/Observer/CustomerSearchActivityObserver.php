<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Service\GroupNotifier;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Registra a busca do cliente logado (host para o digest diario) e, quando a busca
 * NAO retorna resultados, avisa o grupo operacional e a vendedora atribuida.
 * Dedupe por termo/dia + cap diario por cliente. Nunca afeta a busca.
 */
class CustomerSearchActivityObserver implements ObserverInterface
{
    private const CONFIG_REALTIME = 'grupoawamotos_b2b/whatsapp/notify_attendant_activity_realtime';
    private const CACHE_PREFIX = 'b2b_search_noresult_';
    private const DAILY_CAP = 5;
    private const DAILY_CAP_KEY = 'b2b_search_noresult_count_';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RequestInterface $request,
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AttendantManager $attendantManager,
        private readonly WhatsAppPublisher $whatsAppPublisher,
        private readonly CacheInterface $cache,
        private readonly LayerResolver $layerResolver,
        private readonly GroupNotifier $groupNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                return;
            }

            $text = trim((string) $this->request->getParam('q', ''));
            if ($text === '') {
                return;
            }
            $clean = preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text;
            $clean = trim(mb_substr($clean, 0, 255));
            if ($clean === '') {
                return;
            }

            $numResults = $this->countResults();

            $connection = $this->resource->getConnection();
            $connection->insert(
                $this->resource->getTableName('grupoawamotos_b2b_customer_search_activity'),
                [
                    'customer_id' => $customerId,
                    'query_text' => $clean,
                    'num_results' => $numResults === null ? 0 : $numResults,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]
            );

            // Alerta em tempo real somente para busca SEM resultado (count conhecido = 0).
            if ($numResults !== 0) {
                return;
            }
            if (!$this->scopeConfig->isSetFlag(self::CONFIG_REALTIME, ScopeInterface::SCOPE_STORE)) {
                return;
            }

            $day = date('Ymd');
            $dedupeKey = self::CACHE_PREFIX . $customerId . '_' . md5(mb_strtolower($clean)) . '_' . $day;
            if ($this->cache->load($dedupeKey)) {
                return;
            }

            $capKey = self::DAILY_CAP_KEY . $customerId . '_' . $day;
            $sentToday = (int) ($this->cache->load($capKey) ?: 0);
            if ($sentToday >= self::DAILY_CAP) {
                return;
            }

            $firstName = (string) $this->customerSession->getCustomer()->getFirstname();
            $message = sprintf(
                "🔍 *%s* buscou por \"%s\" e não encontrou nada na loja. Pode ser uma oportunidade de oferta/alternativa! (aviso automático)",
                $firstName !== '' ? $firstName : 'Cliente',
                $clean
            );

            // Grupo operacional (recebe sempre, mesmo sem vendedora atribuida)
            $this->groupNotifier->send($message);

            // Vendedora atribuida (individual)
            $attendant = $this->attendantManager->getCustomerAttendant($customerId);
            if ($attendant && !empty($attendant['whatsapp'])) {
                $this->whatsAppPublisher->publishText((string) $attendant['whatsapp'], $message);
            }

            $this->cache->save('1', $dedupeKey, [], 86400);
            $this->cache->save((string) ($sentToday + 1), $capKey, [], 86400);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Search→Vendedora] ' . $e->getMessage());
        }
    }

    /**
     * Conta os resultados da busca corrente (layer criado pelo controller).
     * Retorna null quando nao for possivel medir (ex.: contexto CLI) — nesse caso
     * a atividade e registrada mas nenhum alerta "sem resultado" e disparado.
     */
    private function countResults(): ?int
    {
        try {
            return (int) $this->layerResolver->get()->getProductCollection()->getSize();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
