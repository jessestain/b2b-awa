<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Checkout;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface as CustomerAddressInterface;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Psr\Log\LoggerInterface;

/**
 * Completa shipping address incompleto (ex.: regionId ausente) antes do place order.
 */
class ShippingAddressFallbackService
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function resolveForQuote(int $cartId): void
    {
        /** @var Quote $quote */
        $quote = $this->cartRepository->getActive($cartId);
        if ($quote->isVirtual()) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress instanceof QuoteAddress || !$this->needsCompletion($shippingAddress)) {
            return;
        }

        $source = $this->resolveSourceAddress($quote, $shippingAddress);
        if ($source === null) {
            return;
        }

        $this->mergeMissingFields($shippingAddress, $source);
        $quote->setShippingAddress($shippingAddress);
        try {
            $this->cartRepository->save($quote);
        } catch (StateException) {
            return;
        }
    }

    private function needsCompletion(QuoteAddress $address): bool
    {
        if (trim((string) $address->getCountryId()) === 'BR' && !$address->getRegionId()) {
            return true;
        }

        return trim((string) $address->getFirstname()) === ''
            || trim((string) $address->getLastname()) === ''
            || trim((string) $address->getCity()) === ''
            || trim((string) $address->getPostcode()) === ''
            || $this->isPlaceholderTelephone((string) $address->getTelephone());
    }

    private function isPlaceholderTelephone(string $telephone): bool
    {
        $trimmed = trim($telephone);

        if ($trimmed === '') {
            return true;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        return in_array($digits, ['0', '0000000000', '00000000000'], true);
    }

    private function resolveSourceAddress(Quote $quote, QuoteAddress $shippingAddress): ?CustomerAddressInterface
    {
        $customerAddressId = (int) $shippingAddress->getCustomerAddressId();
        if ($customerAddressId > 0) {
            try {
                return $this->addressRepository->getById($customerAddressId);
            } catch (\Throwable $e) {
                $this->logger->debug(
                    '[ShippingAddressFallbackService::resolveSourceAddress] Endereço do quote não '
                    . 'encontrado, tentando próximo fallback: ' . $e->getMessage()
                );
            }
        }

        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return null;
        }

        $defaultShippingId = (int) $customer->getDefaultShipping();
        if ($defaultShippingId > 0) {
            try {
                return $this->addressRepository->getById($defaultShippingId);
            } catch (\Throwable $e) {
                $this->logger->debug(
                    '[ShippingAddressFallbackService::resolveSourceAddress] Endereço padrão de '
                    . 'entrega não encontrado, tentando próximo fallback: ' . $e->getMessage()
                );
            }
        }

        foreach ($customer->getAddresses() as $address) {
            if ($address instanceof CustomerAddressInterface) {
                return $address;
            }
        }

        return null;
    }

    private function mergeMissingFields(QuoteAddress $target, CustomerAddressInterface $source): void
    {
        if (!trim((string) $target->getFirstname()) && trim((string) $source->getFirstname())) {
            $target->setFirstname((string) $source->getFirstname());
        }
        if (!trim((string) $target->getLastname()) && trim((string) $source->getLastname())) {
            $target->setLastname((string) $source->getLastname());
        }
        if (!trim((string) $target->getCity()) && trim((string) $source->getCity())) {
            $target->setCity((string) $source->getCity());
        }
        if (!trim((string) $target->getPostcode()) && trim((string) $source->getPostcode())) {
            $target->setPostcode((string) $source->getPostcode());
        }
        if ($this->isPlaceholderTelephone((string) $target->getTelephone())
            && !$this->isPlaceholderTelephone((string) $source->getTelephone())
        ) {
            $target->setTelephone((string) $source->getTelephone());
        }
        if (!trim((string) $target->getCountryId()) && trim((string) $source->getCountryId())) {
            $target->setCountryId((string) $source->getCountryId());
        }

        $street = $target->getStreet();
        $streetLine = is_array($street) ? trim((string) ($street[0] ?? '')) : trim((string) $street);
        if ($streetLine === '' && $source->getStreet()) {
            $target->setStreet($source->getStreet());
        }

        if (!$target->getRegionId() && $source->getRegionId()) {
            $target->setRegionId($source->getRegionId());
        }
        if (!trim((string) $target->getRegion()) && trim((string) $source->getRegion()?->getRegion())) {
            $target->setRegion((string) $source->getRegion()->getRegion());
        }
        if (!trim((string) $target->getRegionCode()) && trim((string) $source->getRegion()?->getRegionCode())) {
            $target->setRegionCode((string) $source->getRegion()->getRegionCode());
        }
    }
}
