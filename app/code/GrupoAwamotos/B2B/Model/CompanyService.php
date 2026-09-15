<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Model\CompanyFactory;
use GrupoAwamotos\B2B\Model\CompanyUserFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\Company as CompanyResource;
use GrupoAwamotos\B2B\Model\ResourceModel\CompanyUser as CompanyUserResource;
use GrupoAwamotos\B2B\Model\ResourceModel\Company\CollectionFactory as CompanyCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CompanyUser\CollectionFactory as UserCollectionFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class CompanyService
{
    private CompanyFactory $companyFactory;
    private CompanyUserFactory $userFactory;
    private CompanyResource $companyResource;
    private CompanyUserResource $userResource;
    private CompanyCollectionFactory $companyCollectionFactory;
    private UserCollectionFactory $userCollectionFactory;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        CompanyFactory $companyFactory,
        CompanyUserFactory $userFactory,
        CompanyResource $companyResource,
        CompanyUserResource $userResource,
        CompanyCollectionFactory $companyCollectionFactory,
        UserCollectionFactory $userCollectionFactory,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->companyFactory = $companyFactory;
        $this->userFactory = $userFactory;
        $this->companyResource = $companyResource;
        $this->userResource = $userResource;
        $this->companyCollectionFactory = $companyCollectionFactory;
        $this->userCollectionFactory = $userCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Find or create company by CNPJ, assign customer as admin
     */
    public function findOrCreateByCustomer(int $customerId): ?Company
    {
        $customer = $this->customerRepository->getById($customerId);
        $cnpj = $this->getCustomerCnpj($customer);

        if (!$cnpj) { // phpcs:ignore Squiz.Operators.ComparisonOperatorUsage
            return null;
        }

        $company = $this->findByCnpj($cnpj);
        if (!$company) {
            $company = $this->createCompany($cnpj, $customer, $customerId);
        }

        $this->ensureUserInCompany((int)$company->getId(), $customerId, Company::ROLE_ADMIN);
        return $company;
    }

    /**
     * Refresh names of existing companies owned by this customer only.
     * Does not create companies, assign users or change access permissions.
     */
    public function syncExistingCompanyDetailsFromCustomer(CustomerInterface $customer): int
    {
        $customerId = (int) $customer->getId();
        $cnpj = preg_replace('/\D/', '', (string) $this->getCustomerCnpj($customer));
        $taxvat = preg_replace('/\D/', '', (string) $customer->getTaxvat());
        if ($customerId <= 0 || strlen($cnpj) !== 14 || ($taxvat !== '' && $taxvat !== $cnpj)) {
            return 0;
        }

        $values = [
            'razao_social' => trim((string) $this->getCustomerAttribute($customer, 'b2b_razao_social')),
            'nome_fantasia' => trim((string) $this->getCustomerAttribute($customer, 'b2b_nome_fantasia')),
        ];
        $updated = 0;
        $page = 1;
        do {
            $collection = $this->companyCollectionFactory->create();
            $collection->addFieldToFilter('admin_customer_id', $customerId);
            $collection->setOrder('company_id', 'ASC');
            $collection->setPageSize(50)->setCurPage($page);
            foreach ($collection as $company) {
                if ((int) $company->getData('admin_customer_id') !== $customerId
                    || preg_replace('/\D/', '', (string) $company->getData('cnpj')) !== $cnpj
                ) {
                    continue;
                }
                $changed = false;
                foreach ($values as $field => $value) {
                    if ($value !== '' && (string) $company->getData($field) !== $value) {
                        $company->setData($field, $value);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->companyResource->save($company);
                    $updated++;
                }
            }
            $page++;
        } while ($page <= $collection->getLastPageNumber());

        return $updated;
    }

    public function findByCnpj(string $cnpj): ?Company
    {
        $collection = $this->companyCollectionFactory->create();
        $collection->addFieldToFilter('cnpj', $cnpj);
        $company = $collection->getFirstItem();
        return $company->getId() ? $company : null;
    }

    public function getCompanyForCustomer(int $customerId): ?Company
    {
        $companyIds = $this->getCompanyIdsForCustomer($customerId);
        if ($companyIds === []) {
            return null;
        }

        if (count($companyIds) > 1) {
            $this->logger->warning(
                'B2B CompanyService::getCompanyForCustomer: cliente pertence a múltiplas empresas '
                . 'ativas (multi-empresa); retornando a vinculação mais antiga. Para lógica '
                . 'sensível (ex: autorização), use getCompanyIdsForCustomer() e compare o conjunto.',
                ['customer_id' => $customerId, 'company_ids' => $companyIds]
            );
        }

        $company = $this->companyFactory->create();
        $this->companyResource->load($company, $companyIds[0]);
        return $company->getId() ? $company : null;
    }

    /**
     * Get all active company IDs a customer belongs to.
     *
     * A customer may be linked to more than one company (multi-empresa). Unlike
     * getCompanyForCustomer()/getUserRole(), which resolve a single "best guess"
     * company for simple display contexts, this returns the full set so that
     * security-sensitive checks (e.g. "do these two customers share a company?")
     * don't silently compare against an arbitrary company.
     *
     * @param int $customerId
     * @return int[]
     */
    public function getCompanyIdsForCustomer(int $customerId): array
    {
        $userCollection = $this->userCollectionFactory->create();
        $userCollection->addFieldToFilter('customer_id', $customerId);
        $userCollection->addFieldToFilter('is_active', 1);
        $userCollection->setOrder('user_id', 'ASC');

        $companyIds = [];
        foreach ($userCollection as $user) {
            $companyIds[] = (int) $user->getData('company_id');
        }

        return array_values(array_unique($companyIds));
    }

    public function getCompanyUsers(int $companyId): \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
    {
        return $this->userCollectionFactory->create()->filterByCompany($companyId);
    }

    /**
     * Invite user to company
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addUser(int $companyId, int $customerId, string $role = Company::ROLE_BUYER): CompanyUser
    {
        $existing = $this->getUserInCompany($companyId, $customerId);
        if ($existing) {
            throw new LocalizedException(__('Este usuário já está vinculado à empresa.'));
        }

        return $this->ensureUserInCompany($companyId, $customerId, $role);
    }

    /**
     * Remove user from company
     */
    public function removeUser(int $companyId, int $customerId): void
    {
        $user = $this->getUserInCompany($companyId, $customerId);
        if ($user) {
            $this->userResource->delete($user);
            $this->logger->info("B2B Company user removed: company=$companyId customer=$customerId");
        }
    }

    /**
     * Update user role
     */
    public function updateUserRole(int $companyId, int $customerId, string $role): void
    {
        $user = $this->getUserInCompany($companyId, $customerId);
        if ($user) {
            $user->setData('role', $role);
            $this->userResource->save($user);
        }
    }

    /**
     * Get user role in company.
     *
     * If the customer belongs to more than one active company (multi-empresa)
     * and no $companyId is given to disambiguate, the role from the earliest
     * membership is returned and a warning is logged — callers that need the
     * role within a specific company (e.g. after the user picks a company in
     * a switcher) should pass $companyId explicitly.
     *
     * @param int $customerId
     * @param int|null $companyId
     */
    public function getUserRole(int $customerId, ?int $companyId = null): ?string
    {
        $userCollection = $this->userCollectionFactory->create();
        $userCollection->addFieldToFilter('customer_id', $customerId);
        $userCollection->addFieldToFilter('is_active', 1);
        $userCollection->setOrder('user_id', 'ASC');

        if ($companyId !== null) {
            $userCollection->addFieldToFilter('company_id', $companyId);
            $user = $userCollection->getFirstItem();
            return $user->getId() ? $user->getData('role') : null;
        }

        $users = array_values($userCollection->getItems());
        if (count($users) > 1) {
            $this->logger->warning(
                'B2B CompanyService::getUserRole: cliente pertence a múltiplas empresas ativas '
                . '(multi-empresa); retornando o papel da vinculação mais antiga. Passe $companyId '
                . 'para desambiguar.',
                [
                    'customer_id' => $customerId,
                    'company_ids' => array_map(
                        static fn ($user) => (int) $user->getData('company_id'),
                        $users
                    ),
                ]
            );
        }

        $user = $users[0] ?? null;
        return $user ? $user->getData('role') : null;
    }

    private function getUserInCompany(int $companyId, int $customerId): ?CompanyUser
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter('company_id', $companyId);
        $collection->addFieldToFilter('customer_id', $customerId);
        $user = $collection->getFirstItem();
        return $user->getId() ? $user : null;
    }

    private function ensureUserInCompany(int $companyId, int $customerId, string $role): CompanyUser
    {
        $existing = $this->getUserInCompany($companyId, $customerId);
        if ($existing) {
            return $existing;
        }

        $user = $this->userFactory->create();
        $user->setData([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'role' => $role,
            'is_active' => 1,
        ]);
        $this->userResource->save($user);

        $this->logger->info("B2B Company user added: company=$companyId customer=$customerId role=$role");
        return $user;
    }

    private function createCompany(string $cnpj, $customer, int $customerId): Company
    {
        $company = $this->companyFactory->create();
        $company->setData([
            'cnpj' => $cnpj,
            'razao_social' => $this->getCustomerAttribute($customer, 'b2b_razao_social') ?: $customer->getFirstname() . ' ' . $customer->getLastname(),
            'nome_fantasia' => $this->getCustomerAttribute($customer, 'b2b_nome_fantasia'),
            'inscricao_estadual' => $this->getCustomerAttribute($customer, 'b2b_inscricao_estadual'),
            'email' => $customer->getEmail(),
            'phone' => $this->getCustomerAttribute($customer, 'b2b_company_phone'),
            'admin_customer_id' => $customerId,
            'is_active' => 1,
        ]);
        $this->companyResource->save($company);

        $this->logger->info("B2B Company created: cnpj=$cnpj company_id=" . $company->getId());
        return $company;
    }

    private function getCustomerCnpj($customer): ?string
    {
        return $this->getCustomerAttribute($customer, 'b2b_cnpj');
    }

    private function getCustomerAttribute($customer, string $code): ?string
    {
        $attr = $customer->getCustomAttribute($code);
        return $attr ? $attr->getValue() : null;
    }
}
