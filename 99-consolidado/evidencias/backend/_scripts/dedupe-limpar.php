<?php
/**
 * task-49 — limpeza do artefato de teste + estado final.
 * A chamada de teste sobrescreveu message/context_data da linha 157 (o dedupe atualiza esses
 * campos de proposito). Restaura os valores originais, reconstruidos a partir das linhas 155/156,
 * que tem exatamente o mesmo conteudo. Mantem occurrences=3 (os incrementos sao a evidencia).
 * NAO toca em notified_at.
 */
$root = '/home/jessessh/htdocs/srv1113343.hstgr.cloud';
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

$repo = $om->get('GrupoAwamotos\\LogMonitoring\\Api\\AlertRepositoryInterface');
$alert = $repo->getById(157);

$msgOriginal = 'Detected 64 NoSuchEntityException errors in exception.log';
$ctxOriginal = ['file' => 'exception.log', 'count' => 64];

echo "ANTES:\n";
echo '  message      = ' . $alert->getMessage() . "\n";
echo '  context_data = ' . json_encode($alert->getContextData()) . "\n";
echo '  occurrences  = ' . $alert->getOccurrences() . ' | notified_at = ' . var_export($alert->getData('notified_at'), true) . "\n";

$alert->setMessage($msgOriginal);
$alert->setContextData($ctxOriginal);
$repo->save($alert);

$depois = $repo->getById(157);
echo "\nDEPOIS:\n";
echo '  message      = ' . $depois->getMessage() . "\n";
echo '  context_data = ' . json_encode($depois->getContextData()) . "\n";
echo '  occurrences  = ' . $depois->getOccurrences() . ' | notified_at = ' . var_export($depois->getData('notified_at'), true) . "\n";
echo '  status       = ' . $depois->getStatus() . ' | last_occurrence = ' . $depois->getLastOccurrence() . "\n";
echo "\nnotified_at preservado (continua NULL)? "
    . var_export($depois->getData('notified_at') === null, true) . "\n";
