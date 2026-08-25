# AGENTS.md

## 项目定位

`week-plan` 是一个 WordPress 插件，把待办按天排进一周的计划表。界面只在 wp-admin 后台，单用户，最终部署在个人博客服务器 `thornsw.cn` 上。

设计决定全部记录在 [docs/design-spec.md](docs/design-spec.md)，共 35 条。**实现以规格文档为准；实现与文档冲突时，先改文档再改代码。**

## 项目边界

- 本项目只放插件源码、设计文档和开发环境脚本。
- 博客服务器的运维记录属于相邻项目 `../TX_VPS/`，不在这里重复。涉及服务器状态的事实以那边的 `docs/server-inventory.md` 为准，但**记录可能过期，重要信息必须当场只读核实**。
- 旧的 Memo 桌面应用在 `../Memo/`，已冻结。两者无共享代码、无数据迁移，不要从那里复制实现。

## 核心约定

命名一旦定下不再改动，文件夹名、插件名、线上部署目录名三者必须完全一致：

| 项目 | 值 |
|---|---|
| 插件目录 / 线上部署路径 | `wp-content/plugins/week-plan/` |
| 插件主文件 | `week-plan.php` |
| PHP 函数、常量前缀 | `weekplan_` —— **不可用 `wp_`**，那是 WordPress 核心保留前缀 |
| 待办内容类型 | `weekplan_todo` |
| 标签分类 | `weekplan_tag` —— **必须是自有分类**，复用博客文章标签会让待办标签出现在公开页面 |
| 排期表 | `{$wpdb->prefix}weekplan_schedule` |

## 代码规范

- 界面相关的钩子一律只在后台注册。内容类型和分类法在 `init` 上无条件注册（否则 wp-cli 和后台之外的写入会被拒绝），其余一切走 `is_admin()` 守卫。插件不注册任何前台钩子，博客前台不受它影响。
- 每个写操作校验 nonce 和 `manage_options` 权限。
- 所有 SQL 走 `$wpdb->prepare()`；自建表的表名用 `weekplan_schedule_table()` 取，不要硬编码前缀。
- 前端不引框架、不引构建步骤。原生 HTML + CSS + JavaScript，浏览器原生拖拽 API。
- 改自建表结构必须同时改 `WEEKPLAN_DB_VERSION`，升级逻辑走 `weekplan_maybe_upgrade_schema()`。
- `dbDelta()` 对 SQL 格式极其挑剔：字段名与类型之间两个空格、用 `KEY` 而非 `INDEX`、`PRIMARY KEY` 后两个空格。写错的表现是每次加载都重复执行 ALTER，不会报错。

## 开发流程

开发用的 WordPress 跑在博客服务器上，与生产博客完全隔离，见 [dev/README.md](dev/README.md)。

```bash
./dev/server.sh start   # 启动远端开发服务器
./dev/tunnel.sh         # 开隧道，浏览器访问 http://localhost:8080/wp-admin/
./dev/sync.sh           # 同步插件代码
./dev/server.sh debuglog # 看 PHP 报错
./dev/server.sh stop     # 不开发时停掉
```

服务器内存 1.7 GiB 无 swap，可用约 550 MiB。**开发服务器不用时必须停掉。**

## 安全边界

沿用 `../TX_VPS/AGENTS.md` 的规矩：

- 不把服务器密码、数据库密码、面板密码、token、SSL 私钥写入项目文件、脚本、日志或回复。
- 用户在会话中提供的凭据只用于本次授权操作，不沉淀到文件。
- 不读取或展示生产站的 `wp-config.php`、宝塔敏感配置、数据库凭据。
- 开发站的 `wp-config.php` 只存在于服务器 `/root/wp-dev/`，权限 600，不进本仓库。

## 触碰生产环境的规矩

**开发阶段完全不碰生产博客。** 到部署阶段时：

1. 先只读检查实时状态；实时状态与文档冲突，以实时状态为准。
2. 部署前 `wp db export` 备份生产数据库。
3. 一次只做一个可验证的小变更，变更后检查 HTTP 响应和错误日志。
4. 把结论、变更、验证结果和回滚方式写进 `../TX_VPS/docs/ops-log.md`。

线上写操作、激活插件、任何安全相关变更，都需要用户明确同意。

## 沟通

- 默认中文。
- 先给结论，再给证据和可执行步骤。
- 避免不必要的技术术语；确实需要时先解释一句再用。
- 完成变更后说明做了什么、验证了什么、还剩什么风险。
