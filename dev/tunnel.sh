#!/usr/bin/env bash
# 打开到开发站的 SSH 隧道。保持这个终端开着,浏览器访问 http://localhost:8080/wp-admin/
echo "隧道已开:http://localhost:8080/wp-admin/   (Ctrl+C 关闭)"
exec ssh -N -L 8080:127.0.0.1:8080 tx-vps
