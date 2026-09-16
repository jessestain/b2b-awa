<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\ViewModel;

use GrupoAwamotos\AiAssistant\Helper\Config;
use GrupoAwamotos\AiAssistant\Model\Guide\SlotGuide;
use GrupoAwamotos\B2B\Model\CustomerApproval;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class ChatWidget implements ArgumentInterface
{
    public function __construct(
        private readonly Config           $config,
        private readonly CustomerSession  $customerSession,
        private readonly CustomerApproval $customerApproval,
        private readonly UrlInterface     $url,
        private readonly Json             $json
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isStorefrontEnabled();
    }

    public function getWidgetTitle(): string
    {
        return $this->config->getWidgetTitle();
    }

    public function getWelcomeMessage(): string
    {
        return $this->config->getWelcomeMessage();
    }

    public function getEndpointUrl(): string
    {
        return $this->url->getUrl('aiassistant/chat/message');
    }

    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    public function isB2BCustomer(): bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }
        return $this->customerApproval->isApproved((int) $this->customerSession->getCustomerId());
    }

    /**
     * Returns JS config as JSON for the Knockout component.
     */
    public function getWidgetConfigJson(): string
    {
        $isB2B    = $this->isB2BCustomer();
        $howToBuy = $this->config->getHowToBuyCmsUrl();
        $slotGuide = new SlotGuide();
        $guideStart = $slotGuide->toClient($slotGuide->start(), $isB2B);
        $guideIdle = $slotGuide->toClient($slotGuide->idle(), $isB2B);

        return $this->json->serialize([
            'endpoint'       => $this->getEndpointUrl(),
            'title'          => $this->getWidgetTitle(),
            'welcomeMessage' => $this->getWelcomeMessage(),
            'isLoggedIn'     => $this->isLoggedIn(),
            'isB2B'          => $isB2B,
            'channel'        => $isB2B ? 'b2b' : 'storefront',
            'helpCenterUrl'  => $this->url->getUrl('ajuda'),
            'privacyPolicyUrl' => $this->getPrivacyPolicyUrl(),
            'handoffUrl'     => $this->getHandoffUrl(),
            'registerUrl'    => $this->url->getUrl('b2b/register'),
            'guideStart'     => [
                'reply' => SlotGuide::START_REPLY,
                'chips' => $guideStart['chips'],
                'progress' => 1,
                'total' => 3,
            ],
            'guideIdle'      => [
                'chips' => $guideIdle['chips'],
            ],
            'guidedCoach'    => [
                'enabled'         => $this->config->isGuidedCoachEnabled(),
                'delayMs'         => $this->config->getGuidedCoachDelayMs(),
                'autoDismissMs'   => $this->config->getGuidedCoachAutoDismissMs(),
                'firstVisitMessage' => 'Bem-vindo a AWA Motos! Quer ajuda para encontrar a peça certa no primeiro acesso?',
                'guestMessage'    => $this->config->getGuidedCoachGuestMessage(),
                'customerMessage' => $this->config->getGuidedCoachAuthMessage(),
                'authMessage'     => $this->config->getGuidedCoachAuthMessage(),
                'b2bMessage'      => 'Novo aqui? Veja como usar cotações, visualizar crédito e gestão da sua carteira.',
                'howToBuyUrl'     => $howToBuy !== '' ? $this->resolveUrl($howToBuy) : '',
                /* 7-day suppress key (localStorage) — coach not shown for 7d after dismiss */
                'suppressDays'    => 7,
                'urls'            => [
                    'login'      => $this->url->getUrl('customer/account/login'),
                    'register'   => $this->url->getUrl('b2b/register'),
                    'orders'     => $this->url->getUrl('sales/order/history'),
                    'reorder'    => $this->url->getUrl('b2b/reorder/history'),
                    'helpCenter' => $this->url->getUrl('ajuda'),
                ],
            ],
        ]);
    }

    public function getPrivacyPolicyUrl(): string
    {
        return $this->resolveUrl($this->config->getPrivacyPolicyUrlPath());
    }

    public function getHandoffUrl(): string
    {
        $base = $this->config->getHumanHandoffUrl();
        $text = rawurlencode('Olá, vim do assistente do site e preciso de atendimento humano.');
        if (str_contains($base, '?')) {
            return $base . '&text=' . $text;
        }

        return $base . '?text=' . $text;
    }

    private function resolveUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }

        return $this->url->getUrl(ltrim($pathOrUrl, '/'));
    }
}
