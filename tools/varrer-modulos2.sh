#!/usr/bin/env bash
# AWA Motos — varredura de modulos: SOBREPOSICAO, SINTAXE e INTEGRIDADE
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud || exit 1
php -r '
$cfg = include "app/etc/config.php";
$mods = $cfg["modules"];
$custom = [];
foreach (glob("app/code/*/*", GLOB_ONLYDIR) as $d) {
    $p = explode("/", $d);
    $custom[$p[2]."_".$p[3]] = $d;
}
ksort($custom);

echo "\n======== F) SOBREPOSICAO DE RESPONSABILIDADE ========\n";
$grupos = [
  "CatalogFix / correcao de catalogo" => ["Awa_CatalogFix","GrupoAwamotos_CatalogFix"],
  "Schema / dados estruturados"       => ["GrupoAwamotos_SchemaEnhancer","GrupoAwamotos_SchemaOrg"],
  "Dashboard"                         => ["Awa_DashboardFix","Awa_RealTimeDashboard"],
  "Busca (9 modulos!)"                => ["Mirasvit_Search","Mirasvit_SearchAutocomplete","Mirasvit_SearchElastic","Mirasvit_SearchGraphQl","Mirasvit_SearchLanding","Mirasvit_SearchMysql","Mirasvit_SearchReport","Mirasvit_SearchSphinx","Mirasvit_SearchUltimate","Rokanthemes_SearchSuiteAutocomplete","Rokanthemes_SearchbyCat"],
  "Visual fixes"                      => ["AWA_VisualFixes","GrupoAwamotos_LayoutFix"],
  "Meta (5 modulos)"                  => ["Meta_BusinessExtension","Meta_Catalog","Meta_Conversion","Meta_Promotions","Meta_Sales"],
  "Chat / atendimento"                => ["GrupoAwamotos_AiAssistant","GrupoAwamotos_LiveChat","GrupoAwamotos_WhatsAppCommerce"],
  "Relatorio"                         => ["Mirasvit_Report","Mirasvit_ReportApi"],
];
foreach ($grupos as $rot => $lista) {
    $ativos = 0; $desl = 0; $detalhe = [];
    foreach ($lista as $n) {
        if (!array_key_exists($n, $mods)) { $detalhe[] = "$n(NAO NO CONFIG)"; continue; }
        if ($mods[$n]) { $ativos++; $detalhe[] = "$n(ATIVO)"; } else { $desl++; $detalhe[] = "$n(desligado)"; }
    }
    if (count($lista) > 1) {
        printf("  %-32s ativos=%d desligados=%d\n", $rot, $ativos, $desl);
        printf("      %s\n", implode(" · ", $detalhe));
    }
}

echo "\n======== G) ERROS DE SINTAXE PHP NOS MODULOS CUSTOM ========\n";
$erros = 0; $checados = 0;
foreach ($custom as $n => $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (strtolower($f->getExtension()) !== "php") continue;
        $p = $f->getPathname();
        $checados++;
        exec("php -l " . escapeshellarg($p) . " 2>&1", $out, $rc);
        if ($rc !== 0) { echo "  *** $p\n"; $erros++; }
        $out = [];
    }
}
printf("  %d arquivos PHP checados · %d com erro\n", $checados, $erros);

echo "\n======== H) INTEGRIDADE: module.xml vs composer.json vs config ========\n";
$problemas = 0;
foreach ($custom as $n => $d) {
    $temReg = file_exists("$d/registration.php");
    $temMod = file_exists("$d/etc/module.xml");
    $temCom = file_exists("$d/composer.json");
    $flags = [];
    if (!$temReg) $flags[] = "SEM registration.php";
    if (!$temMod) $flags[] = "SEM etc/module.xml";
    if (!$temCom) $flags[] = "SEM composer.json";
    if (!array_key_exists($n, $mods)) $flags[] = "FORA DO CONFIG";
    if ($flags) { printf("  *** %-40s %s\n", $n, implode(", ", $flags)); $problemas++; }
}
echo $problemas ? "" : "  (nenhum problema)\n";

echo "\n======== I) MODULOS ATIVOS QUE NINGUEM REFERENCIA NO LAYOUT/DI ========\n";
// modulos cujo nome nao aparece em nenhum layout/di/template do tema ou de outro modulo
$refs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("app/design/frontend/AWA_Custom/ayo_home5_child", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $e = strtolower($f->getExtension());
    if (!in_array($e, ["xml","phtml","php"])) continue;
    $refs[] = file_get_contents($f->getPathname());
}
$todo = implode("\n", $refs);
$semRef = [];
foreach ($custom as $n => $d) {
    if (empty($mods[$n])) continue;
    $curto = explode("_", $n)[1];
    if (strpos($todo, $n) === false && strpos($todo, $curto) === false) $semRef[] = $n;
}
printf("  %d modulos ativos sem NENHUMA referencia no tema\n", count($semRef));
foreach (array_slice($semRef, 0, 20) as $s) echo "    $s\n";
' 2>&1
