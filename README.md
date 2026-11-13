# AIContentSummary

AIContentSummary 是一个用于通过调用 AI 接口，根据文章内容生成摘要的 Typecho 插件。

## 功能

- 发布文章时自动调用 AI 生成摘要，写入文章自定义字段（默认 `ai_summary`）
- 默认接入 DeepSeek（`deepseek-chat` + 官方 OpenAI 兼容接口），填写 API Key 即可使用，也可替换为任意 OpenAI 兼容服务
- 后台「摘要管理」面板：查看全部文章摘要、就地编辑 / 清空 / 单篇生成 / 勾选批量生成（串行防限流）
- 正文前置摘要（可选）：按模板把摘要渲染到正文顶部，`{summary}` 占位符 + HTML 转义
- 自定义系统提示词、摘要字段名与截断长度
- 编辑页「AI 摘要」字段（管理员可见）：手动填写后跳过自动生成
- 设置页一键「测试 API 连通性」，失败时指出卡在哪一步（配置 / 网络 / 接口 / 令牌）
- 「扫描并补生成」：为没有摘要的历史文章批量补生成
- 关键节点写入 `usr/plugins/AIContentSummary/debug.log`，便于排查

## 环境要求

- Typecho >= 1.3，PHP 8.0 ~ 8.4（依赖 ext-curl 与 ext-mbstring）

## 兼容性修复（2.1.0 ~ 2.2.0）

| 问题 | 现象 | 处理 |
| --- | --- | --- |
| temperature 被拒 | `400 invalid temperature: only 1 is allowed for this model` | 按模型判断：Kimi/Moonshot、OpenAI o 系、gpt-5 系等不传自定义温度，并带自动回退 |
| 思考型模型无正文 | `模型没有返回正文（finish_reason=length）` | 按模型给足推理预算（4096~32768），被截断自动放大重试，仍失败时从 `reasoning_content` 保守提取草稿 |
| 保存文章白屏 | 后台空白页、无「已经发布」提示 | 发布钩子内不再调用 `fastcgi_finish_request()`，并整体包 try/catch，异常只写日志 |
| 从不生成摘要 | 发布后一直没有摘要 | 不再用 `isset($obj->cid)` 判断 Typecho 魔术属性（恒为 false），改为直接取值 |
| PHP 8.4 报错 | 除零、`array_key_exists(false)`、隐式可空参数等 | 已适配 PHP 8.x |
| 启用插件即 500（2.1.1） | Typecho 1.2 下启用/设置页空白 | 设置表单误用了 Typecho 1.3 才有的 `Url` 表单元素，改回 `Text` |
| 设置无法保存（2.1.1） | 保存时报错或提示「格式不正确」 | `regexp` / `min` 不是 Typecho\Validate 的合法规则，换成闭包校验 |
| 面板摘要列恒为空（2.1.1） | 「摘要管理」所有文章都显示「暂无摘要」 | 面板模板错误地用 `??` 读取魔术属性，改为直接取值 |
| 截断出现双省略号（2.1.1） | `……...` | `Common::subStr` 自带「...」后缀，去掉手动拼接 |
| PHP 7.4 保存设置报错（2.1.1） | `Call to undefined function str_contains()` | 模板占位符校验改用 `strpos()`，兼容 7.4（2.2.0 起 PHP 底线提升至 8.0，改回 `str_contains`） |
| 全面重构（2.2.0） | — | 仅支持 Typecho 1.3+ 与 PHP 8.0+：全文件 `strict_types`、全类型签名、`match`/`str_contains`/nullsafe 等 PHP 8 语法；`apiUrl` 改用 1.3 新增的 `Url` 元素、截断长度改用 `Number` 元素；摘要在单次请求内按 cid 缓存，避免列表页逐行查询 fields 表；独立接口增加 `nosniff` 并对空 API KEY 一律拒绝 |

## 安装

1. 从 [Releases](https://github.com/Rockytkg/AIContentSummary/releases) 下载插件压缩包
2. 解压得到 `AIContentSummary/` 目录，**整个目录**上传到 `usr/plugins/`
3. 后台 → 控制台 → 插件 → 启用 AIContentSummary
4. 进入插件设置，填写 API Key（模型与 API 地址已预填 DeepSeek），点「保存设置」后点「测试 API 连通性」

> **升级覆盖前**建议先停用插件；务必删除旧目录后再上传新版，避免残留旧文件。

### 配置示例

默认已预填 DeepSeek（`deepseek-chat` + 官方接口），填好 API Key 即可使用；其他服务按需替换：

| 服务 | API 地址 | 模型名 |
| --- | --- | --- |
| DeepSeek（默认） | `https://api.deepseek.com/v1` | `deepseek-chat` |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k3.1-pro` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

API 地址带不带 `/v1` 都可以，插件会自动补全。

**摘要建议用非思考型模型**：摘要任务不需要推理，默认的 `deepseek-chat` 通常 1~3 秒返回、价格低；`kimi-k3` 这类思考型模型会先推理几千字，慢且贵（虽然插件已做兼容）。

## 使用方法

1. 开启「发布时自动生成」后，发布文章自动生成摘要；已有摘要的文章不会重复调用接口
2. **后台「摘要管理」面板**：查看全部文章摘要，点摘要文字就地编辑；也可单篇生成或勾选后批量生成
3. 想让某篇文章重新生成：在管理面板点「生成摘要」，或清空摘要字段再保存
4. 开启「正文前置摘要」可在正文顶部渲染摘要（默认关闭）
5. 前端输出参考：`<?php $this->fields->ai_summary(); ?>`

## 排查问题

插件目录下的 `debug.log` 会记录每个关键节点：

```
[2025-01-01 10:20:01] 跳过生成：cid=128 已有摘要，不再调用接口
[2025-01-01 10:21:12] 生成成功：cid=130 摘要长度=96
```

## 许可证

[Apache License 2.0](LICENSE)

## 致谢

- [@Andrew8102](https://github.com/Andrew8102) 在 [PR #2](https://github.com/Rockytkg/AIContentSummary/pull/2) 中贡献了 PHP 8.4 兼容与 Kimi K2/K3 等思考型模型的 temperature / max_tokens 适配；
- [@Andrew8102](https://github.com/Andrew8102) 在 [PR #3](https://github.com/Rockytkg/AIContentSummary/pull/3) 中贡献了摘要管理面板与正文前置摘要的整合，并修复了插件头被误判为「即插即用」的问题、清理了 1.x 遗留钩子。

如果这个插件帮到了你，欢迎给项目点个 Star，也欢迎通过 Issue 反馈问题、通过 PR 提交改进。
