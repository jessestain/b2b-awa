<?php
/**
 * P0.1 - Camada 1: Buscar nos logs o SKU que gerou o erro Fail-closed
 * Somente leitura
 */

require __DIR__ . '/app/bootstrap.php';

$base = BP . '/var/log';

echo "=== PROCURANDO ERRO Fail-closed NOS LOGS ===\n\n";

// Search all log files for the Fail-closed error
$logFiles = glob($base . '/*.log');
$found = [];

foreach ($logFiles as $logFile) {
    $name = basename($logFile);
    $content = @file_get_contents($logFile);
    if ($content === false) continue;
    
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (stripos($line, 'Fail-closed') !== false ||
            stripos($line, 'authorized price unavailable') !== false ||
            stripos($line, 'B2B ErpQuotePrice') !== false) {
            $found[] = ['file' => $name, 'line' => $i + 1, 'text' => $line];
        }
    }
}

if (empty($found)) {
    echo "  NENHUM erro Fail-closed encontrado nos logs.\n\n";
    
    // Check if exception.log exists and has relevant entries
    echo "  Verificando exception.log...\n";
    $excFile = $base . '/exception.log';
    if (file_exists($excFile)) {
        $content = file_get_contents($excFile);
        $lines = explode("\n", $content);
        $relevant = array_filter($lines, function($l) {
            return stripos($l, 'B2B') !== false ||
                   stripos($l, 'authorized price') !== false ||
                   stripos($l, 'Fail-closed') !== false ||
                   stripos($l, 'ErpQuotePrice') !== false ||
                   stripos($l, '9068') !== false;
        });
        echo "  Linhas relevantes em exception.log: " . count($relevant) . "\n";
        foreach (array_slice($relevant, -10) as $l) {
            echo "  " . substr($l, 0, 500) . "\n";
        }
    }
    
    // Also check for ErpQuotePrice in system.log (broader search)
    echo "\n  Buscando 'ErpQuotePrice' em system.log...\n";
    $sysFile = $base . '/system.log';
    if (file_exists($sysFile)) {
        $content = file_get_contents($sysFile);
        $lines = explode("\n", $content);
        $relevant = array_filter($lines, function($l) {
            return stripos($l, 'ErpQuotePrice') !== false ||
                   stripos($l, 'B2B ErpQuotePrice') !== false;
        });
        echo "  Linhas com ErpQuotePrice: " . count($relevant) . "\n";
        foreach (array_slice($relevant, -10) as $l) {
            echo "  " . substr($l, 0, 500) . "\n";
        }
    }
    
    // Search for "authorized price" more broadly
    echo "\n  Buscando 'authorized price' em todos os logs...\n";
    foreach ($logFiles as $logFile) {
        $name = basename($logFile);
        $content = @file_get_contents($logFile);
        if ($content === false) continue;
        if (stripos($content, 'authorized price') !== false) {
            echo "  Encontrado em: $name\n";
            $lines = explode("\n", $content);
            foreach ($lines as $l) {
                if (stripos($l, 'authorized price') !== false) {
                    echo "    " . substr($l, 0, 500) . "\n";
                }
            }
        }
    }
} else {
    echo "  " . count($found) . " ocorrência(s) encontrada(s):\n\n";
    foreach (array_slice($found, -30) as $entry) {
        echo "  [{$entry['file']}:L{$entry['line']}] " . substr($entry['text'], 0, 500) . "\n";
    }
}

// Also search for the exact exception message
echo "\n=== PROCURANDO 'Não foi possível validar o preço' ===\n";
foreach ($logFiles as $logFile) {
    $name = basename($logFile);
    $content = @file_get_contents($logFile);
    if ($content === false) continue;
    if (stripos($content, 'validar o preço') !== false || stripos($content, 'validar o pre') !== false) {
        echo "  Encontrado em: $name\n";
        $lines = explode("\n", $content);
        foreach ($lines as $l) {
            if (stripos($l, 'validar o pre') !== false) {
                echo "    " . substr($l, 0, 500) . "\n";
            }
        }
    }
}

// Check debug.log for quote/cart related entries
echo "\n=== PROCURANDO ENTRADAS DE QUOTE/CART RELACIONADAS A 9068 ===\n";
foreach ($logFiles as $logFile) {
    $name = basename($logFile);
    if (strpos($name, 'debug') !== false || strpos($name, 'exception') !== false) {
        $content = @file_get_contents($logFile);
        if ($content === false) continue;
        $lines = explode("\n", $content);
        $relevant = array_filter($lines, function($l) {
            return stripos($l, '9068') !== false && (
                stripos($l, 'quote') !== false ||
                stripos($l, 'cart') !== false ||
                stripos($l, 'add_to_cart') !== false ||
                stripos($l, 'addtocart') !== false
            );
        });
        if (!empty($relevant)) {
            echo "  Encontrado em: $name (" . count($relevant) . " linhas)\n";
            foreach (array_slice($relevant, -10) as $l) {
                echo "    " . substr($l, 0, 500) . "\n";
            }
        }
    }
}

// List all log files with sizes and dates for context
echo "\n=== LOGFILES DISPONÍVEIS ===\n";
foreach ($logFiles as $logFile) {
    $name = basename($logFile);
    $size = filesize($logFile);
    $mtime = date('Y-m-d H:i:s', filemtime($logFile));
    echo "  $name ($size bytes, $mtime)\n";
}

echo "\n=== FIM ===\n";
