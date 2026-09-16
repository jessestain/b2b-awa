#!/usr/bin/env bash
# mcp-performance.sh — diagnóstico e limpeza MCP
# MCP nativo Cursor + Hostinger API MCP (leve, sem Chrome).
set -euo pipefail

ROOT="/home/jessessh/htdocs/srv1113343.hstgr.cloud"
CURSOR_MCP_GLOBAL="/home/deploy/.cursor/mcp.json"
CURSOR_MCP="$ROOT/.cursor/mcp.json"
PROJECT_MCP="$ROOT/.vscode/mcp.json"
CDP_SERVICE="chrome-cdp-9222.service"
LIGHT_MARKER="$ROOT/.vscode/.mcp-profile-light"

status() {
  set +e
  echo "=== Diagnóstico MCP / VPS ==="
  echo
  free -h | awk '/^Mem:/{print "RAM: "$3" usada / "$2" total ("$7" disponível)"}'
  uptime | sed 's/.*load average/load average/'
  echo

  local ps_data chrome_mb mcp_mb cursor_mb chrome_count ext_hosts
  ps_data=$(ps aux 2>/dev/null)
  chrome_mb=$(ps -C chrome -o rss= 2>/dev/null | awk '{s+=$1} END {printf "%.0f", s/1024+0}')
  mcp_mb=$(echo "$ps_data" | grep -E 'playwright-mcp|chrome-devtools-mcp|testsprite|run-test-mcp|mcp-server-filesystem|mcp-server|hostinger-api-mcp|codacy-mcp' | grep -v grep | awk '{s+=$6} END {printf "%.0f", s/1024+0}')
  cursor_mb=$(echo "$ps_data" | grep -E 'cursor-server|vscode-server' | grep -v grep | awk '{s+=$6} END {printf "%.0f", s/1024+0}')
  chrome_count=$(echo "$ps_data" | grep -c '[c]hrome' 2>/dev/null || true)
  chrome_count=${chrome_count:-0}
  ext_hosts=$(echo "$ps_data" | grep -F 'type=extensionHost' | grep -vc grep || true)
  ext_hosts=${ext_hosts:-0}

  echo "Chrome headless:     ${chrome_mb:-0} MB (${chrome_count} processos)"
  echo "MCP externo (Node):  ${mcp_mb:-0} MB"
  echo "Cursor/VS Code:      ${cursor_mb:-0} MB"
  echo "Extension hosts:     ${ext_hosts}"
  echo
  echo "Perfil ativo:        Cursor + Hostinger + Codacy MCP (serial)"
  echo "Config projeto:      $CURSOR_MCP"
  echo "MCP nativo:          cursor-ide-browser (sob demanda, 1 instância)"
  echo "MCP externo:         hostinger-mcp (~128 MB) + codacy (~192 MB)"
  if [[ -f "$CURSOR_MCP" ]]; then
    python3 - "$CURSOR_MCP" <<'PY' 2>/dev/null || true
import json, sys
with open(sys.argv[1], encoding="utf-8") as fh:
    c = json.load(fh)
print(
    "Serial:              "
    f"concurrency={c.get('concurrency', 1)} "
    f"browser={c.get('browserInstances', 1)} "
    f"parallel={c.get('parallelJobs', 1)} "
    f"queue={c.get('enableQueue', True)}"
)
PY
  fi

  if systemctl is-active --quiet "$CDP_SERVICE" 2>/dev/null; then
    echo "CDP systemd:         ATIVO (~500 MB — rode: $0 cleanup)"
  else
    echo "CDP systemd:         parado"
  fi
  echo

  echo "Top CPU agora:"
  ps aux --sort=-%cpu 2>/dev/null | awk 'NR==1 || ($3>=5 && NR<=8){print}' | head -8
  set -e
}

