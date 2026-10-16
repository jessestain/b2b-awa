#!/bin/bash
# task-67 — quando o MySQL-MQ marca RETRY_REQUIRED? (e quem chama)
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== 1) ResourceModel/Queue.php 235-300 ==="
sed -n '235,300p' vendor/magento/module-mysql-mq/Model/ResourceModel/Queue.php

echo
echo "=== 2) quem chama esse metodo ==="
grep -rn 'rejectMessage\|RETRY_REQUIRED' vendor/magento/module-mysql-mq/Model/Driver/Queue.php vendor/magento/framework-message-queue/ --include=*.php 2>/dev/null | grep -v Test | head -15

echo
echo "=== 3) Driver/Queue.php: push/ack/reject ==="
grep -n 'function \|COMPLETE\|RETRY' vendor/magento/module-mysql-mq/Model/Driver/Queue.php | head -25

echo
echo "=== 4) quem chama reject/retry quando o handler lanca ==="
grep -rn 'reject\|retry' vendor/magento/framework/MessageQueue/Consumer.php vendor/magento/framework-message-queue/Consumer.php 2>/dev/null | head -10
find vendor/magento/framework-message-queue -name 'Consumer.php' | head

echo
echo "=== 5) CallbackInvoker: lanca para cima? ==="
grep -rn 'catch\|throw' vendor/magento/framework-message-queue/CallbackInvoker.php 2>/dev/null | head -10
