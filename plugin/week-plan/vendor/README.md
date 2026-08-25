# vendor

第三方代码，原样保留，不要在这里改动。

## Parsedown.php

- 版本：1.8.0
- 来源：https://github.com/erusev/parsedown（MIT）
- 取得日期：2026-08-25

单文件、无依赖的 Markdown 解析器。选它是因为这个项目明确不引构建步骤，
而 Parsedown 一个文件拷进来就能用。

**它自 2019 年起没有再更新**，历史上有过 XSS 公告。这里能接受的原因：
单用户、笔记内容是使用者自己写的，不存在「别人提交内容给你渲染」的场景。
封装在 `includes/markdown.php` 里，并且开了 `setSafeMode(true)`（转义原始
HTML）和 `setMarkupEscaped(true)`，多一层兜底。

如果哪天这个工具变成多人可写，必须重新评估这个依赖。
