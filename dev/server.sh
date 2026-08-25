#!/usr/bin/env bash
# 控制服务器上的开发用 WordPress(PHP 内置服务器,只监听 127.0.0.1)
set -euo pipefail

HOST=tx-vps
ROOT=/root/wp-dev
PORT=8080
# 方括号不是笔误:pkill -f 扫的是整条命令行,而这些命令是被 ssh 包在一层
# `bash -c "...pkill -f '...'..."` 里送过去的。写成 `php -S` 会先匹配到那层
# bash 自己,把自己的 shell 杀掉;`[p]hp` 匹配得到真正的 php 进程,匹配不到
# 写着 `[p]hp` 的那条命令行。
PATTERN="[p]hp -S 127.0.0.1:${PORT}"

case "${1:-status}" in
  start)
    # 「在不在跑」按端口有没有人听来判断,不按进程名。start 这条命令行里本来
    # 就写着 `php -S 127.0.0.1:8080`(下面那行),用 pgrep 一定会匹配到自己,
    # 于是永远报「已在运行」却什么都没起 —— 2026-08-25 踩过。
    ssh "$HOST" "ss -tln | grep -qw $PORT && echo '已在运行' && exit 0
      # 外面这层小括号是必须的:光 nohup、甚至 setsid + 三个 fd 全重定向都不够,
      # ssh 仍会等着后台的 php 才肯返回,start 就挂在那儿不动(服务其实已经起来
      # 了)。套一层子 shell 让 php 立刻被 init 收养,ssh 才会当场返回。
      cd $ROOT && ( setsid php -S 127.0.0.1:$PORT -t $ROOT > /root/wp-dev-server.log 2>&1 < /dev/null & )
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
