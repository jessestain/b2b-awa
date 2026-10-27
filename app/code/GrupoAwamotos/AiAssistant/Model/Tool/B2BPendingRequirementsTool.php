<?php
declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\Support\B2bAccessExplainer;
use GrupoAwamotos\B2B\Api\CustomerApprovalInterface;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Registration\B2bPhoneNormalizer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * "O que falta no meu cadastro B2B?" — lista só o que é verificável.
 *
 * Confere CNPJ, razão social e telefone comercial (as mesmas regras do
 * B2bRegistrationGuard) e informa o estado da análise e há quantos dias o
 * cadastro foi enviado. NÃO expõe nota interna da equipe, score, motivo de
 * reprovação nem o número do CNPJ. Identidade só da sessão.
 */
class B2BPendingRequirementsTool implements ToolInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerApprovalInterface $customerApproval,
        private readonly B2bPhoneNormalizer $phoneNormalizer,
        private readonly AttendantManager $attendantManager,
        private readonly B2bAccessExplainer $explainer,
        private readonly Config $config
    ) {
    }

    public function getName(): string
    {
        return 'b2b_pending_requirements';
    }

    public function getDescription(): string
    {
        return 'Lista o que ainda falta no cadastro B2B da pessoa logada (CNPJ, razão social, telefone comercial), '
            . 'o estado da análise e há quantos dias o cadastro foi enviado. Use quando perguntarem '
            . '"o que falta no meu cadastro", "minha conta B2B está incompleta", "quanto tempo demora a aprovação". '
            . 'Não recebe parâmetros: consulta a conta de quem está conversando. Somente leitura.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [
                'logged_in'   => false,
                'explanation' => 'Para conferir o cadastro B2B é preciso estar logado. Quem ainda não tem conta '
                    . 'pode se cadastrar com CNPJ e a equipe comercial analisa em seguida.',
            ];
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return ['error' => 'Não foi possível localizar o seu cadastro.'];
        }

        $approval = $this->customerApproval->getApprovalStatus($customerId);
        $missing  = $this->explainer->missingRequirements(
            $this->attr($customer, 'b2b_cnpj'),
            $this->attr($customer, 'b2b_razao_social'),
            $this->phoneNormalizer->isValidBrazilianPhone($this->attr($customer, 'b2b_phone'))
        );

        $statusLabels = [
            'approved'    => 'aprovado',
            'pending'     => 'em análise pela equipe comercial',
            'data_review' => 'aguardando revisão de dados',
            'rejected'    => 'não aprovado',
            'suspended'   => 'suspenso',
        ];

        $result = [
            'logged_in'         => true,
            'approval_status'   => (string) $approval,
            'approval_label'    => $statusLabels[strtolower((string) $approval)] ?? 'não identificado',
            'registered_days_ago' => $this->explainer->daysSince((string) $customer->getCreatedAt()),
            'registration_complete' => $missing === [],
            'missing'           => $missing,
            'note'              => 'Só liste o que está em "missing". Não invente documentos nem prazos; '
                . 'a decisão de aprovar é sempre da equipe comercial.',
        ];

        if ($missing !== [] || strtolower((string) $approval) !== 'approved') {
            $result['contact'] = $this->contactFor($customerId);
        }

        return $result;
    }

    public function getAllowedChannels(): array
    {
        return [
            AssistantOrchestratorInterface::CHANNEL_STOREFRONT,
            AssistantOrchestratorInterface::CHANNEL_B2B,
        ];
    }

    private function attr(\Magento\Customer\Api\Data\CustomerInterface $customer, string $code): string
    {
        $attribute = $customer->getCustomAttribute($code);

        return $attribute ? trim((string) $attribute->getValue()) : '';
    }

    /**
     * @return array<string, string>
     */
    private function contactFor(int $customerId): array
    {
        try {
            $attendant = $this->attendantManager->getCustomerAttendant($customerId);
        } catch (\Throwable) {
            $attendant = null;
        }
        if (is_array($attendant)) {
            $link = $this->explainer->whatsappLink((string) ($attendant['whatsapp'] ?? $attendant['phone'] ?? ''));
            $name = trim((string) ($attendant['name'] ?? ''));
            if ($link !== '' && $name !== '') {
                return ['attendant' => $name, 'whatsapp_url' => $link];
            }
        }

        return ['whatsapp_url' => $this->config->getHumanHandoffUrl()];
    }
}
