<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Revalidação server-side por SKU no momento da adição ao carrinho.
 *
 * Esconder no HTML não é autorização: este validador repete, no caminho de
 * ESCRITA, as regras de catálogo já usadas na vitrine:
 * - tabela grupoawamotos_b2b_exclusive_product (produto OEM exclusivo de UM cliente);
 * - atributo b2b_exclusive (produto exclusivo B2B);
 * - atributo b2b_customer_groups (restrição por grupo — sem valores em produção hoje;
 *   fail-closed quando preenchido: regra documentada em docs/PENDENCIAS.md).
 *
 * Fase 2: isVisibleInCatalog() expõe as MESMAS regras de catálogo sem a
 * exigência de isSaleable (reuso do toggle de favoritos, que permite favoritar
 * item sem estoque); validate() = isVisibleInCatalog() + isSaleable.
 *
 * Nunca altera estado; apenas lê e decide.
 */
class ProductAccessValidator
{
    private const EXCLUSIVE_TABLE = 'grupoawamotos_b2b_exclusive_product';
    private const ALL_GROUPS_LABEL = 'todos os grupos b2b';

    /**
     * Inicializa o validador com repositório, conexão e sessão do cliente.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ResourceConnection $resourceConnection
     * @param CustomerSession $customerSession
     * @param B2BHelper $b2bHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerSession $customerSession,
        private readonly B2BHelper $b2bHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Revalida o SKU no caminho de escrita, incluindo disponibilidade.
     *
     * @param string $sku
     * @param int $storeId
     * @return array{allowed:bool,message:string,product:\Magento\Catalog\Api\Data\ProductInterface|null}
     */
    public function validate(string $sku, int $storeId): array
    {
        $result = $this->isVisibleInCatalog($sku, $storeId);

        if (!$result['allowed'] || $result['product'] === null) {
            return $result;
        }

        if (!$result['product']->isSaleable()) {
            return $this->deny((string) __('Produto indisponível.'));
        }

        return $result;
    }

    /**
     * Verifica se o produto está visível no catálogo, sem exigir isSaleable.
     *
     * Favoritar um item sem estoque é legítimo. As mesmas regras de validate():
     * existe, status enabled, exclusividade OEM (tabela), b2b_exclusive e
     * b2b_customer_groups.
     *
     * @param string $sku
     * @param int $storeId
     * @return array{allowed:bool,message:string,product:\Magento\Catalog\Api\Data\ProductInterface|null}
     */
    public function isVisibleInCatalog(string $sku, int $storeId): array
    {
        try {
            $product = $this->productRepository->get($sku, false, $storeId, true);
        } catch (NoSuchEntityException) {
            return $this->deny((string) __('SKU não encontrado.'));
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Product load failed on catalog check.', [
                'sku' => $sku,
                'exception' => $exception->getMessage(),
            ]);
            return $this->deny((string) __('Não foi possível validar o produto agora.'));
        }

        if ((int) $product->getStatus() !== \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
            return $this->deny((string) __('Produto indisponível.'));
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        if (!$this->isExclusiveOwner((int) $product->getId(), $customerId)) {
            return $this->deny((string) __('Produto não disponível para sua conta.'));
        }

        if ((int) ($product->getData('b2b_exclusive') ?? 0) === 1 && !$this->b2bHelper->isB2BCustomer()) {
            return $this->deny((string) __('Produto exclusivo para clientes B2B aprovados.'));
        }

        if (!$this->isAllowedForCustomerGroup($product, (int) $this->customerSession->getCustomerGroupId())) {
            return $this->deny((string) __('Produto restrito ao seu grupo comercial.'));
        }

        return [
            'allowed' => true,
            'message' => '',
            'product' => $product,
        ];
    }

    /**
     * Produto com linha na tabela de exclusivos só pode ser comprado pelo dono.
     *
     * Produto sem linha é comum a todos.
     *
     * @param int $productId
     * @param int $customerId
     * @return bool
     */
    private function isExclusiveOwner(int $productId, int $customerId): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::EXCLUSIVE_TABLE);

            $owners = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['customer_id'])
                    ->where('product_id = ?', $productId)
            );
        } catch (\Throwable $exception) {
            // Fail-closed: se a regra não pôde ser avaliada, nega.
            $this->logger->warning('[B2B QuickCatalog] Exclusive product rule check failed.', [
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);
            return false;
        }

        if ($owners === []) {
            return true;
        }

        foreach ($owners as $ownerId) {
            if ((int) $ownerId === $customerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Avalia a restrição do atributo b2b_customer_groups (multiselect).
     *
     * Semântica conservadora: vazio = disponível a todos; preenchido = exige o
     * grupo do cliente ou "Todos os Grupos B2B". Produção hoje não tem valores
     * (sonda 2026-10-20), portanto o comportamento só age se o dado existir.
     *
     * @param object $product
     * @param int $customerGroupId
     * @return bool
     */
    private function isAllowedForCustomerGroup(object $product, int $customerGroupId): bool
    {
        $raw = (string) ($product->getData('b2b_customer_groups') ?? '');
        if (trim($raw) === '') {
            return true;
        }

        $groupName = $this->b2bHelper->getB2BGroupName($customerGroupId);
        $allowedValues = array_map(
            static fn (string $value): string => mb_strtolower(trim($value)),
            explode(',', $raw)
        );

        if (in_array(self::ALL_GROUPS_LABEL, $allowedValues, true)) {
            return true;
        }

        return $groupName !== '' && in_array(mb_strtolower(trim($groupName)), $allowedValues, true);
    }

    /**
     * Monta a resposta de negação com a mensagem informada.
     *
     * @param string $message
     * @return array{allowed:false,message:string,product:null}
     */
    private function deny(string $message): array
    {
        return [
            'allowed' => false,
            'message' => $message,
            'product' => null,
        ];
    }
}
