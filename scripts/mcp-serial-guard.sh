#!/usr/bin/env bash
# mcp-serial-guard.sh — aplica perfil serial de .cursor/mcp.json na VPS
# Respeita manual-test-mode e enableQueue quando Cursor está ativo.
set -euo pipefail

ROOT="/home/jessessh/htdocs/srv1113343.hstgr.cloud"
MCP_JSON="$ROOT/.cursor/mcp.json"
MANUAL_FLAG="$ROOT/var/tmp/manual-test-mode"
CURSOR_FLAG="$ROOT/var/tmp/cursor-dev-active"
LOG="$ROOT/var/log/mcp_serial_guard.log"
HOSTINGER_HARD_CAP=2

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

cursor_active() {
    pgrep -u deploy -f 'cursor-server|extensionHost' >/dev/null 2>&1
}

count_extension_hosts() {
    local count=0 pid comm
    while IFS= read -r pid; do
        comm=$(ps -o comm= -p "$pid" 2>/dev/null || true)
        [[ "$comm" == node ]] && count=$((count + 1))
    done < <(pgrep -u deploy -f -- '--type=extensionHost' 2>/dev/null || true)
    echo "$count"
}

hostinger_allowed() {
    local hosts
    hosts="$(count_extension_hosts)"
    if [[ "$hosts" -le 0 ]]; then
        echo 0
        return
    fi
    if [[ "$hosts" -gt "$HOSTINGER_HARD_CAP" ]]; then
        echo "$HOSTINGER_HARD_CAP"
        return
    fi
    echo "$hosts"
}

load_config() {
    python3 - "$MCP_JSON" <<'PY'
import json
import sys

path = sys.argv[1]
defaults = {
    "concurrency": 1,
    "browserInstances": 1,
    "parallelJobs": 1,
    "timeoutSeconds": 45,
    "retries": 1,
    "enableQueue": True,
}

try:
    with open(path, encoding="utf-8") as fh:
        data = json.load(fh)
except FileNotFoundError:
    data = {}

for key, value in defaults.items():
    print(f"{key}={data.get(key, value)}")
PY
}

kill_browser_automation() {
    pkill -9 -f 'npx playwright' 2>/dev/null || true
    pkill -9 -f 'playwright test' 2>/dev/null || true
    pkill -9 -f 'playwright-mcp' 2>/dev/null || true
    pkill -9 -f 'chrome-devtools-mcp' 2>/dev/null || true
    pkill -9 -f 'testsprite-mcp' 2>/dev/null || true
    pkill -9 -f 'run-test-mcp-server' 2>/dev/null || true
    pkill -9 -f 'node -e.*playwright' 2>/dev/null || true
    pkill -9 -f 'chromium_headless_shell|chrome-headless-shell' 2>/dev/null || true
    pkill -9 -f 'playwright_chromiumdev_profile' 2>/dev/null || true
    rm -rf /tmp/playwright_chromiumdev_profile-* 2>/dev/null || true
}

trim_hostinger_mcp() {
    local max="${1:-1}"
    local -a nodes=()
    local count kill_count pid npm_pids npm_count excess i

    mapfile -t nodes < <(pgrep -f '/node_modules/.bin/hostinger-api-mcp' 2>/dev/null | sort -n)
    count=${#nodes[@]}
    if [[ "$count" -gt "$max" ]]; then
        kill_count=$((count - max))
        for ((i = 0; i < kill_count; i++)); do
            pid="${nodes[$i]}"
            pkill -TERM -P "$pid" 2>/dev/null || true
            kill -TERM "$pid" 2>/dev/null || true
        done
        sleep 1
        for ((i = 0; i < kill_count; i++)); do
            kill -9 "${nodes[$i]}" 2>/dev/null || true
        done
        log "trim hostinger-mcp nodes: $count -> $max"
    fi

    npm_pids=$(pgrep -f 'npm exec hostinger-api-mcp' 2>/dev/null || true)
    if [[ -n "$npm_pids" ]]; then
        npm_count=$(echo "$npm_pids" | wc -w)
        if [[ "$npm_count" -gt "$max" ]]; then
            excess=$((npm_count - max))
            echo "$npm_pids" | head -n "$excess" | xargs -r kill -9 2>/dev/null || true
            log "trim hostinger-mcp npm: $npm_count -> $max"
        fi
    fi

    if [[ "$max" -le 0 ]]; then
        pgrep -f 'sh -c hostinger-api-mcp' 2>/dev/null | xargs -r kill -9 2>/dev/null || true
    else
        pgrep -f 'sh -c hostinger-api-mcp' 2>/dev/null | head -n -"$max" 2>/dev/null | xargs -r kill -9 2>/dev/null || true
    fi
}

trim_processes() {
    local pattern="$1"
    local max="$2"
    local label="$3"

    [[ "$max" -ge 1 ]] || return 0

    local pids count excess
    pids=$(pgrep -f "$pattern" 2>/dev/null || true)
    [[ -n "$pids" ]] || return 0

    count=$(echo "$pids" | wc -w)
    if [[ "$count" -gt "$max" ]]; then
        excess=$((count - max))
        echo "$pids" | head -n "$excess" | xargs -r kill -TERM 2>/dev/null || true
        sleep 1
        echo "$pids" | head -n "$excess" | xargs -r kill -9 2>/dev/null || true
        log "trim $label: $count -> $max"
    fi
}

declare -A CFG=()
while IFS='=' read -r key value; do
    CFG["$key"]="$value"
done < <(load_config)

BROWSER_MAX="${CFG[browserInstances]:-1}"
PARALLEL_MAX="${CFG[parallelJobs]:-1}"
ENABLE_QUEUE="${CFG[enableQueue]:-true}"
HOSTINGER_MAX="$(hostinger_allowed)"

if [[ -f "$MANUAL_FLAG" ]]; then
    kill_browser_automation
    trim_hostinger_mcp "$HOSTINGER_MAX"
    log "manual-test-mode ON — automação browser bloqueada hostinger=${HOSTINGER_MAX}"
    exit 0
fi

if [[ "$ENABLE_QUEUE" == "True" || "$ENABLE_QUEUE" == "true" || "$ENABLE_QUEUE" == "1" ]] && cursor_active; then
    kill_browser_automation
    trim_processes 'playwright test' 0 'playwright-cli'
    trim_hostinger_mcp "$HOSTINGER_MAX"
    log "enableQueue + cursor ativo — fila serial (browser bloqueado) hostinger=${HOSTINGER_MAX}"
    exit 0
fi

trim_processes 'chrome-headless-shell' "$BROWSER_MAX" 'chrome-headless'
trim_processes 'playwright test' "$PARALLEL_MAX" 'playwright-cli'
trim_processes 'playwright-mcp|chrome-devtools-mcp' 0 'mcp-browser'
trim_hostinger_mcp "$HOSTINGER_MAX"

CHROME_COUNT=$(pgrep -cf 'chrome-headless-shell' 2>/dev/null || echo 0)
PW_COUNT=$(pgrep -cf 'playwright test|npx playwright' 2>/dev/null || echo 0)
log "ok browser=$CHROME_COUNT/${BROWSER_MAX} playwright=$PW_COUNT/${PARALLEL_MAX} queue=${ENABLE_QUEUE} hostinger=${HOSTINGER_MAX}"
