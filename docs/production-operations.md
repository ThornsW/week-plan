# week-plan 生产操作

只在任务明确涉及 `thornsw.cn` 的部署、激活、数据库或线上安全变更时读取本文件。
服务器的实时状态、通用运维规范和操作记录以
[`../../TX_VPS/AGENTS.md`](../../TX_VPS/AGENTS.md) 为准。

## 安全边界

- 不读取、展示或保存生产 `wp-config.php`、宝塔敏感配置、数据库凭据、token 或 SSL 私钥。
- 会话中提供的凭据只用于当次已授权操作，不写入项目文件、脚本、日志或回复。
- Nginx 只执行 `.php` 文件；站点目录中的其他文件可能被公网原样读取。不要部署
  `.md`、`.txt`、`.bak`、`.old`、`.sql`、`.zip` 等文件。
- 不在站点目录原地创建备份。备份存放在 `/root/blog-backups/`。
- 每个 PHP 入口必须先检查 `ABSPATH`，避免文件被直接执行。

`dev/sync.sh` 和 `dev/deploy-production.sh` 已排除 Markdown，但脚本过滤不能替代部署后检查。

## 生产变更流程

生产写操作、插件激活和安全相关变更需要用户明确授权。获得授权后：

1. 只读核实 WordPress 路径、插件版本、数据库状态和可用资源；实时状态优先于历史文档。
2. 使用 `wp db export` 将生产数据库备份到 `/root/blog-backups/`，并记录回滚所需信息。
3. 一次执行一个可验证的变更。插件文件部署入口是 `dev/deploy-production.sh`。
4. 检查 PHP 语法、站点 HTTP 响应、错误日志、插件后台页和前台隔离。
5. 确认站点插件目录没有不应公开的文件，并抽查 PHP 文件直接访问时不返回内容。
6. 把变更、证据和回滚方式写入 `../../TX_VPS/docs/ops-log.md`。

插件版本用于 CSS/JavaScript 缓存刷新。修改静态资源时同步更新
`WEEKPLAN_VERSION` 和插件头部版本号。

## 开发站

隔离开发站也位于服务器上，但不接触生产目录和生产数据库。日常命令、端口、资源限制
和清理方法见 [`../dev/README.md`](../dev/README.md)。不用开发时关闭 PHP 内置服务器，
避免在 1.7 GiB、无 swap 的主机上长期占用内存。
