#!/usr/bin/env bash
# 把本地插件源码同步到开发站。
#
# --exclude '*.md' 不是洁癖:webroot 里的 .md 文件会被 Nginx 当静态文件明文
# 发给公网(不以 .php 结尾就不会被执行)。文档留在仓库里,不上服务器。
set -euo pipefail
SRC="$(cd "$(dirname "$0")/../plugin/week-plan" && pwd)/"
rsync -az --delete \
  --exclude '.git' --exclude '*.swp' --exclude '.DS_Store' --exclude '*.md' \
  "$SRC" tx-vps:/root/wp-dev/wp-content/plugins/week-plan/
echo "已同步 → /root/wp-dev/wp-content/plugins/week-plan/"
