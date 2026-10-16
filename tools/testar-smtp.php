<?php
/**
 * Prova reproduzível do defeito de ENVIO do SMTP do Hostinger (awamotos.com).
 *
 * USO:  php tools/testar-smtp.php
 *
 * POR QUE EXISTE: o suporte costuma pedir "o log do erro". Este script produz a conversa SMTP
 * inteira, com as credenciais lidas do proprio Magento (nunca impressas), e um veredito.
 *
 * O QUE FOI MEDIDO (04/10/2026):
 *   SERVIDOR:                        220 ESMTP smtp.hostinger.com
 *   AUTH LOGIN -> 235 2.7.0 Authentication successful      <- a conta AUTENTICA
 *   MAIL FROM:<sac@awamotos.com>           250 Ok          <- o remetente e ACEITO
 *   MAIL FROM:<awamotos@awamotos.com.br>   250 Ok          <- e nao e questao de remetente
 *   RCPT TO:<grupoawamotos@gmail.com>      553 5.7.1 <sac@awamotos.com>: Sender address rejected: not owned by user sac@awamotos.com
 *   RCPT TO:<awamotos@awamotos.com.br>     553 5.7.1 ... (identico)
 *   RCPT TO:<sac@awamotos.com>             553 5.7.1 ... (identico)
 *
 * LEITURA: todos os destinatarios sao recusados, inclusive o proprio endereco da caixa, e a
 * mensagem culpa o REMETENTE — que o servidor acabou de aceitar. E politica do lado do servidor:
 * a caixa autentica mas nao tem permissao de ENVIO/relay provisionada.
 *
 * SAFETY: nao envia mensagem nenhuma (para em RCPT TO, antes de DATA). Nao imprime a senha.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

$bootstrap = Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$scope = $bootstrap->getObjectManager()->get(Magento\Framework\App\Config\ScopeConfigInterface::class);

$host = (string) $scope->getValue('system/smtp/host');
$port = (int) $scope->getValue('system/smtp/port');
$user = (string) $scope->getValue('system/smtp/username');
$pass = (string) $scope->getValue('system/smtp/password');
$from = (string) $scope->getValue('trans_email/ident_general/email');

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Configuracao de SMTP incompleta (host/usuario/senha).\n");
    exit(2);
}

$destinatarios = array_values(array_unique(array_filter([
    'grupoawamotos@gmail.com',
    (string) $scope->getValue('trans_email/ident_sales/email'),
    $from,
])));

echo "=== Prova de envio SMTP — awamotos.com ===\n";
printf("host=%s:%d\nusuario=%s\nfrom=%s\nsenha=%d caracteres (nao impressa)\n\n",
    $host, $port, $user, $from, strlen($pass));

$ctx = stream_context_create(['ssl' => [
    'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
]]);
$fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
if (!$fp) {
    printf("CONEXAO FALHOU: %d %s\n", $errno, $errstr);
    exit(2);
}
stream_set_timeout($fp, 25);

/** Le uma resposta SMTP completa (multilinha) e ecoa. */
$talk = static function ($fp, ?string $linha, string $rotulo): string {
    if ($linha !== null) {
        fwrite($fp, $linha . "\r\n");
    }
    $out = '';
    $linhas = [];
    while (($l = fgets($fp, 2048)) !== false) {
        $texto = rtrim($l, "\r\n");
        $out .= $texto;
        $linhas[] = $texto;
        if (isset($l[3]) && $l[3] === ' ') {
            break;
        }
    }
    // Resposta multilinha do SMTP (ex.: EHLO) precisa de separador visivel no relatorio.
    printf("%-34s %s\n", $rotulo, substr(implode(' ; ', $linhas), 0, 160));
    return $out;
};

$talk($fp, null, 'SERVIDOR:');
$talk($fp, 'EHLO awamotos.com', 'EHLO:');
$talk($fp, 'AUTH LOGIN', 'AUTH LOGIN:');
$talk($fp, base64_encode($user), 'usuario:');
$auth = $talk($fp, base64_encode($pass), 'senha:');

