<?php
/**
 * GrupoAwamotos_FormKeyCacheFix
 *
 * P0.2: Evita que o form_key da sessao seja congelado em HTML publico cacheado
 * (FPC/Varnish). O bloco core Magento\Framework\View\Element\FormKey imprime
 * <input name="form_key" value="FORM_KEY_DA_SESSAO">; sob FPC esse valor vaza
 * para todas as sessoes. Este modulo zera o value no servidor; o JS de
 * hidratacao do Magento (form_key_provider / mage/cookies) preenche o valor
 * correto por-sessao no cliente antes do submit.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'GrupoAwamotos_FormKeyCacheFix',
    __DIR__
);
