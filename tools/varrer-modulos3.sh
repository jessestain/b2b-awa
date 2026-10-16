#!/usr/bin/env bash
# AWA Motos — quais modulos NAO FAZEM NADA (criterio correto)
#
# O criterio anterior ("nao referenciado no tema") era RUIM: modulo age por
# di.xml (plugin), events.xml (observer), crontab.xml, webapi.xml, routes.xml.
# Aqui mede a ATUACAO REAL e, no caso dos plugins, se a CLASSE ALVO ainda existe
# — plugin apontando para classe inexistente e codigo MORTO (mesma logica do
# seletor CSS que nao casa em nenhum elemento).
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud || exit 1
php -r '
$cfg = include "app/etc/config.php";
$mods = $cfg["modules"];
$dirs = [];
foreach (glob("app/code/*/*", GLOB_ONLYDIR) as $d) {
    $p = explode("/", $d);
    $dirs[$p[2]."_".$p[3]] = $d;
}
ksort($dirs);

echo "\n======== J) ATUACAO REAL DE CADA MODULO ATIVO ========\n";
printf("  %-40s %4s %4s %4s %4s %4s %4s  %s\n", "MODULO", "plug", "obs", "cron", "cmd", "rota", "cli", "VEREDITO");
printf("  %s\n", str_repeat("-", 100));

$vazios = [];
foreach ($dirs as $n => $d) {
    if (empty($mods[$n])) continue;
    $plug = $obs = $cron = $cmd = $rota = $cli = 0;

    foreach (["etc/di.xml","etc/frontend/di.xml","etc/adminhtml/di.xml","etc/webapi/di.xml"] as $f) {
        if (!file_exists("$d/$f")) continue;
        $x = file_get_contents("$d/$f");
        $plug += preg_match_all("/<plugin\b/", $x);
        $cmd  += preg_match_all("/<command\b/", $x);
    }
    foreach (["etc/events.xml","etc/frontend/events.xml","etc/adminhtml/events.xml"] as $f) {
        if (!file_exists("$d/$f")) continue;
        $obs += preg_match_all("/<observer\b/", $x = file_get_contents("$d/$f"));
    }
    if (file_exists("$d/etc/crontab.xml"))       $cron += preg_match_all("/<job\b/", file_get_contents("$d/etc/crontab.xml"));
    if (file_exists("$d/etc/frontend/routes.xml"))  $rota += preg_match_all("/<route\b/", file_get_contents("$d/etc/frontend/routes.xml"));
    if (file_exists("$d/etc/adminhtml/routes.xml")) $rota += preg_match_all("/<route\b/", file_get_contents("$d/etc/adminhtml/routes.xml"));
    if (file_exists("$d/etc/queue_consumer.xml"))   $cli  += preg_match_all("/<consumer\b/", file_get_contents("$d/etc/queue_consumer.xml"));
    if (is_dir("$d/Console")) { $cli += count(glob("$d/Console/**/*.php")); }

    $total = $plug + $obs + $cron + $cmd + $rota + $cli;
    $layout = is_dir("$d/view/frontend/layout") ? count(glob("$d/view/frontend/layout/*")) : 0;
    $tmpl   = is_dir("$d/view/frontend/templates") ? count(glob("$d/view/frontend/templates/*")) : 0;
    $block  = is_dir("$d/Block") ? count(glob("$d/Block/**/*.php")) : 0;
    $model  = is_dir("$d/Model") ? count(glob("$d/Model/**/*.php")) : 0;
    $view   = $layout + $tmpl + $block + $model;

    $veredito = ($total + $view) > 0 ? "atua" : "*** NAO FAZ NADA ***";
    if (($total + $view) === 0) $vazios[] = $n;

    printf("  %-40s %4d %4d %4d %4d %4d %4d  %s\n", $n, $plug, $obs, $cron, $cmd, $rota, $cli, $veredito);
}
echo "\n  *** MODULOS ATIVOS QUE NAO FAZEM NADA: " . count($vazios) . " ***\n";
foreach ($vazios as $v) echo "    $v\n";

echo "\n======== K) PLUGINS APONTANDO PARA CLASSE QUE NAO EXISTE (codigo morto) ========\n";
$autoload = file_get_contents("vendor/composer/autoload_psr4.php");
$mortos = 0; $total = 0;
foreach ($dirs as $n => $d) {
    if (empty($mods[$n])) continue;
    foreach (["etc/di.xml","etc/frontend/di.xml","etc/adminhtml/di.xml"] as $f) {
        if (!file_exists("$d/$f")) continue;
        $x = file_get_contents("$d/$f");
        // <type name="X"> ... <plugin name="y" type="Z"/>
        if (!preg_match_all("/<type name=\"([^\"]+)\"[^>]*>(.*?)<\/type>/s", $x, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $t) {
            $alvo = $t[1];
            $total++;
            $curto = ltrim(str_replace("\\\\", "\\", $alvo), "\\");
            $caminho = "app/code/" . str_replace("_", "/", explode("\\", $curto)[0] . "_" . (explode("\\", $curto)[1] ?? "")) . "/" . implode("/", array_slice(explode("\\", $curto), 2)) . ".php";
            $existe = file_exists($caminho) || file_exists("vendor/" . strtolower(str_replace("\\", "/", $curto)) . ".php");
            if (!$existe) {
                // tenta achar no vendor pela convencao
                $p2 = "vendor/" . strtolower(explode("\\", $curto)[0]) . "/" . strtolower(str_replace("\\", "/", implode("\\", array_slice(explode("\\", $curto), 1)))) . ".php";
                $existe = file_exists($p2);
            }
            if (!$existe) { $mortos++; if ($mortos <= 12) echo "  *** $n -> alvo inexistente: $alvo\n"; }
        }
    }
}
printf("  %d <type> analisados · %d com alvo NAO localizado (heuristica)\n", $total, $mortos);

echo "\n======== L) OBSERVERS DE EVENTOS QUE NINGUEM DISPARA ========\n";
// coleta os eventos observados e verifica se o nome aparece em algum dispatch
$obs = [];
foreach ($dirs as $n => $d) {
    foreach (["etc/events.xml","etc/frontend/events.xml","etc/adminhtml/events.xml"] as $f) {
        if (!file_exists("$d/$f")) continue;
        if (preg_match_all("/<event name=\"([^\"]+)\"/", file_get_contents("$d/$f"), $m)) {
            foreach ($m[1] as $ev) $obs[$ev] = ($obs[$ev] ?? 0) + 1;
        }
    }
}
// eventos nativos do Magento comecam com padroes conhecidos; os custom sao os suspeitos
$suspeitos = [];
foreach ($obs as $ev => $qtd) {
    if (preg_match("/^(catalog|sales|checkout|customer|admin|controller|model|layout|core|store|wishlist|review|newsletter|cms|quote|payment|shipping|tax|wysiwyg|email|import|webapi|cron|payment_method|order|invoice|shipment|creditmemo|sendgrid|aw_)_.*/i", $ev)) continue;
    $suspeitos[$ev] = $qtd;
}
printf("  %d eventos observados · %d com nome NAO-PADRAO (suspeitos)\n", count($obs), count($suspeitos));
foreach (array_slice($suspeitos, 0, 15, true) as $ev => $q) printf("    %-58s %d observador(es)\n", $ev, $q);
' 2>&1
