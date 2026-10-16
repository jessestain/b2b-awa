#!/bin/bash
# task-42 — outros chamadores, impacto e o declarado no XML.
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) quem usa AREA_ADMINHTML em e-mail no app/code ==="
grep -rn "AREA_ADMINHTML" app/code/ --include=*.php | head -20

echo
echo "=== 2) quem chama setTemplateOptions no app/code ==="
grep -rn "setTemplateOptions" app/code/ --include=*.php | head -20

echo
echo "=== 3) declaracao xml do template do B2B ==="
sed -n '1,30p' app/code/GrupoAwamotos/B2B/etc/email_templates.xml

echo
echo "=== 4) existem os arquivos do B2B? ==="
find app/code/GrupoAwamotos/B2B/view -name '*.html' 2>/dev/null

echo
echo "=== 5) o erro tambem aparece em 03/10? quem disparou ==="
zgrep -h '#1[0-9] /home' var/log/exception.log.2.gz 2>/dev/null | grep -o 'app/code/[^(]*' | sort | uniq -c | head -20

echo
echo "=== 6) o catch do B2B registra falha? ==="
grep -rn 'notifyAdminNewCustomer error' var/log/*.log 2>/dev/null | tail -5
echo "--- e no log da B2B? ---"
ls var/log/ | grep -i 'b2b\|customer\|mail\|smtp' | head

echo
echo "=== 7) MagePal SMTP configurado? ==="
grep -rn 'smtp' app/etc/env.php 2>/dev/null | head -5
php bin/magento config:show smtp 2>&1 | head -20
