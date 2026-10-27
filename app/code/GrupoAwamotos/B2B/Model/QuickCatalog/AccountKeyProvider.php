<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

/**
 * Chave pública de conta (sha256 curto do customer_id) usada pelo frontend
 * para escopar estado local (seleções) sem misturar contas.
 *
 * Fase 2: extraído de Search.php/Block para um único ponto — o valor gerado
 * é IDÊNTICO ao da Fase 1 (prefixo 'awa_qc|' + sha256, 16 hex).
 * Não é segredo: é apenas um identificador estável por conta.
 * Instância (não estática) para permitir intercepção/DI, conforme padrão Magento.
 */
class AccountKeyProvider
{
    private const PREFIX = 'awa_qc|';
    private const HEX_LENGTH = 16;

    /**
     * Gera a chave pública da conta a partir do customer_id.
     *
     * @param int $customerId
     * @return string 16 caracteres hex (sha256 do prefixo + id)
     */
    public function getAccountKey(int $customerId): string
    {
        return substr(hash('sha256', self::PREFIX . $customerId), 0, self::HEX_LENGTH);
    }
}
