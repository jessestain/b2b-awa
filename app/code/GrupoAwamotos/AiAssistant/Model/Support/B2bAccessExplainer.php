<?php
declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Support;

/**
 * Traduz o estado de acesso B2B do cliente em explicação e próximo passo.
 *
 * Classe PURA (sem dependência do Magento) para poder ser testada sem
 * instalação. As tools `b2b_price_table_status` e `b2b_pending_requirements`
 * só coletam os fatos (sessão, atributos, regra de visibilidade) e delegam o
 * texto para cá, então a explicação nunca diverge entre os dois canais.
 *
 * Regras de conteúdo: nunca expor nota interna, score, motivo de reprovação
 * nem nenhuma decisão da equipe — só o estado e o caminho para resolver.
 */
class B2bAccessExplainer
{
    public const STATE_GUEST = 'guest';
    public const STATE_CAN_VIEW = 'can_view_prices';
    public const STATE_APPROVED_WAITING_ERP = 'approved_waiting_price_table';
    public const STATE_PENDING = 'pending_review';
    public const STATE_DATA_REVIEW = 'data_review';
    public const STATE_REJECTED = 'rejected';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_UNKNOWN = 'unknown';

    public const FIELD_CNPJ = 'cnpj';
    public const FIELD_RAZAO = 'razao_social';
    public const FIELD_PHONE = 'telefone_comercial';

    /**
     * Estado de acesso a preço. A ordem espelha PriceVisibility::canViewPrices().
     */
    public function priceState(
        bool $loggedIn,
        ?string $approvalStatus,
        bool $canViewPrices,
        bool $approvedPendingErp
    ): string {
        if (!$loggedIn) {
            return $canViewPrices ? self::STATE_CAN_VIEW : self::STATE_GUEST;
        }
        if ($approvedPendingErp) {
            return self::STATE_APPROVED_WAITING_ERP;
        }
        if ($canViewPrices) {
            return self::STATE_CAN_VIEW;
        }

        return match (strtolower((string) $approvalStatus)) {
            'pending'     => self::STATE_PENDING,
            'data_review' => self::STATE_DATA_REVIEW,
            'rejected'    => self::STATE_REJECTED,
            'suspended'   => self::STATE_SUSPENDED,
            default       => self::STATE_UNKNOWN,
        };
    }

    /**
     * @return array{explanation: string, next_step: string, needs_human: bool}
     */
    public function describe(string $state): array
    {
        switch ($state) {
            case self::STATE_GUEST:
                return [
                    'explanation' => 'Os preços da AWA Motos são exclusivos para revendedores e empresas com '
                        . 'cadastro B2B (CNPJ) aprovado. Quem ainda não entrou na conta não vê preço.',
                    'next_step'   => 'Entrar na conta ou fazer o cadastro B2B com CNPJ. Depois da aprovação '
                        . 'pela equipe comercial os preços são liberados.',
                    'needs_human' => false,
                ];
            case self::STATE_CAN_VIEW:
                return [
                    'explanation' => 'O cadastro está aprovado e a tabela de preços está liberada para esta conta.',
                    'next_step'   => 'Se algum produto específico não mostra preço, atualizar a página ou informar '
                        . 'o código do produto para a equipe conferir.',
                    'needs_human' => false,
                ];
            case self::STATE_APPROVED_WAITING_ERP:
                return [
                    'explanation' => 'O cadastro B2B está APROVADO, mas a tabela de preços desta conta ainda está '
                        . 'sendo definida pela equipe comercial (vínculo com o sistema interno). Enquanto isso o '
                        . 'preço e o botão de compra ficam ocultos. Não é um erro da conta.',
                    'next_step'   => 'Pedir a liberação da tabela de preços ao atendente da conta; é um passo da '
                        . 'equipe, o cliente não precisa reenviar documentos.',
                    'needs_human' => true,
                ];
            case self::STATE_PENDING:
                return [
                    'explanation' => 'O cadastro B2B foi recebido e está em análise pela equipe comercial. Os preços '
                        . 'são liberados depois da aprovação.',
                    'next_step'   => 'Aguardar a análise ou pedir ao atendente que priorize o cadastro.',
                    'needs_human' => true,
                ];
            case self::STATE_DATA_REVIEW:
                return [
                    'explanation' => 'A equipe comercial pediu uma revisão dos dados do cadastro antes de aprovar.',
                    'next_step'   => 'Conferir os dados do cadastro (CNPJ, razão social e telefone comercial) e '
                        . 'falar com o atendente para concluir.',
                    'needs_human' => true,
                ];
            case self::STATE_REJECTED:
                return [
                    'explanation' => 'O cadastro B2B não foi aprovado. Esta assistente não tem acesso ao motivo.',
                    'next_step'   => 'Falar com a equipe comercial para entender e, se for o caso, reenviar.',
                    'needs_human' => true,
                ];
            case self::STATE_SUSPENDED:
                return [
                    'explanation' => 'O cadastro B2B está temporariamente suspenso. Esta assistente não tem acesso '
                        . 'ao motivo.',
                    'next_step'   => 'Falar com a equipe comercial para regularizar.',
                    'needs_human' => true,
                ];
            default:
                return [
                    'explanation' => 'Não foi possível identificar o estado do cadastro B2B desta conta.',
                    'next_step'   => 'Falar com a equipe comercial para conferir o cadastro.',
                    'needs_human' => true,
                ];
        }
    }

    /**
     * Pendências verificáveis do cadastro. Espelha B2bRegistrationGuard
     * (CNPJ de 14 dígitos, razão social >= 3, telefone brasileiro válido).
     *
     * @return array<int, array{field: string, label: string, hint: string}>
     */
    public function missingRequirements(string $cnpj, string $razaoSocial, bool $phoneValid): array
    {
        $missing = [];

        if (strlen((string) preg_replace('/\D+/', '', $cnpj)) !== 14) {
            $missing[] = [
                'field' => self::FIELD_CNPJ,
                'label' => 'CNPJ',
                'hint'  => 'Informar o CNPJ com 14 dígitos.',
            ];
        }
        if (mb_strlen(trim($razaoSocial)) < 3) {
            $missing[] = [
                'field' => self::FIELD_RAZAO,
                'label' => 'Razão social',
                'hint'  => 'Informar a razão social da empresa (ou validar o CNPJ para preencher sozinho).',
            ];
        }
        if (!$phoneValid) {
            $missing[] = [
                'field' => self::FIELD_PHONE,
                'label' => 'Telefone comercial',
                'hint'  => 'Informar um telefone com DDD (10 ou 11 dígitos).',
            ];
        }

        return $missing;
    }

    /**
     * Dias inteiros desde uma data (UTC). Nulo se a data não for válida.
     */
    public function daysSince(?string $dateTime, ?\DateTimeImmutable $now = null): ?int
    {
        if ($dateTime === null || trim($dateTime) === '') {
            return null;
        }
        try {
            $then = new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $then->getTimestamp();

        return $diff < 0 ? 0 : intdiv($diff, 86400);
    }

    /**
     * Link wa.me a partir de um número brasileiro. Vazio se não houver número
     * plausível (DDD + 8/9 dígitos, com ou sem 55).
     */
    public function whatsappLink(?string $number): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $number);
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 10 || strlen($digits) > 11) {
            return '';
        }

        return 'https://wa.me/55' . $digits;
    }
}
