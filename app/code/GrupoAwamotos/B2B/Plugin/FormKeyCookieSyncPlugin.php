<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use Magento\Framework\App\PageCache\FormKey as CookieFormKey;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Session\Config\ConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Psr\Log\LoggerInterface;

/**
 * Garante cookie form_key alinhado ao hidden input em páginas com formulário.
 * Sem isso, POST falha com "Invalid Form Key" quando o JS do PageCache não rodou ainda.
 *
 * Fix: flag $cookieSetInRequest evita múltiplos Set-Cookie na mesma request
 * (getFormKey() é chamado por N blocos por página, causando triple-cookie que
 * impede Varnish de cachear e aumenta tamanho do response header).
 */
class FormKeyCookieSyncPlugin
{
    /** @var bool Garante apenas 1 Set-Cookie form_key por request HTTP */
    private bool $cookieSetInRequest = false;

    public function __construct(
        private readonly CookieFormKey $cookieFormKey,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly ConfigInterface $sessionConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param FormKey $subject
     * @param string $result
     * @return string
     */
    public function afterGetFormKey(FormKey $subject, string $result): string
    {
        if ($result === '' || $this->cookieSetInRequest) {
            return $result;
        }

        $currentCookie = (string) $this->cookieFormKey->get();
        if ($currentCookie === $result) {
            $this->cookieSetInRequest = true;
            return $result;
        }

        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $metadata->setDomain($this->sessionConfig->getCookieDomain());
            $metadata->setPath($this->sessionConfig->getCookiePath());
            $metadata->setSecure((bool) $this->sessionConfig->getCookieSecure());
            $metadata->setSameSite('Lax');
            $lifetime = $this->sessionConfig->getCookieLifetime();
            if ($lifetime !== 0) {
                $metadata->setDuration($lifetime);
            }

            $this->cookieFormKey->set($result, $metadata);
            $this->cookieSetInRequest = true;
        } catch (\Throwable $e) {
            $this->logger->debug(
                '[FormKeyCookieSyncPlugin::afterGetFormKey] Cookie já enviado ou headers bloqueados: '
                . $e->getMessage()
            );
        }

        return $result;
    }
}
