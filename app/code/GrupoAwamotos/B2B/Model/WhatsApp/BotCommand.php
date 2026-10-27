<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\WhatsApp;

/**
 * Decide se o robô do WhatsApp deve responder a uma mensagem recebida.
 *
 * O número da Z-API também é usado pela equipe para conversar com clientes,
 * então o robô só fala quando recebe um COMANDO explícito. A versão anterior
 * procurava "oi" em qualquer posição ("boa noite", "dois", "oito") e respondia
 * o menu a quase toda mensagem; também não ignorava grupos nem as mensagens
 * enviadas pela própria loja (risco de laço).
 *
 * Classe pura (sem Magento), instanciada com `new` pelo controller.
 */
final class BotCommand
{
    public const HELP   = 'help';
    public const ORDERS = 'orders';
    public const CREDIT = 'credit';

    private const COMMANDS = [
        self::HELP   => ['menu', 'ajuda', 'opcoes', 'comandos', 'robo'],
        self::ORDERS => ['pedidos', 'meus pedidos', 'pedido', 'status', 'status pedido', 'status do pedido',
                         'status do meu pedido', 'status dos pedidos', 'rastreio', 'rastrear pedido'],
        self::CREDIT => ['limite', 'meu limite', 'limite de credito', 'credito', 'meu credito', 'saldo'],
    ];

    /**
     * Payload da Z-API que nunca deve ser respondido.
     *
     * @param array<string, mixed> $data
     */
    public function shouldIgnore(array $data): bool
    {
        foreach (['fromMe', 'isGroup', 'isNewsletter', 'broadcast', 'isStatusReply', 'isEdit'] as $flag) {
            if (!empty($data[$flag])) {
                return true;
            }
        }
        if (isset($data['type']) && $data['type'] !== 'ReceivedCallback') {
            return true;
        }
        $phone = (string) ($data['phone'] ?? '');
        // grupos e listas também chegam com sufixo no "phone"
        return $phone === '' || str_contains($phone, '-group') || str_contains($phone, '@');
    }

    /**
     * Comando reconhecido ou null (null = deixa para a equipe humana).
     */
    public function parse(string $message): ?string
    {
        $text = $this->normalize($message);
        if ($text === '') {
            return null;
        }
        foreach (self::COMMANDS as $command => $phrases) {
            if (in_array($text, $phrases, true)) {
                return $command;
            }
        }
        return null;
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        // "MENU!", "*pedidos*", "limite?" => comando puro
        $text = preg_replace('/[^a-z0-9 ]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
