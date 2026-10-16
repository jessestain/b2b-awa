#!/bin/bash
# task-42 — o template do B2B existe? e o que a diretiva faz com valor vazio?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem cita grupoawamotos_b2b_admin_new_customer ==="
grep -rn 'grupoawamotos_b2b_admin_new_customer' app/code/ vendor/ 2>/dev/null | head -10

echo
echo "=== 2) no banco (LIKE, sem escape) ==="
php -r '
$env = include "app/etc/env.php";
$d = $env["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
foreach ($pdo->query("SELECT template_id, template_code, template_type, orig_template_code, LENGTH(template_text) len, (LOCATE(\"design/email/header_template\", template_text)>0) usa_header FROM email_template WHERE template_code LIKE \"%b2b%\" OR template_code LIKE \"%awamotos%\" ORDER BY template_code") as $r) {
  echo "  {$r["template_id"]} | {$r["template_code"]} | type={$r["template_type"]} | orig={$r["orig_template_code"]} | len={$r["len"]} | header={$r["usa_header"]}\n";
}
'

echo
echo "=== 3) TemplateDirective::process ==="
sed -n '30,90p' vendor/magento/framework/Filter/DirectiveProcessor/TemplateDirective.php

echo
echo "=== 4) email_templates.xml do module-email (declaracoes) ==="
grep -n 'template id=' vendor/magento/module-email/etc/email_templates.xml | head -20

echo
echo "=== 5) copia do log rotacionado de 03/10: quem disparou ==="
zgrep -h 'CustomerRegisterObserver\|CustomerApproval\|TransportBuilder' var/log/exception.log.2.gz | head -3 | cut -c1-300
