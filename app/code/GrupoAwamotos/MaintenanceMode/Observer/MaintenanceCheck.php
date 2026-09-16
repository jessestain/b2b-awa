<?php

/**
 * GrupoAwamotos_MaintenanceMode - Observer de Verificação
 * Versão Premium Gratuita - Todas as funcionalidades
 */

declare(strict_types=1);

namespace GrupoAwamotos\MaintenanceMode\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class MaintenanceCheck implements ObserverInterface
{
    private const COOKIE_NAME = 'awa_maintenance_access';
    private const CONFIG_PATH = 'grupoawamotos_maintenance/';

    private ScopeConfigInterface $scopeConfig;
    private RemoteAddress $remoteAddress;
    private ResponseInterface $response;
    private RequestInterface $request;
    private CookieManagerInterface $cookieManager;
    private CookieMetadataFactory $cookieMetadataFactory;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RemoteAddress $remoteAddress,
        ResponseInterface $response,
        RequestInterface $request,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->remoteAddress = $remoteAddress;
        $this->response = $response;
        $this->request = $request;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer): void
    {
        // Não executar se não estiver habilitado
        if (!$this->isEnabled()) {
            return;
        }

        // Verificar código secreto na URL (?preview=CODIGO)
        if ($this->checkSecretKey()) {
            $this->setAccessCookie();
            return;
        }

        // Verificar cookie de acesso válido
        if ($this->hasAccessCookie()) {
            return;
        }

        // Verificar se IP está na whitelist
        if ($this->isWhitelisted()) {
            return;
        }

        // Verificar se é rota permitida (admin, newsletter, etc.)
        if ($this->isAllowedRoute()) {
            return;
        }

        // Verificar se é página CMS permitida
        if ($this->isAllowedCmsPage()) {
            return;
        }

        // Mostrar página de manutenção/em breve
        $this->showMaintenancePage();
    }

    private function getConfig(string $path, $default = null)
    {
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PATH . $path,
            ScopeInterface::SCOPE_STORE
        );
        return $value ?? $default;
    }

    private function isEnabled(): bool
    {
        return (bool) $this->getConfig('general/enabled');
    }

    private function checkSecretKey(): bool
    {
        $previewParam = $this->request->getParam('preview');
        if (empty($previewParam)) {
            return false;
        }
        $secretKey = $this->getConfig('general/secret_key');
        return !empty($secretKey) && $previewParam === $secretKey;
    }

    private function setAccessCookie(): void
    {
        $duration = (int) $this->getConfig('general/cookie_duration', 72);
        $metadata = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setDuration($duration * 3600)
            ->setPath('/')
            ->setHttpOnly(true);

        $this->cookieManager->setPublicCookie(
            self::COOKIE_NAME,
            hash('sha256', $this->getConfig('general/secret_key') . '_awamotos_access'),
            $metadata
        );
    }

    private function hasAccessCookie(): bool
    {
        $cookie = $this->cookieManager->getCookie(self::COOKIE_NAME);
        if (empty($cookie)) {
            return false;
        }
        $secretKey = $this->getConfig('general/secret_key');
        return $cookie === hash('sha256', $secretKey . '_awamotos_access');
    }

    private function isWhitelisted(): bool
    {
        $clientIp = $this->remoteAddress->getRemoteAddress();
        $whitelist = $this->getConfig('general/whitelist_ips');
        if (empty($whitelist)) {
            return false;
        }
        $whitelistArray = array_map('trim', explode("\n", $whitelist));
        return in_array($clientIp, array_filter($whitelistArray), true);
    }

    private function isAllowedRoute(): bool
    {
        $currentRoute = $this->request->getModuleName();
        $currentAction = $this->request->getFullActionName();

        // Rotas de sistema sempre permitidas
        $systemRoutes = ['admin', 'adminhtml', 'maintenance'];
        if (in_array($currentRoute, $systemRoutes)) {
            return true;
        }

        // Permitir controllers deste módulo
        if (strpos($currentAction, 'maintenance_') === 0) {
            return true;
        }

        // Rotas configuradas pelo admin
        $allowedRoutes = $this->getConfig('general/allowed_routes', '');
        if (empty($allowedRoutes)) {
            return false;
        }

        $routesArray = array_map('trim', explode(',', $allowedRoutes));
        return in_array($currentRoute, $routesArray);
    }

    private function isAllowedCmsPage(): bool
    {
        $currentPath = trim($this->request->getPathInfo(), '/');
        $allowedPages = $this->getConfig('general/allowed_cms_pages', '');

        if (empty($allowedPages)) {
            return false;
        }

        $pagesArray = array_map('trim', explode(',', $allowedPages));
        return in_array($currentPath, $pagesArray);
    }

    private function showMaintenancePage(): void
    {
        // Detectar modo (manutenção ou em breve)
        $mode = $this->getConfig('general/mode', 'maintenance');
        $isComingSoon = ($mode === 'coming_soon');
        $configPath = $isComingSoon ? 'coming_soon' : 'maintenance';

        // Configurações de conteúdo
        $title = $this->getConfig($configPath . '/title', 'Estamos em Manutenção');
        $message = $this->getConfig($configPath . '/message', '<p>Voltamos em breve!</p>');
        $showCountdown = (bool) $this->getConfig($configPath . '/show_countdown');
        $countdownDate = $this->getConfig($configPath . '/countdown_date');

        // Design
        $bgType = $this->getConfig('design/background_type', 'gradient');
        $bgColor = $this->getConfig('design/background_color', '#1a237e');
        $bgGradient = $this->getConfig('design/background_gradient', '#1a237e,#000428');
        $bgImage = $this->getConfig('design/background_image');
        $bgVideo = $this->getConfig('design/background_video');
        $textColor = $this->getConfig('design/text_color', '#ffffff');
        $customCss = $this->getConfig('design/custom_css', '');
        $logo = $this->getConfig('design/logo');

        // Newsletter
        $showNewsletter = (bool) $this->getConfig('newsletter/enabled');
        $newsletterTitle = $this->getConfig('newsletter/title', 'Seja avisado!');
        $newsletterButton = $this->getConfig('newsletter/button_text', 'Cadastrar');
        $newsletterSuccess = $this->getConfig('newsletter/success_message', 'Obrigado!');

        // Social
        $showSocial = (bool) $this->getConfig('social/enabled');
        $facebook = $this->getConfig('social/facebook');
        $instagram = $this->getConfig('social/instagram');
        $youtube = $this->getConfig('social/youtube');
        $whatsappSocial = $this->getConfig('social/whatsapp');

        // Contato
        $showContact = (bool) $this->getConfig('contact/show_info');
        $phone = $this->getConfig('contact/phone');
        $whatsappContact = $this->getConfig('contact/whatsapp');
        $email = $this->getConfig('contact/email');

        // URLs
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        // Build CSS de fundo
        $backgroundCss = $this->buildBackgroundCss($bgType, $bgColor, $bgGradient, $bgImage, $mediaUrl);
        $safeTextColor = preg_replace('/[^a-zA-Z0-9#(),.\/\s%+]/u', '', $textColor) ?: '#ffffff';
        $safeCustomCss = strip_tags($customCss);

        // URLs de mídia
        $logoUrl = $logo ? $mediaUrl . 'maintenance/' . $logo : '';

        // Build HTML sections
        $safeTitle = $this->escapeHtml($title);
        $safeMessage = $this->sanitizeMessage($message);
        $safeNewsletterTitle = $this->escapeHtml($newsletterTitle);
        $safeNewsletterButton = $this->escapeHtml($newsletterButton);
        $safeNewsletterSuccess = $this->escapeHtml($newsletterSuccess);

        $logoHtml = $logoUrl ? '<div class="logo"><img src="' . htmlspecialchars($logoUrl) . '" alt="AWA Motos"></div>' : '';
        $countdownHtml = ($showCountdown && $countdownDate) ? '<div id="countdown" class="countdown"></div>' : '';
        $countdownScript = ($showCountdown && $countdownDate) ? $this->getCountdownScript($countdownDate) : '';
        $newsletterHtml = $showNewsletter ? $this->getNewsletterHtml($baseUrl, $safeNewsletterTitle, $safeNewsletterButton, $safeNewsletterSuccess, $newsletterButton, $newsletterSuccess) : '';
        $socialHtml = $showSocial ? $this->getSocialHtml($facebook, $instagram, $youtube, $whatsappSocial) : '';
        $contactHtml = $showContact ? $this->getContactHtml($phone, $whatsappContact, $email) : '';
        $videoHtml = ($bgType === 'video' && $bgVideo) ? $this->getVideoBackground($bgVideo) : '';
        $secretCodeHtml = $this->getSecretCodeFormHtml($baseUrl);

        $icon = $isComingSoon
            ? '<svg width="80" height="80" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M505.12 19.09C503.42 14.1 499.9 10.58 494.91 8.88 487.7 6.29 321 48.12 207.89 161.23c-17.31 17.31-30.57 35.61-42.11 53.55-22.67-7.09-47.33-3.65-66.78 11.58L25.72 289.64c-6.25 6.25-6.25 16.38 0 22.63l22.63 22.63c6.25 6.25 16.38 6.25 22.63 0l26.84-26.84c3.37-3.37 8.84-3.37 12.21 0l11.31 11.31c-27.63 48.05-22.67 109.74 16.97 149.38 39.64 39.64 101.33 44.6 149.38 16.97l11.31 11.31c3.37 3.37 3.37 8.84 0 12.21l-26.84 26.84c-6.25 6.25-6.25 16.38 0 22.63l22.63 22.63c6.25 6.25 16.38 6.25 22.63 0l63.28-73.28c15.23-19.45 18.67-44.11 11.58-66.78 17.94-11.54 36.24-24.8 53.55-42.11C508.89 257.99 505.71 24.3 505.12 19.09zM298.5 213.5c-17.68-17.68-17.68-46.34 0-64.02 17.68-17.68 46.34-17.68 64.02 0 17.68 17.68 17.68 46.34 0 64.02-17.68 17.67-46.34 17.67-64.02 0z"/></svg>'
            : '<svg width="80" height="80" viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M507.73 109.1c-2.24-9.03-13.54-12.09-20.12-5.51l-74.36 74.36-67.88-11.31-11.31-67.88 74.36-74.36c6.58-6.58 3.52-17.88-5.51-20.12-32.25-8-67.32 1.05-92.6 26.33-26.33 26.33-34.72 63.38-25.33 96.62L49.7 362.51c-17.78 17.78-18.93 44.59 0 63.52s45.74 17.78 63.52 0l235.28-235.28c33.24 9.39 70.29 1 96.62-25.33 25.28-25.28 34.33-60.35 26.33-92.6zM64 472c-13.25 0-24-10.75-24-24 0-13.26 10.75-24 24-24s24 10.74 24 24c0 13.25-10.75 24-24 24z"/></svg>';
        $httpCode = $isComingSoon ? 200 : 503;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{$safeTitle} | AWA Motos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            {$backgroundCss}
            color: {$textColor};
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            text-align: center;
            position: relative;
            overflow-x: hidden;
        }
        .video-bg {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            z-index: -1;
        }
        .overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 0;
        }
        .container {
            max-width: 700px;
            width: 100%;
            animation: fadeIn 1s ease-out;
            position: relative;
            z-index: 1;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .logo { margin-bottom: 30px; }
        .logo img { max-width: 250px; height: auto; }
        .icon { font-size: 80px; margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .content h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 20px; line-height: 1.2; }
        .content p { font-size: 1.1rem; line-height: 1.8; margin-bottom: 15px; opacity: 0.9; }
        a { color: #4fc3f7; text-decoration: none; font-weight: 600; transition: color 0.3s; }
        a:hover { color: #81d4fa; text-decoration: underline; }

        /* Countdown */
        .countdown {
            display: flex; justify-content: center; gap: 20px; flex-wrap: wrap;
            margin: 30px 0; padding: 25px;
            background: rgba(255,255,255,0.1); border-radius: 16px;
            backdrop-filter: blur(10px);
        }
        .countdown-item { display: flex; flex-direction: column; align-items: center; min-width: 80px; }
        .countdown-value { font-size: 3rem; font-weight: 700; line-height: 1; }
        .countdown-label { font-size: 0.75rem; text-transform: uppercase; opacity: 0.7; margin-top: 5px; }

        /* Newsletter */
        .newsletter {
            margin: 30px 0; padding: 25px;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            backdrop-filter: blur(10px);
        }
        .newsletter h3 { margin-bottom: 15px; font-size: 1.2rem; }
        .newsletter-form { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        .newsletter-form input[type="email"] {
            flex: 1; min-width: 250px; padding: 15px 20px;
            border: none; border-radius: 50px;
            font-size: 1rem; outline: none;
            background: #fff; color: #333;
        }
        .newsletter-form button {
            padding: 15px 30px;
            background: #4fc3f7;
            color: #000;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .newsletter-form button:hover { background: #81d4fa; transform: scale(1.05); }
        .newsletter-message {
            margin-top: 15px;
            padding: 10px;
            border-radius: 8px;
            display: none;
        }
        .newsletter-message.success { background: rgba(129,199,132,0.3); color: #c8e6c9; display: block; }
        .newsletter-message.error { background: rgba(239,83,80,0.3); color: #ffcdd2; display: block; }

        /* Secret Code Form */
        .secret-access {
            margin-top: 30px;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            border: 1px dashed rgba(255,255,255,0.2);
        }
        .secret-access summary {
            cursor: pointer;
            font-size: 0.9rem;
            opacity: 0.7;
            list-style: none;
        }
        .secret-access summary::-webkit-details-marker { display: none; }
        .secret-access[open] summary { margin-bottom: 15px; }
        .secret-form { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
        .secret-form input[type="text"] {
            flex: 1;
            min-width: 200px;
            padding: 12px 18px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 50px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            font-size: 1rem;
            outline: none;
        }
        .secret-form input::placeholder { color: rgba(255,255,255,0.5); }
        .secret-form button {
            padding: 12px 25px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .secret-form button:hover { background: rgba(255,255,255,0.3); }
        .secret-message { margin-top: 10px; font-size: 0.9rem; display: none; }

        /* Social */
        .social-links { margin-top: 30px; display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; }
        .social-links a {
            display: inline-flex; align-items: center; justify-content: center;
            width: 55px; height: 55px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            transition: all 0.3s;
            font-size: 26px;
            text-decoration: none;
        }
        .social-links a:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-5px) scale(1.1);
        }

        /* Contact */
        .contact-info {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        .contact-info p { margin: 5px 0; }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 600px) {
            .content h1 { font-size: 1.8rem; }
            .countdown { gap: 10px; padding: 15px; }
            .countdown-value { font-size: 2rem; }
            .countdown-item { min-width: 60px; }
            .newsletter-form input[type="email"] { min-width: 100%; }
            .secret-form input[type="text"] { min-width: 100%; }
        }
        {$safeCustomCss}
    </style>
</head>
<body>
    {$videoHtml}
    <div class="container">
        {$logoHtml}
        <div class="icon">{$icon}</div>
        <div class="content">{$safeMessage}</div>
        {$countdownHtml}
        {$newsletterHtml}
        {$secretCodeHtml}
        {$socialHtml}
        {$contactHtml}
    </div>
    {$countdownScript}
</body>
</html>
HTML;

        $this->response->setHttpResponseCode($httpCode);
        if (!$isComingSoon) {
            $this->response->setHeader('Retry-After', '3600');
        }
        $this->response->setBody($html);
        $this->response->sendResponse();
        // Observer runs before dispatch; without an early halt here, the front controller
        // would still execute the original action after this response was already sent,
        // duplicating output.
        // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        exit;
    }

    private function buildBackgroundCss(string $type, string $color, string $gradient, ?string $image, string $mediaUrl): string
    {
        switch ($type) {
            case 'color':
                return "background: {$color};";
            case 'gradient':
                $colors = explode(',', $gradient);
                $color1 = trim($colors[0] ?? '#1a237e');
                $color2 = trim($colors[1] ?? '#000428');
                return "background: linear-gradient(135deg, {$color1} 0%, {$color2} 100%);";
            case 'image':
                if ($image) {
                    $imageUrl = $mediaUrl . 'maintenance/' . $image;
                    return "background: url('{$imageUrl}') center/cover no-repeat fixed; background-color: #000;";
                }
                return "background: #000;";
            case 'video':
                return "background: #000;";
            default:
                return "background: linear-gradient(135deg, #1a237e 0%, #000428 100%);";
        }
    }

    private function getVideoBackground(string $videoUrl): string
    {
        // Detectar YouTube
        if (strpos($videoUrl, 'youtube') !== false || strpos($videoUrl, 'youtu.be') !== false) {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $videoUrl, $matches);
            $videoId = $matches[1] ?? '';
            if ($videoId) {
                return '<div class="overlay"></div><iframe class="video-bg" src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '?autoplay=1&mute=1&loop=1&playlist=' . htmlspecialchars($videoId) . '&controls=0&showinfo=0" frameborder="0" allow="autoplay" allowfullscreen></iframe>';
            }
        }
        // Vídeo MP4 direto
        return '<div class="overlay"></div><video class="video-bg" autoplay muted loop playsinline><source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4"></video>';
    }

    private function getNewsletterHtml(string $baseUrl, string $titleHtml, string $buttonHtml, string $successHtml, string $buttonRaw, string $successRaw): string
    {
        $actionUrl = $baseUrl . 'maintenance/newsletter/subscribe';
        $actionUrlJs = json_encode($actionUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
        $buttonJs = json_encode($buttonRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '"Cadastrar"';
        $successJs = json_encode($successRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
        return <<<HTML
<div class="newsletter">
    <h3>{$titleHtml}</h3>
    <form class="newsletter-form" id="maintenance-newsletter">
        <input type="email" name="email" id="newsletter-email" placeholder="Seu melhor e-mail" required>
        <button type="submit" id="newsletter-btn">{$buttonHtml}</button>
    </form>
    <div class="newsletter-message" id="newsletter-msg"></div>
</div>
<script>
document.getElementById('maintenance-newsletter').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('newsletter-btn');
    const msg = document.getElementById('newsletter-msg');
    const email = document.getElementById('newsletter-email').value;

    btn.disabled = true;
    btn.innerHTML += '<span class="spinner"></span>';
    msg.className = 'newsletter-message';
    msg.style.display = 'none';

    fetch({$actionUrlJs}, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'email=' + encodeURIComponent(email)
            + '&form_key=' + encodeURIComponent((document.cookie.match(/(?:^|; )form_key=([^;]*)/) || [])[1] || '')
    })
    .then(r => r.json())
    .then(data => {
        msg.textContent = data.message || (data.success ? '✅ ' + {$successJs} : '❌ Erro ao cadastrar.');
        msg.className = 'newsletter-message ' + (data.success ? 'success' : 'error');
        msg.style.display = 'block';
        if (data.success) document.getElementById('newsletter-email').value = '';
    })
    .catch(() => {
        msg.textContent = '❌ Erro de conexão. Tente novamente.';
        msg.className = 'newsletter-message error';
        msg.style.display = 'block';
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = {$buttonJs};
    });
});
</script>
HTML;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeMessage(?string $message): string
    {
        $message = strip_tags((string) $message);

        return nl2br($this->escapeHtml($message));
    }

    private function getSecretCodeFormHtml(string $baseUrl): string
    {
        $actionUrl = $baseUrl . 'maintenance/access/validate';
        return <<<HTML
<details class="secret-access">
    <summary>🔐 Possui código de acesso?</summary>
    <form class="secret-form" id="secret-access-form">
        <input type="text" name="code" id="access-code" placeholder="Digite o código" required>
        <button type="submit" id="access-btn">Entrar</button>
    </form>
    <div class="secret-message" id="access-msg"></div>
</details>
<script>
document.getElementById('secret-access-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('access-btn');
    const msg = document.getElementById('access-msg');
    const code = document.getElementById('access-code').value;

    btn.disabled = true;
    btn.innerHTML = 'Verificando<span class="spinner"></span>';
    msg.style.display = 'none';

    fetch('{$actionUrl}', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'code=' + encodeURIComponent(code)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.redirect) {
            msg.textContent = '✅ ' + data.message;
            msg.style.color = '#81c784';
            msg.style.display = 'block';
            setTimeout(() => window.location.href = data.redirect, 1000);
        } else {
            msg.textContent = '❌ ' + (data.message || 'Código inválido');
            msg.style.color = '#ef5350';
            msg.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Entrar';
        }
    })
    .catch(() => {
        msg.textContent = '❌ Erro de conexão';
        msg.style.color = '#ef5350';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Entrar';
    });
});
</script>
HTML;
    }

    private function getSocialHtml(?string $facebook, ?string $instagram, ?string $youtube, ?string $whatsapp): string
    {
        $links = '';
        $svgSize = 'width="24" height="24" fill="currentColor"';
        if ($whatsapp) {
            $links .= '<a href="https://wa.me/' . htmlspecialchars($whatsapp) . '" title="WhatsApp" target="_blank" rel="noopener"><svg ' . $svgSize . ' viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg></a>';
        }
        if ($facebook) {
            $links .= '<a href="' . htmlspecialchars($facebook) . '" title="Facebook" target="_blank" rel="noopener"><svg ' . $svgSize . ' viewBox="0 0 320 512" xmlns="http://www.w3.org/2000/svg"><path d="M279.14 288l14.22-92.66h-88.91v-60.13c0-25.35 12.42-50.06 52.24-50.06h40.42V6.26S260.43 0 225.36 0c-73.22 0-121.08 44.38-121.08 124.72v70.62H22.89V288h81.39v224h100.17V288z"/></svg></a>';
        }
        if ($instagram) {
            $links .= '<a href="' . htmlspecialchars($instagram) . '" title="Instagram" target="_blank" rel="noopener"><svg ' . $svgSize . ' viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg></a>';
        }
        if ($youtube) {
            $links .= '<a href="' . htmlspecialchars($youtube) . '" title="YouTube" target="_blank" rel="noopener"><svg ' . $svgSize . ' viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg"><path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"/></svg></a>';
        }
        return $links ? '<div class="social-links">' . $links . '</div>' : '';
    }

    private function getContactHtml(?string $phone, ?string $whatsapp, ?string $email): string
    {
        $html = '<div class="contact-info"><p><strong>AWA Motos</strong> - Peças e Acessórios para Motos</p>';
        $contacts = [];
        if ($phone) {
            $contacts[] = '<svg width="14" height="14" fill="currentColor" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle"><path d="M493.4 24.6l-104-24c-11.3-2.6-22.9 3.3-27.5 13.9l-48 112c-4.2 9.8-1.4 21.3 6.9 28l60.6 49.6c-36 76.7-98.9 140.5-177.2 177.2l-49.6-60.6c-6.8-8.3-18.2-11.1-28-6.9l-112 48C3.9 366.5-2 378.1.6 389.4l24 104C27.1 504.2 36.7 512 48 512c256.1 0 464-207.5 464-464 0-11.2-7.7-20.9-18.6-23.4z"/></svg> ' . htmlspecialchars($phone);
        }
        if ($whatsapp) {
            $contacts[] = '<svg width="14" height="14" fill="currentColor" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle"><path d="M256 32C132.3 32 32 132.3 32 256c0 44.4 13 85.7 35.2 120.5L32 480l96.6-35.2C163.7 467 204.3 480 248 480h8c123.7 0 224-100.3 224-224S379.7 32 256 32zm128 312c-5.3 14.9-30.8 28.5-42.5 30.3-11.7 1.8-22.5 8-73.8-15.4-62.1-28.3-101.5-91.5-104.6-95.7-3.1-4.2-25.3-33.7-25.3-64.3s16-45.6 21.7-51.8c5.7-6.2 12.4-7.8 16.6-7.8 4.1 0 8.3 0 11.9.2 3.8.2 8.9-1.4 13.9 10.6 5.3 12.8 17.8 43.5 19.4 46.6 1.5 3.1 2.6 6.8.5 10.9-2.1 4.2-3.1 6.8-6.2 10.4-3.1 3.6-6.5 8-9.3 10.7-3.1 3.1-6.3 6.4-2.7 12.6 3.6 6.2 16 26.4 34.3 42.8 23.6 21.1 43.5 27.6 49.7 30.7 6.2 3.1 9.8 2.6 13.4-1.5 3.6-4.2 15.4-17.8 19.5-24 4.1-6.2 8.3-5.2 13.9-3.1 5.7 2.1 36 17 42.2 20.1 6.2 3.1 10.4 4.6 11.9 7.2 1.6 2.6 1.6 14.9-3.7 29.8z"/></svg> ' . htmlspecialchars($whatsapp);
        }
        if ($email) {
            $contacts[] = '<svg width="14" height="14" fill="currentColor" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle"><path d="M502.3 190.8c3.9-3.1 9.7-.2 9.7 4.7V400c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V195.6c0-5 5.7-7.8 9.7-4.7 22.4 17.4 52.1 39.5 154.1 113.6 21.1 15.4 56.7 47.8 92.2 47.6 35.7.3 72-32.8 92.3-47.6 102-74.1 131.6-96.3 154-113.7zM256 320c23.2.4 56.6-29.2 73.4-41.4 132.7-96.3 142.8-104.7 173.4-128.7 5.8-4.5 9.2-11.5 9.2-18.9v-19c0-26.5-21.5-48-48-48H48C21.5 64 0 85.5 0 112v19c0 7.4 3.4 14.3 9.2 18.9 30.6 23.9 40.7 32.4 173.4 128.7 16.8 12.2 50.2 41.8 73.4 41.4z"/></svg> <a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>';
        }
        if ($contacts) {
            $html .= '<p>' . implode(' | ', $contacts) . '</p>';
        }
        return $html . '</div>';
    }

    private function getCountdownScript(string $targetDate): string
    {
        return <<<SCRIPT
<script>
(function() {
    const targetDate = new Date('{$targetDate}'.replace(' ', 'T')).getTime();
    const countdown = document.getElementById('countdown');
    if (!countdown) return;

    function updateCountdown() {
        const now = new Date().getTime();
        const distance = targetDate - now;
        if (distance < 0) {
            countdown.innerHTML = '<p style="font-size:1.5rem;">Estamos quase prontos!</p>';
            return;
        }
        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
        countdown.innerHTML =
            '<div class="countdown-item"><span class="countdown-value">' + days + '</span><span class="countdown-label">Dias</span></div>' +
            '<div class="countdown-item"><span class="countdown-value">' + String(hours).padStart(2,'0') + '</span><span class="countdown-label">Horas</span></div>' +
            '<div class="countdown-item"><span class="countdown-value">' + String(minutes).padStart(2,'0') + '</span><span class="countdown-label">Min</span></div>' +
            '<div class="countdown-item"><span class="countdown-value">' + String(seconds).padStart(2,'0') + '</span><span class="countdown-label">Seg</span></div>';
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
</script>
SCRIPT;
    }
}
