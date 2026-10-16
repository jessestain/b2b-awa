#!/usr/bin/env bash
# AWA Motos — varredura de SAUDE DOS MODULOS (v2, corrigida)
# A v1 listava ~380 "modulos fora do config" no vendor por erro MEU: pacote
# `magento/module-catalog` vira o modulo `Magento_Catalog` (nao `module-catalog`).
# Agora converte o nome direito e separa CUSTOM de VENDOR.
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud || exit 1

php -r '
$cfg = include "app/etc/config.php";
$mods = $cfg["modules"];

function nomeDoDir(string $d): ?string {
    $p = explode("/", $d);
    if (count($p) < 3) return null;
    $v = $p[1]; $m = $p[2];
    // magento/module-catalog -> Magento_Catalog
    if (preg_match("/^module-(.+)$/", $m, $x)) {
        $m = str_replace("-", "", ucwords($x[1], "-"));
    } elseif (preg_match("/^language-(.+)$/", $m, $x)) {
        return null;                                  // language packs nao sao modulos
    } elseif (preg_match("/^framework/", $m)) {
        return null;                                  // frameworks nao sao modulos
    } else {
        $m = str_replace("-", "", ucwords($m, "-"));
    }
    return ucfirst($v) . "_" . $m;
}

// ---------- inventario ----------
$custom = []; $vendor = [];
foreach (glob("app/code/*/*", GLOB_ONLYDIR) as $d) {
    $p = explode("/", $d);
    $custom[$p[2]."_".$p[3]] = $d;
}
foreach (glob("vendor/*/*", GLOB_ONLYDIR) as $d) {
    if (!file_exists("$d/registration.php")) continue;
    $n = nomeDoDir($d);
    if ($n) $vendor[$n] = $d;
}
ksort($custom); ksort($vendor);

$ativos = array_filter($mods);

echo "\n======== RESUMO ========\n";
printf("  custom em app/code ......... %d (%d ativos, %d desligados)\n", count($custom),
    count(array_intersect_key($ativos, $custom)), count($custom) - count(array_intersect_key($ativos, $custom)));
printf("  vendor declarados .......... %d\n", count($vendor));
printf("  total no config.php ........ %d (%d ativos, %d desligados)\n", count($mods), count($ativos), count($mods) - count($ativos));

echo "\n======== A) CUSTOM FORA DO config.php (modulo morto) ========\n";
$n1 = 0;
foreach ($custom as $n => $d) {
    if (!array_key_exists($n, $mods)) { echo "  *** $n   ($d)\n"; $n1++; }
}
echo $n1 ? "" : "  (nenhum)\n";

echo "\n======== B) CUSTOM ATIVO CUJA DEPENDENCIA ESTA DESLIGADA ========\n";
$n2 = 0;
foreach ($custom as $n => $d) {
    if (empty($mods[$n])) continue;
    $txt = "";
    if (file_exists("$d/etc/module.xml")) $txt .= file_get_contents("$d/etc/module.xml");
    if (file_exists("$d/composer.json"))  $txt .= file_get_contents("$d/composer.json");
    foreach ($vendor as $vn => $vd) {
        if (isset($mods[$vn]) && !$mods[$vn] && strpos($txt, $vn) !== false) {
            echo "  *** $n depende de $vn (DESLIGADO no vendor)\n"; $n2++;
        }
    }
    foreach ($custom as $cn => $cd) {
        if ($cn === $n) continue;
        if (isset($mods[$cn]) && !$mods[$cn] && strpos($txt, $cn) !== false) {
            echo "  *** $n depende de $cn (DESLIGADO custom)\n"; $n2++;
        }
    }
}
echo $n2 ? "" : "  (nenhuma)\n";

echo "\n======== C) MODULO CUSTOM ATIVO SEM EFEITO NENHUM ========\n";
$n3 = [];
foreach ($custom as $n => $d) {
    if (empty($mods[$n])) continue;
    $efeitos = [];
    foreach (["etc/di.xml","etc/frontend/di.xml","etc/adminhtml/di.xml","etc/events.xml",
              "etc/frontend/events.xml","etc/adminhtml/events.xml","etc/crontab.xml",
              "etc/webapi.xml","etc/frontend/routes.xml","etc/adminhtml/routes.xml",
              "etc/acl.xml","etc/config.xml","etc/frontend/sections.xml",
              "etc/queue_consumer.xml","etc/queue_topology.xml","etc/system.xml"] as $f) {
        if (file_exists("$d/$f") && filesize("$d/$f") > 80) $efeitos[] = $f;
    }
    $temLayout = is_dir("$d/view/frontend/layout") && count(glob("$d/view/frontend/layout/*")) > 0;
    $temTmpl   = is_dir("$d/view/frontend/templates") && count(glob("$d/view/frontend/templates/*")) > 0;
    $php = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (strtolower($f->getExtension()) !== "php") continue;
        if (strpos($p, "/Test/") !== false) continue;
        if (strpos($p, "/etc/") !== false) continue;
        $php++;
    }
    $total = count($efeitos) + ($temLayout ? 1 : 0) + ($temTmpl ? 1 : 0) + ($php > 0 ? 1 : 0);
    if ($total <= 1) {
        $n3[] = sprintf("%-40s efeitos=%d php=%d layout=%s tmpl=%s", $n, count($efeitos), $php,
            $temLayout ? "sim" : "nao", $temTmpl ? "sim" : "nao");
    }
}
foreach ($n3 as $s) echo "  *** $s\n";
echo $n3 ? "" : "  (nenhum)\n";

echo "\n======== D) MODULOS CUSTOM DESLIGADOS ========\n";
foreach ($custom as $n => $d) {
    if (isset($mods[$n]) && !$mods[$n]) {
        $temLayout = is_dir("$d/view/frontend/layout") && count(glob("$d/view/frontend/layout/*")) > 0;
        $temTmpl   = is_dir("$d/view/frontend/templates") && count(glob("$d/view/frontend/templates/*")) > 0;
        $tam = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) $tam += $f->getSize();
        printf("  %-40s %6.0f KB  layout=%s tmpl=%s\n", $n, $tam/1024, $temLayout?"sim":"-", $temTmpl?"sim":"-");
    }
}

echo "\n======== E) MODULOS VENDOR DESLIGADOS COM ARQUIVOS (peso) ========\n";
$lista = [];
foreach ($vendor as $n => $d) {
    if (!isset($mods[$n]) || $mods[$n]) continue;
    $tam = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) $tam += $f->getSize();
    $lista[$n] = $tam;
}
arsort($lista);
$soma = 0;
foreach ($lista as $n => $t) { $soma += $t; }
printf("  %d modulos desligados no vendor · %.1f MB no disco\n", count($lista), $soma/1048576);
foreach (array_slice($lista, 0, 12, true) as $n => $t) printf("    %-46s %7.1f MB\n", $n, $t/1048576);
' 2>&1
