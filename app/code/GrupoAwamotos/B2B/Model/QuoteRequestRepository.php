<?php

/**
 * Quote Request Repository
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Api\Data\QuoteRequestInterface;
use GrupoAwamotos\B2B\Api\QuoteRequestRepositoryInterface;
use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest as QuoteRequestResource;
use GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class QuoteRequestRepository implements QuoteRequestRepositoryInterface
{
    /**
     * @var QuoteRequestFactory
     */
    private $quoteRequestFactory;

    /**
     * @var QuoteRequestResource
     */
    private $resource;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var SearchResultsInterfaceFactory
     */
    private $searchResultsFactory;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    public function __construct(
        QuoteRequestFactory $quoteRequestFactory,
        QuoteRequestResource $resource,
        CollectionFactory $collectionFactory,
        SearchResultsInterfaceFactory $searchResultsFactory,
        CheckoutSession $checkoutSession,
        Config $config,
        DateTime $dateTime,
        LoggerInterface $logger,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository
    ) {
        $this->quoteRequestFactory = $quoteRequestFactory;
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        $this->cartManagement = $cartManagement;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @inheritDoc
     *
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(QuoteRequestInterface $quoteRequest): QuoteRequestInterface
    {
        try {
            $this->resource->save($quoteRequest);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Não foi possível salvar a solicitação de cotação: %1', $e->getMessage())
            );
        }

        return $quoteRequest;
    }

    /**
     * @inheritDoc
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $requestId): QuoteRequestInterface
    {
        $quoteRequest = $this->quoteRequestFactory->create();
        $this->resource->load($quoteRequest, $requestId);

        if (!$quoteRequest->getRequestId()) {
            throw new NoSuchEntityException(
                __('Solicitação de cotação com ID "%1" não encontrada.', $requestId)
            );
        }

        return $quoteRequest;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria)
    {
        $collection = $this->collectionFactory->create();

        foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
            foreach ($filterGroup->getFilters() as $filter) {
                $condition = $filter->getConditionType() ?: 'eq';
                $collection->addFieldToFilter($filter->getField(), [$condition => $filter->getValue()]);
            }
        }

        $sortOrders = $searchCriteria->getSortOrders();
        if ($sortOrders) {
            foreach ($sortOrders as $sortOrder) {
                $collection->addOrder(
                    $sortOrder->getField(),
                    ($sortOrder->getDirection() == 'ASC') ? 'ASC' : 'DESC'
                );
            }
        }

        $collection->setCurPage($searchCriteria->getCurrentPage());
        $collection->setPageSize($searchCriteria->getPageSize());

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    /**
     * @inheritDoc
     *
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(QuoteRequestInterface $quoteRequest): bool
    {
        try {
            $this->resource->delete($quoteRequest);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Não foi possível deletar a solicitação de cotação: %1', $e->getMessage())
            );
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById(int $requestId): bool
    {
        return $this->delete($this->getById($requestId));
    }

    /**
     * @inheritDoc
     */
    public function createFromCart(?int $customerId, array $customerData, ?string $message = null): QuoteRequestInterface
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->hasItems()) {
                throw new \Exception('O carrinho está vazio.');
            }

            // Preparar itens
            $items = [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $items[] = [
                    'product_id' => $item->getProductId(),
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'qty' => $item->getQty(),
                    'original_price' => $item->getPrice(),
                    'row_total' => $item->getRowTotal(),
                    'options' => $item->getProduct()->getTypeInstance()->getOrderOptions($item->getProduct()),
                ];
            }

            // Calcular expiração
            $expiryDays = $this->config->getQuoteExpiryDays();
            $expiresAt = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

            // Criar solicitação
            $quoteRequest = $this->quoteRequestFactory->create();
            $quoteRequest->setCustomerId($customerId);
            $quoteRequest->setCustomerEmail($customerData['email'] ?? '');
            $quoteRequest->setCustomerName($customerData['name'] ?? '');
            $quoteRequest->setCompanyName($customerData['company_name'] ?? null);
            $quoteRequest->setCnpj($customerData['cnpj'] ?? null);
            $quoteRequest->setPhone($customerData['phone'] ?? null);
            $quoteRequest->setStatus(QuoteRequestInterface::STATUS_PENDING);
            $quoteRequest->setItems($items);
            $quoteRequest->setMessage($message);
            $quoteRequest->setQuoteId($quote->getId());
            $quoteRequest->setExpiresAt($expiresAt);

            $this->save($quoteRequest);

            $this->logger->info(
                sprintf('B2B: Nova solicitação de cotação #%d criada', $quoteRequest->getRequestId())
            );

            return $quoteRequest;
        } catch (\Exception $e) {
            $this->logger->error('B2B createFromCart error: ' . $e->getMessage());
            throw new CouldNotSaveException(__('Erro ao criar solicitação: %1', $e->getMessage()));
        }
    }

    /**
     * @inheritDoc
     */
    public function updateStatus(int $requestId, string $status, ?string $adminNotes = null): QuoteRequestInterface
    {
        $quoteRequest = $this->getById($requestId);
        $quoteRequest->setStatus($status);

        if ($adminNotes !== null) {
            $quoteRequest->setAdminNotes($adminNotes);
        }

        return $this->save($quoteRequest);
    }

    /**
     * @inheritDoc
     */
    public function setQuotedPrices(int $requestId, array $itemPrices, ?float $quotedTotal = null): QuoteRequestInterface
    {
        $quoteRequest = $this->getById($requestId);
        $items = $quoteRequest->getItems();

        foreach ($items as &$item) {
            if (isset($itemPrices[$item['sku']])) {
                $item['quoted_price'] = $itemPrices[$item['sku']];
            }
        }

        $quoteRequest->setItems($items);
        $quoteRequest->setQuotedTotal($quotedTotal);
        $quoteRequest->setStatus(QuoteRequestInterface::STATUS_QUOTED);

        return $this->save($quoteRequest);
    }

    /**
     * @inheritDoc
     */
    public function convertToOrder(int $requestId): int
    {
        $quoteRequest = $this->getById($requestId);

        if (!in_array($quoteRequest->getStatus(), [
            QuoteRequestInterface::STATUS_QUOTED,
            QuoteRequestInterface::STATUS_ACCEPTED
            ])
        ) {
            throw new LocalizedException(
                __('Apenas cotações respondidas ou aceitas podem ser convertidas em pedido.')
            );
        }

        $customerId = $quoteRequest->getCustomerId();
        if (!$customerId) {
            throw new LocalizedException(__('Cliente não identificado na cotação.'));
        }

        try {
            // Create a new cart for the customer
            $cartId = $this->cartManagement->createEmptyCartForCustomer($customerId);
            $cart = $this->cartRepository->get($cartId);

            // Add quoted items to the cart
            $items = $quoteRequest->getItems();
            foreach ($items as $item) {
                $productId = $item['product_id'] ?? null;
                if (!$productId) {
                    continue;
                }

                try {
                    $product = $this->productRepository->getById((int) $productId);
                    $request = new \Magento\Framework\DataObject([
                        'qty' => $item['qty'] ?? 1
                    ]);
                    $cart->addProduct($product, $request);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        sprintf('B2B convertToOrder: produto %d ignorado - %s', $productId, $e->getMessage())
                    );
                }
            }

            if (!$cart->hasItems()) {
                throw new LocalizedException(__('Nenhum produto da cotação pôde ser adicionado ao carrinho.'));
            }

            // Apply quoted prices using custom price
            foreach ($cart->getAllItems() as $cartItem) {
                $sku = $cartItem->getSku();
                foreach ($items as $item) {
                    if (($item['sku'] ?? '') === $sku && isset($item['quoted_price'])) {
                        $cartItem->setCustomPrice((float) $item['quoted_price']);
                        $cartItem->setOriginalCustomPrice((float) $item['quoted_price']);
                        $cartItem->getProduct()->setIsSuperMode(true);
                    }
                }
            }

            $cart->collectTotals();
            $this->cartRepository->save($cart);

            // Load this cart into the checkout session so the customer can complete checkout
            $this->checkoutSession->setQuoteId($cartId);

            // Update quote request status
            $quoteRequest->setStatus(QuoteRequestInterface::STATUS_CONVERTED);
            $this->save($quoteRequest);

            $this->logger->info(
                sprintf(
                    'B2B: Cotação #%d convertida em carrinho #%d para cliente #%d',
                    $requestId,
                    $cartId,
                    $customerId
                )
            );

            return (int) $cartId;
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('B2B convertToOrder error: ' . $e->getMessage());
            throw new CouldNotSaveException(
                __('Erro ao converter cotação em pedido: %1', $e->getMessage())
            );
        }
    }
}
