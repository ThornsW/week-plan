#!/usr/bin/env bash
# 把本地插件源码同步到开发站
set -euo pipefail
SRC="$(cd "$(dirname "$0")/../plugin/week-plan" && pwd)/"
rsync -az --delete \
  --exclude '.git' --exclude '*.swp' --exclude '.DS_Store' \
  "$SRC" tx-vps:/root/wp-dev/wp-content/plugins/week-plan/
echo "已同步 → /root/wp-dev/wp-content/plugins/week-plan/"