apply_hostinger_mcp() {
  mkdir -p "$(dirname "$CURSOR_MCP")"
  cat > "$CURSOR_MCP" <<'EOF'
{
  "_comment": "Perfil serial Hostinger — Remote-SSH workspace AWA Motos. Recarregue: Settings → MCP → Reload",
  "concurrency": 1,
  "browserInstances": 1,
  "parallelJobs": 1,
  "timeoutSeconds": 45,
  "retries": 1,
  "enableQueue": true,
  "mcpServers": {
    "hostinger-mcp": {
      "command": "/home/deploy/.cursor/bin/hostinger-mcp.sh",
      "args": [],
      "env": {
        "NODE_OPTIONS": "--max-old-space-size=128"
      }
    },
    "codacy": {
      "command": "/home/deploy/.cursor/bin/codacy-mcp.sh",
      "args": [],
      "env": {
        "NODE_OPTIONS": "--max-old-space-size=192"
      }
    }
  }
}
EOF
  # Global vazio: evita user-hostinger-mcp + project-hostinger-mcp ao mesmo tempo
  cat > "$CURSOR_MCP_GLOBAL" <<'EOF'
{
  "_comment": "Vazio de propósito. Hostinger MCP só no projeto (.cursor/mcp.json).",
  "concurrency": 1,
  "browserInstances": 1,
  "parallelJobs": 1,
  "timeoutSeconds": 45,
  "retries": 1,
  "enableQueue": true,
  "mcpServers": {}
}
EOF
  cat > "$PROJECT_MCP" <<'EOF'
{
	"_comment": "Legado VS Code — canônico: .cursor/mcp.json do projeto. Servers vazios anti-duplicata.",
	"servers": {},
	"inputs": []
}
EOF
  touch "$LIGHT_MARKER"
  sudo systemctl stop "$CDP_SERVICE" 2>/dev/null || true
  sudo systemctl disable "$CDP_SERVICE" 2>/dev/null || true
}

cleanup_quiet() {
  pkill -f 'playwright-mcp' 2>/dev/null || true
  pkill -f 'mcp-server-filesystem' 2>/dev/null || true
  pkill -f 'chrome-devtools-mcp' 2>/dev/null || true
  pkill -f 'testsprite-mcp' 2>/dev/null || true
  pkill -f 'run-test-mcp-server' 2>/dev/null || true
  pkill -f 'chromium_headless_shell|chrome-headless-shell|playwright_chromiumdev_profile' 2>/dev/null || true
  pkill -f 'chrome-headless-shell' 2>/dev/null || true
  pkill -f 'node -e.*playwright' 2>/dev/null || true
  pkill -f '/tmp/verify' 2>/dev/null || true
  sleep 1
  pgrep -af 'mcp-chrome' 2>/dev/null | awk '{print $1}' | xargs -r kill -9 2>/dev/null || true
  pgrep -af 'chrome-headless-shell' 2>/dev/null | awk '{print $1}' | xargs -r kill -9 2>/dev/null || true
  rm -rf "$ROOT/.playwright-mcp"/* 2>/dev/null || true
}

cleanup() {
  echo "Encerrando MCPs externos e processos órfãos..."
  cleanup_quiet
  apply_hostinger_mcp
  sudo systemctl stop "$CDP_SERVICE" 2>/dev/null || true
  echo "Perfil Hostinger + Codacy MCP aplicado. Recarregue: Settings → MCP → Reload"
  echo "Feito. Rode 'status' para confirmar."
}

case "${1:-status}" in
  status|st)    status ;;
  heavy-on|on|heavy-off|off|light|hostinger)
    apply_hostinger_mcp
    cleanup_quiet
    echo "Perfil Cursor + Hostinger + Codacy MCP. Recarregue: Settings → MCP → Reload"
    ;;
  cleanup|clean) cleanup ;;
  *)
    echo "Uso: $0 {status|cleanup|light}"
    echo
    echo "  status   — RAM, Chrome, MCPs ativos"
    echo "  cleanup  — Mata MCPs externos/Chrome órfãos"
    echo "  light    — Garante perfil somente Cursor (padrão)"
    exit 1
    ;;
esac
