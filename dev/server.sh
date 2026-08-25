#!/usr/bin/env bash
# 控制服务器上的开发用 WordPress(PHP 内置服务器,只监听 127.0.0.1)
set -euo pipefail

HOST=tx-vps
ROOT=/root/wp-dev
PORT=8080
PATTERN="php -S 127.0.0.1:${PORT}"

case "${1:-status}" in
  start)
    ssh "$HOST" "pgrep -f '$PATTERN' >/dev/null && echo '已在运行' && exit 0
      cd $ROOT && nohup php -S 127.0.0.1:$PORT -t $ROOT > /root/wp-dev-server.log 2>&1 &
      sleep 2; ss -tulpn | grep -w $PORT"
    ;;
  stop)
    ssh "$HOST" "pkill -f '$PATTERN' && echo '已停止' || echo '本来就没在跑'"
    ;;
  status)
    ssh "$HOST" "ss -tulpn | grep -w $PORT || echo '未运行'
      curl -s -o /dev/null -w 'wp-login.php -> HTTP %{http_code}\n' http://127.0.0.1:$PORT/wp-login.php 2>/dev/null || true"
    ;;
  log)
    ssh "$HOST" "tail -n ${2:-40} /root/wp-dev-server.log"
    ;;
  debuglog)
    ssh "$HOST" "tail -n ${2:-40} $ROOT/wp-content/debug.log 2>/dev/null || echo '(暂无 debug.log)'"
    ;;
  *)
    echo "用法: $0 {start|stop|status|log [行数]|debuglog [行数]}" >&2
    exit 1
    ;;
esac
