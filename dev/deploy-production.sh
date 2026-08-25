#!/usr/bin/env bash
# 部署到**生产博客**。这是碰线上的操作,不要随手跑。
#
# 跑之前先按 ../TX_VPS/AGENTS.md 的规矩备份:
#   ssh tx-vps 'D=/root/blog-backups/$(date +%Y%m%d-%H%M%S)-weekplan-update; mkdir -p $D; \
#     wp db export $D/db.sql --path=/usr/local/lighthouse/softwares/wordpress --allow-root'
# 跑完记得写 ../TX_VPS/docs/ops-log.md。
set -euo pipefail

read -rp "确认要部署到生产站 thornsw.cn?(输入 yes 继续) " ok
[ "$ok" = "yes" ] || { echo "已取消"; exit 1; }

WP=/usr/local/lighthouse/softwares/wordpress
SRC="$(cd "$(dirname "$0")/../plugin/week-plan" && pwd)/"

# --exclude '*.md':webroot 里的 .md 会被当静态文件明文发给公网。
# 2026-08-25 首次部署时漏了这条,vendor/README.md 一度公网可读。
rsync -az --delete \
  --exclude '.git' --exclude '*.swp' --exclude '.DS_Store' --exclude '*.md' \
  "$SRC" "tx-vps:$WP/wp-content/plugins/week-plan/"

# rsync 是以 root 传的,不改属主 WordPress 会当成不可写
ssh tx-vps "
  chown -R www:www $WP/wp-content/plugins/week-plan
  find $WP/wp-content/plugins/week-plan -type d -exec chmod 755 {} \;
  find $WP/wp-content/plugins/week-plan -type f -exec chmod 644 {} \;
  for f in \$(find $WP/wp-content/plugins/week-plan -name '*.php'); do php -l \"\$f\" | grep -v 'No syntax errors' || true; done
  echo '语法 OK'
"

echo
echo "部署完了。请自己复核:"
echo "  1. 前台 curl -s -o /dev/null -w '%{http_code}' https://thornsw.cn/"
echo "  2. 错误日志 ssh tx-vps \"tail -20 /www/wwwlogs/thornsw.cn.error.log\""
echo "  3. 把结果写进 ../TX_VPS/docs/ops-log.md"
