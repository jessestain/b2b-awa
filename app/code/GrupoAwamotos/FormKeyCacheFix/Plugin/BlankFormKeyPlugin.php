<?php
/**
 * P0.2: zera o value do form_key renderizado pelo bloco core FormKey.
 *
 * O bloco Magento\Framework\View\Element\FormKey->toHtml() devolve:
 *   <input name="form_key" type="hidden" value="FORM_KEY_DA_SESSAO" />
 * Sob Full Page Cache (Varnish), esse HTML e cacheado e o form_key de uma
 * sessao vaza para todas as demais. Deixamos o value vazio; o provider de
 * form_key do Magento (Magento_PageCache::form_key_provider.phtml, ja presente
 * no <head>) le o cookie form_key por-sessao e preenche todos os
 * input[name="form_key"] no cliente antes do submit.
 *
 * Escopo: apenas area frontend (di.xml em etc/frontend). O admin continua com
 * o form_key real, pois nao usa FPC.
 */
declare(strict_types=1);

namespace GrupoAwamotos\FormKeyCacheFix\Plugin;

use Magento\Framework\View\Element\FormKey;

class BlankFormKeyPlugin
{
    /**
     * Substitui o value do input form_key por vazio no HTML ja renderizado.
     *
     * @param FormKey $subject
     * @param string $result HTML produzido por toHtml()
     * @return string
     */
    public function afterToHtml(FormKey $subject, $result)
    {
        if (!is_string($result) || $result === '') {
            return $result;
        }

        // Troca value="qualquercoisa" por value="" apenas no input form_key.
        // Regex ancorada no atributo name="form_key" para nao afetar outros inputs.
        return preg_replace(
            '/(<input\b[^>]*\bname="form_key"[^>]*\bvalue=")[^"]*(")/i',
            '${1}${2}',
            $result
        );
    }
}