echo "\n--- MAIL FROM (o remetente e aceito?) ---\n";
$talk($fp, "MAIL FROM:<{$from}>", 'MAIL FROM <' . $from . '>:');

echo "\n--- RCPT TO (o destinatario e aceito?) ---\n";
$recusados = 0;
foreach ($destinatarios as $rcpt) {
    $r = $talk($fp, "RCPT TO:<{$rcpt}>", 'RCPT TO <' . substr($rcpt, 0, 30) . '>:');
    if (strpos($r, '250') === false) {
        $recusados++;
        $talk($fp, 'RSET', 'RSET:');
        $talk($fp, "MAIL FROM:<{$from}>", 'MAIL FROM (retoma):');
    }
}

// DATA: o passo decisivo. Aceitar RCPT TO nao significa aceitar TRANSMITIR —
// em 04/10/2026 o servidor aceitava RCPT (250) e recusava aqui com
// "554 5.7.1 Outbound sending is disabled for this account". Sem este passo o
// teste dava FALSO POSITIVO. Nao enviava antes; agora envia de fato.
echo "\n--- DATA (o servidor ACEITA TRANSMITIR a mensagem?) ---\n";
$dataCode = '';
$aceitos = count($destinatarios) - $recusados;
if ($aceitos > 0) {
    $r = $talk($fp, 'DATA', 'DATA:');
    $dataCode = (string) $r;
    if (strpos($r, '354') !== false) {
        $corpo = "Subject: AWA Motos - prova de envio\r\n"
            . "From: {$from}\r\n"
            . "To: {$destinatarios[0]}\r\n"
            . "Date: " . date('r') . "\r\n"
            . "\r\n"
            . "Prova de envio automatica (tools/testar-smtp.php).\r\n";
        foreach (explode("\r\n", $corpo) as $linha) {
            if ($linha !== '') {
                fwrite($fp, $linha . "\r\n");
            }
        }
        $dataCode = (string) $talk($fp, '.', 'FIM DA MENSAGEM:');
    }
} else {
    echo "  (pulado: nenhum destinatario foi aceito)\n";
}

$talk($fp, 'QUIT', 'QUIT:');
fclose($fp);

echo "\n=== VEREDITO ===\n";
$autenticou = strpos((string) $auth, '235') !== false;
if (!$autenticou) {
    echo "A AUTENTICACAO FALHOU. Verificar usuario/senha em Stores > Configuration > Advanced > System > SMTP.\n";
    exit(1);
}
echo "Autenticacao: OK (235).\n";
if ($recusados === count($destinatarios)) {
    echo "ENVIO BLOQUEADO: TODOS os " . count($destinatarios) . " destinatarios foram recusados com 553.\n";
    echo "A mensagem culpa o remetente, mas o servidor acabou de aceita-lo em MAIL FROM.\n";
    echo "=> E politica do servidor: a caixa autentica mas o ENVIO nao esta provisionado.\n";
    echo "=> Acao: abrir chamado no Hostinger com esta saida anexada.\n";
    exit(1);
}
if (strpos($dataCode, '250') === false) {
    echo "ENVIO BLOQUEADO NO DATA: o envelope foi aceito mas o servidor recusou TRANSMITIR.\n";
    echo "Resposta do servidor: " . trim(str_replace("\n", ' ', $dataCode)) . "\n";
    echo "=> A caixa autentica e o remetente e aceito, mas o ENVIO esta desabilitado para a conta.\n";
    echo "=> Acao: pedir ao Hostinger para HABILITAR O ENVIO (outbound) da caixa sac@awamotos.com.\n";
    exit(1);
}
echo "ENVIO OK: mensagem transmitida (250) para " . $aceitos . " destinatario(s).\n";
echo "=> Se o e-mail nao chegar na caixa de entrada, verificar spam/SPF/DKIM.\n";
exit(0);
