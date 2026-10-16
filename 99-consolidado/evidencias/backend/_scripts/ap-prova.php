<?php
/**
 * task-74 — PROVA DIRIGIDA com o execute() REAL, notificationService STUBADO (nenhum e-mail sai)
 * e tudo dentro de uma TRANSACAO REVERTIDA (nada persiste).
 *
 * Roda igual ANTES e DEPOIS da correcao, para comparar.
 *
 * (0) passivo atual: com os dados reais (0 open) nao gera nada
 * (1) incidente LONGO (occurrences=10 = acima do teto antigo) -> notifica?
 *     incidente NOVO  (occurrences=1, notified_at NULL)        -> notifica?
 *     alerta DENTRO do cooldown (notified_at = agora-1h)        -> fica quieto?
 * (2) SEGUNDA JANELA: com notified_at = agora-7h, o incidente longo notifica DE NOVO?
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$conn = $om->get('Magento\\Framework\\App\\ResourceConnection')->getConnection();
$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');
$logger = $om->get('Psr\\Log\\LoggerInterface');

/** stub: registra a chamada e devolve sucesso SEM enviar nada */
class StubNotifier extends \GrupoAwamotos\LogMonitoring\Service\NotificationService
{
    public array $chamadas = [];

    public function sendAlert(array $alertData): array
    {
        $this->chamadas[] = substr((string) $alertData['message'], 0, 40);
        return ['email' => true, 'slack' => false, 'webhook' => false];
    }
}

$stub = new StubNotifier(
    $om->get('Magento\\Framework\\App\\Config\\ScopeConfigInterface'),
    $om->get('Magento\\Framework\\Mail\\Template\\TransportBuilder'),
    $om->get('Magento\\Framework\\Translate\\Inline\\StateInterface'),
    $om->get('Magento\\Framework\\HTTP\\Client\\Curl'),
    $om->get('Magento\\Framework\\HTTP\\Client\\Curl'),
    $logger
);

$processor = new \GrupoAwamotos\LogMonitoring\Cron\AlertProcessor($repo, $stub, $logger);
$agora = date('Y-m-d H:i:s');

$inserir = static function (string $marca, int $occurrences, ?string $notifiedAt) use ($conn, $agora): int {
    $conn->insert('awa_log_alerts', [
        'alert_type' => 'teste_task74_' . $marca,
        'severity' => 'high',
        'title' => 'PROVA task-74 (transacao revertida)',
        'message' => 'MARCA:' . $marca,
        'context_data' => '{"teste":true}',
        'source' => 'erp_analyzer',
        'status' => 'open',
        'occurrences' => $occurrences,
        'first_occurrence' => $agora,
        'last_occurrence' => $agora,
        'notified_at' => $notifiedAt,
    ]);
    return (int) $conn->lastInsertId('awa_log_alerts');
};

// ---------------------------------------------------------------- (0)
echo "############ (0) PASSIVO ATUAL (dados reais, 0 open) ############\n";
$stub->chamadas = [];
$processor->execute();
echo '  notificacoes disparadas: ' . count($stub->chamadas) . " (esperado 0)\n";
echo '  alertas open na tabela: ' . (int) $conn->fetchOne("SELECT COUNT(*) FROM awa_log_alerts WHERE status='open'") . "\n\n";

// ---------------------------------------------------------------- transacao
$conn->beginTransaction();
try {
    echo "############ (1) CENARIOS ############\n";
    $idLongo = $inserir('longo', 10, null);
    $idNovo = $inserir('novo', 1, null);
    $idCooldown = $inserir('cooldown', 1, date('Y-m-d H:i:s', time() - 3600));
    printf("  inseridos (na transacao): longo=%d (occ=10, notified=NULL) | novo=%d (occ=1, NULL) | cooldown=%d (occ=1, agora-1h)\n",
        $idLongo, $idNovo, $idCooldown);

    $stub->chamadas = [];
    $processor->execute();
    echo '  notificacoes: ' . count($stub->chamadas) . "\n";
    foreach ($stub->chamadas as $c) {
        echo "    -> $c\n";
    }

    $ler = static function (int $id) use ($conn): ?string {
        $v = $conn->fetchOne("SELECT notified_at FROM awa_log_alerts WHERE entity_id = ?", [$id]);
        return $v === false ? null : $v;
    };
    printf("  notified_at: longo=%s | novo=%s | cooldown=%s\n",
        var_export($ler($idLongo), true), var_export($ler($idNovo), true), var_export($ler($idCooldown), true));

    echo "\n############ (2) SEGUNDA JANELA (6h depois) ############\n";
    $conn->update('awa_log_alerts', ['notified_at' => date('Y-m-d H:i:s', time() - 7 * 3600)], ['entity_id = ?' => $idLongo]);
    echo "  notified_at do incidente longo recuado para agora-7h (janela expirada)\n";
    $stub->chamadas = [];
    $processor->execute();
    echo '  notificacoes na 2a janela: ' . count($stub->chamadas) . "\n";
    foreach ($stub->chamadas as $c) {
        echo "    -> $c\n";
    }
    echo '  o incidente longo continua aberto? ' . var_export($ler($idLongo) !== null, true) . "\n";
} finally {
    $conn->rollBack();
    echo "\n  ROLLBACK executado.\n";
}

echo '  linhas de teste task-74 apos o rollback: '
    . (int) $conn->fetchOne("SELECT COUNT(*) FROM awa_log_alerts WHERE alert_type LIKE 'teste_task74%'") . " (esperado 0)\n";
echo '  total de alertas: ' . (int) $conn->fetchOne('SELECT COUNT(*) FROM awa_log_alerts') . "\n";
