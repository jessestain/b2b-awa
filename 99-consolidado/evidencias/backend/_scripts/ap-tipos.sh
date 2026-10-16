#!/bin/bash
# task-74 — os valores concretos de $type (o limite de N apos o dedupe).
set -u
cd /home/jessessh/htdocs/srv1113343.hstgr.cloud

echo "=== ErpAnalyzer: chamadas de createAndSaveAlert ==="
sed -n '235,262p' app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/ErpAnalyzer.php

echo
echo "=== PerformanceAnalyzer: chamadas de createAndSaveAlert ==="
sed -n '254,282p' app/code/GrupoAwamotos/LogMonitoring/Service/LogAnalyzer/PerformanceAnalyzer.php
