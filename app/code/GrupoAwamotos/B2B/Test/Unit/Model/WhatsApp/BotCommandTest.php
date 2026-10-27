<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Model\WhatsApp;

use GrupoAwamotos\B2B\Model\WhatsApp\BotCommand;
use PHPUnit\Framework\TestCase;

class BotCommandTest extends TestCase
{
    private BotCommand $bot;

    protected function setUp(): void
    {
        if (!class_exists(BotCommand::class, false)) {
            require_once dirname(__DIR__, 4) . '/Model/WhatsApp/BotCommand.php';
        }
        $this->bot = new BotCommand();
    }

    /**
     * @dataProvider commands
     */
    public function testExplicitCommands(string $message, string $expected): void
    {
        $this->assertSame($expected, $this->bot->parse($message));
    }

    public static function commands(): array
    {
        // literais: o data provider roda antes do setUp (classe ainda não carregada)
        return [
            ['MENU', 'help'],
            ['ajuda!', 'help'],
            ['*Pedidos*', 'orders'],
            ['status do pedido?', 'orders'],
            ['Limite de crédito', 'credit'],
            ['  saldo ', 'credit'],
        ];
    }

    /**
     * Conversa normal fica com a equipe — antes "oi" dentro de qualquer palavra disparava o menu.
     *
     * @dataProvider humanConversation
     */
    public function testNormalConversationIsLeftToHumans(string $message): void
    {
        $this->assertNull($this->bot->parse($message));
    }

    public static function humanConversation(): array
    {
        return [
            ['boa noite'],
            ['oi'],
            ['oi, tudo bem? quero fazer um pedido de 10 retrovisores'],
            ['mandei dois boletos ontem'],
            ['quanto custa o pedido minimo?'],
            ['qual o status da entrega do pedido 196422?'],
            [''],
        ];
    }

    /**
     * @dataProvider ignoredPayloads
     */
    public function testIgnoresOwnGroupAndSystemMessages(array $payload): void
    {
        $this->assertTrue($this->bot->shouldIgnore($payload));
    }

    public static function ignoredPayloads(): array
    {
        return [
            'enviada pela loja' => [['type' => 'ReceivedCallback', 'phone' => '5516999990000', 'fromMe' => true]],
            'grupo'             => [['type' => 'ReceivedCallback', 'phone' => '120363-group', 'isGroup' => true]],
            'status/story'      => [['type' => 'ReceivedCallback', 'phone' => '5516999990000', 'isStatusReply' => true]],
            'callback de envio' => [['type' => 'MessageStatusCallback', 'phone' => '5516999990000']],
            'sem telefone'      => [['type' => 'ReceivedCallback']],
        ];
    }

    public function testAcceptsPrivateCustomerMessage(): void
    {
        $this->assertFalse($this->bot->shouldIgnore([
            'type' => 'ReceivedCallback', 'phone' => '5516999990000', 'fromMe' => false, 'isGroup' => false,
            'text' => ['message' => 'pedidos'],
        ]));
    }
}
