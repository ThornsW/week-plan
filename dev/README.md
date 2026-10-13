# 开发环境

开发用的 WordPress **跑在博客服务器上**，和生产博客完全隔离，通过 SSH 隧道访问。
本机不需要装 Docker、PHP 或数据库。

## 为什么这样搭

- 版本不是「对齐线上」，而是**就是线上那一份**：PHP 8.3.22、MariaDB 10.6.20、WordPress 7.0.2 —— 同一台机器上的同一批二进制。
- 不碰 Nginx、不加 vhost、不加证书、不改安全组、不加 systemd 服务。
- 只监听 `127.0.0.1`，公网访问不到。
- 生产博客的目录和数据库一根手指都不碰。

## 现状

| 项目 | 值 |
|---|---|
| 站点目录 | `/root/wp-dev`（约 92 MB） |
| 数据库 | `wpdev`（宝塔面板创建，独立于博客库） |
| WordPress | 7.0.2 |
| 访问地址 | `http://localhost:8080/wp-admin/`（需先开隧道） |
| 后台账号 | `dev` |
| 时区 | `Asia/Hong_Kong`，与生产博客一致 |
| 周起始 | 1（周一），与生产博客一致 |
| 插件目录 | `/root/wp-dev/wp-content/plugins/week-plan/` |

开发期开关：`WP_DEBUG`、`WP_DEBUG_LOG` 已开；`DISABLE_WP_CRON` 已开
（PHP 内置服务器是单线程的，WordPress 定时任务会向自己发请求而卡住）。

## 日常使用

开发站使用独立目录和数据库，不接触生产环境。实现任务需要在真实 WordPress 中验证时，
可以连续执行启动、同步、检查、修复和重试；完成后关闭服务器。

```bash
./dev/server.sh start      # 启动远端开发服务器
./dev/tunnel.sh            # 开隧道，保持终端开着
# 浏览器打开 http://localhost:8080/wp-admin/

./dev/sync.sh              # 改完代码同步插件（自动排除 *.md）
./dev/server.sh debuglog   # 看 PHP 报错
./dev/server.sh stop       # 不开发时停掉，省内存
```

重置假数据（**会清空所有待办和排期**，只在开发站用）：

```bash
scp dev/seed.php tx-vps:/tmp/seed.php
ssh tx-vps 'WP_CLI_PHP_ARGS="-d memory_limit=256M" wp eval-file /tmp/seed.php --path=/root/wp-dev --allow-root'
```

## 注意

- 服务器内存 1.7 GiB 无 swap，可用约 550 MiB。开发服务器进程约占 110 MB，**不开发时记得 `stop`**。
- `wp-cli` 的 PHP 内存上限是 128M，解压 WordPress 会爆；用 `tar` 解压绕开。
  其它 wp-cli 命令若报内存不足，加 `WP_CLI_PHP_ARGS="-d memory_limit=256M"`。
- PHP 内置服务器不做 URL 重写，固定链接在这里不正常 —— 不影响我们，界面全在 wp-admin。
- 数据库密码只存在于服务器的 `wp-config.php`（权限 600），不写入本项目任何文件。

## 完全撤销

```bash
ssh tx-vps 'pkill -f "php -S 127.0.0.1:8080"; rm -rf /root/wp-dev /root/wp-dev-server.log'
```
再去宝塔面板删掉数据库 `wpdev`。服务器恢复原状，零残留。
