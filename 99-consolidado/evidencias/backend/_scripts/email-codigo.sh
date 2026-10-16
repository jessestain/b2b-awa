#!/bin/bash
# task-42 — setDesignConfig muda a AREA do DesignInterface? e quantos call sites usam adminhtml?
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) AbstractTemplate::setDesignConfig ==="
sed -n '/public function setDesignConfig/,/^    }/p' vendor/magento/module-email/Model/AbstractTemplate.php
echo "--- getDesignConfig ---"
sed -n '/public function getDesignConfig/,/^    }/p' vendor/magento/module-email/Model/AbstractTemplate.php
echo "--- getDesignParams ---"
grep -n -A 10 'function getDesignParams' vendor/magento/module-email/Model/AbstractTemplate.php

echo
echo "=== 2) Email/Model/Template::setOptions ==="
sed -n '/public function setOptions/,/^    }/p' vendor/magento/module-email/Model/Template.php

echo
echo "=== 3) os 3 setTemplateOptions do CustomerApproval ==="
grep -n -A 4 'setTemplateOptions' app/code/GrupoAwamotos/B2B/Model/CustomerApproval.php

echo
echo "=== 4) NotifyPendingApprovals ==="
grep -n -A 4 'setTemplateOptions' app/code/GrupoAwamotos/B2B/Cron/NotifyPendingApprovals.php

echo
echo "=== 5) os demais: qual area usam? ==="
for f in app/code/GrupoAwamotos/LogMonitoring/Service/NotificationService.php app/code/GrupoAwamotos/ERPIntegration/Model/Alert/EmailSender.php app/code/GrupoAwamotos/SalesIntelligence/Model/EmailSender.php app/code/GrupoAwamotos/AbandonedCart/Model/EmailSender.php app/code/GrupoAwamotos/ProductIntelligence/Helper/EmailNotifier.php app/code/Ayo/Curriculo/Model/Mail/StatusNotifier.php; do
  echo "-- $f"
  grep -n -A 4 'setTemplateOptions' "$f" | grep -E "area|store" | head -4
done
