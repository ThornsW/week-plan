# week-plan 仓库指南

## 项目

`week-plan` 是一个单用户 WordPress 插件，把待办按天排进周计划表。界面只在
`wp-admin` 后台出现，生产站点为 `thornsw.cn`。插件源码位于
`plugin/week-plan/`，当前源码版本是 0.3.0；最近一次生产部署也是 0.3.0
（2026-10-14，见 `../TX_VPS/docs/ops-log.md`）。

旧的 `../Memo/` 已冻结，与本项目没有共享代码或数据迁移。服务器事实和运维记录归
`../TX_VPS/` 管理；文档可能过期，影响生产操作的状态应当在操作前只读核实。

## 按需读取

- 修改功能、交互、数据模型或产品范围时，读取
  [设计规格](docs/design-spec.md)。其中 35 条决定是实现依据；需要改变决定时同步更新规格。
- 启动、同步或排查隔离开发站时，读取 [开发环境](dev/README.md)。
- 准备生产部署、备份或线上检查时，读取
  [生产操作](docs/production-operations.md) 和 `../TX_VPS/AGENTS.md`。

例行小改不必通读全部文档。

## 实现约束

- 插件目录和主文件固定为 `week-plan/`、`week-plan.php`；PHP 标识符使用
  `weekplan_`，不得占用 WordPress 的 `wp_` 前缀。
- 内容类型 `weekplan_todo` 和自有分类 `weekplan_tag` 在 `init` 上注册；界面钩子只在
  后台注册。不要增加前台入口或复用博客文章标签。
- 每个写操作校验 nonce 和 `manage_options`。SQL 使用 `$wpdb->prepare()`；表名通过
  `weekplan_schedule_table()` 获取。
- 改排期表结构时同时更新 `WEEKPLAN_DB_VERSION`，并通过
  `weekplan_maybe_upgrade_schema()` 升级。保留 `dbDelta()` 要求的 SQL 格式。
- 每个 PHP 入口先检查 `ABSPATH`。不要把 Markdown、备份、SQL、压缩包或其他可被
  Nginx 原样返回的文件部署进 `wp-content`。
- 前端使用原生 HTML、CSS 和 JavaScript，不引入框架、npm 或构建步骤。

## 验证与环境边界

根据改动范围检查：PHP 改动做语法检查，交互改动在隔离开发站验证，数据库改动验证
安装与升级路径，前台相关边界验证博客页面不受影响。文档改动只需检查链接和差异。

本机不安装 WordPress、PHP 或数据库；开发站使用独立目录和数据库，不接触生产站。
实现任务需要远端验证时，可以启动、同步、检查、修复并重试，无需逐步确认；结束后停止
开发服务器。生产部署、激活和数据变更必须有用户明确授权，并遵守生产操作文档中的备份、
验证和记录要求。

不要把凭据、私钥、token、`wp-config.php` 内容或服务器敏感配置写入仓库、日志或回复。
