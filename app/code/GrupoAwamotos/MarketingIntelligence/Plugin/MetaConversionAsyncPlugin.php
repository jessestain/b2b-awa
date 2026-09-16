<?php

declare(strict_types=1);

namespace GrupoAwamotos\MarketingIntelligence\Plugin;

use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

/**
 * Torna a chamada Meta Conversion API (ViewContent / PDP) assíncrona (fire-and-forget).
 *
 * Problema: Meta\Conversion\Observer\ViewContent faz um curl síncrono para
 * https://graph.facebook.com com timeout 15s × 3 tentativas = até 45s.
 * Isso bloqueia o worker PHP-FPM em cada PDP FPC-MISS, causando "página sem resposta".
 *
 * Solução: captura a chamada durante o event dispatch, registra um shutdown_function
 * que, após o response ser enviado pelo FPM, executa a chamada Meta em background.
 * O usuário recebe a resposta imediatamente; o Facebook recebe o evento alguns segundos depois.
 *
 * @see Meta\Conversion\Observer\ViewContent
 */
class MetaConversionAsyncPlugin
{
    /** @var list<array{proceed: callable, observer: Observer}> */
    private static array $deferred = [];
    private static bool $registered = false;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundExecute(
        \Meta\Conversion\Observer\ViewContent $subject,
        callable $proceed,
        Observer $observer
    ): void {
        self::$deferred[] = ['proceed' => $proceed, 'observer' => $observer];

        if (!self::$registered) {
            self::$registered = true;
            $logger = $this->logger;
            register_shutdown_function(static function () use ($logger): void {
                // Envia a resposta HTTP para o browser AGORA, antes de chamar o Facebook.
                // Em PHP-FPM, fastcgi_finish_request() fecha o socket FastCGI
                // mas permite que o processo PHP continue rodando.
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);

                foreach (self::$deferred as $task) {
                    try {
                        ($task['proceed'])($task['observer']);
                    } catch (\Throwable $e) {
                        // Não vale interromper o shutdown por uma falha de tracking opcional,
                        // mas registra para permitir diagnóstico se a API Meta cair com frequência.
                        $logger->warning(
                            '[MetaConversionAsyncPlugin] Falha ao enviar Meta Conversion API: '
                            . $e->getMessage()
                        );
                    }
                }
            });
        }
    }
}
