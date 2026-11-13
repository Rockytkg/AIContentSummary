# AIContentSummary

一个用于 Typecho 的 AI 文章摘要插件：在文章发布后自动调用 AI 接口，根据正文生成摘要并保存为自定义字段，前台列表/摘要输出时优先展示该摘要；后台提供摘要管理面板，可逐条编辑、清空、生成。

- **版本**：2.2.0（Typecho 1.3+ / PHP 8.0+ 全面重构：strict_types、match、str_contains、nullsafe 等）
- **兼容环境**：PHP 8.0 ~ 8.4、Typecho 1.3+

> 项目说明与完整文档见仓库根目录的 [README.md](../README.md)。

---

## 文件结构

```
AIContentSummary/
├── Plugin.php               插件主体（钩子注册、配置表单、发布流程）
├── Action.php               后台摘要管理动作（生成 / 保存 / 清空）
├── Api.php                  公共 API 逻辑（温度策略、token 预算、响应解析）※必须上传
├── test.php                 连通性测试接口（独立文件，不经路由）※必须上传
├── regen.php                历史文章批量补生成接口 ※必须上传
├── template/
│   └── summaries.php        后台「摘要管理」面板
└── README.md
```

## 安装

1. 将整个 `AIContentSummary` 文件夹上传到 `usr/plugins/`；
2. 后台 → 控制台 → 插件 → 启用 **AIContentSummary**;
3. 设置 → 插件 → AIContentSummary，填写模型名、API Key、API 地址，保存后点「测试 API 连通性」。

> **升级覆盖前**建议先停用插件，删除旧目录后再上传新版。

## 配置项

| 界面名称 | 配置键 | 默认 | 说明 |
| --- | --- | --- | --- |
| 模型名称 | `modelName` | `gpt-4o-mini` | 聊天补全接口使用的模型名 |
| API Key | `apiKey` | 空 | 调用摘要接口的密钥，保存后以密码框掩码回显 |
| API 地址 | `apiUrl` | 空 | 带不带 `/v1` 均可，插件自动补全 |
| 系统提示词 | `prompt` | 内置提示词 | 约束摘要风格与输出格式 |
| 摘要字段名 | `fieldName` | `ai_summary` | 摘要保存的自定义字段名 |
| 发布时自动生成 | `finishPublishSummary` | `0` | 仅对没有摘要的文章生效 |
| 摘要截断长度 | `summaryLength` | `100` | 前台摘录输出的最大字符数 |
| 正文前置摘要 | `outputSummaryInHeader` | `0` | 开启后正文顶部渲染模板 |
| 摘要模板 | `summaryTemplate` | `<aside class="ai-content-summary">…</aside>` | 必须含 `{summary}` |

### 常见服务配置

| 服务 | API 地址 | 模型名 |
| --- | --- | --- |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k3.1-pro` |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |

**建议用非思考型模型生成摘要**：摘要任务不需要推理，`deepseek-chat` 通常 1~3 秒返回；`kimi-k3` 这类思考型模型会先推理几千字，慢且贵（本版已做兼容，推理预算与兜底提取都到位）。

## 使用方法

- **自动生成**：发布文章后自动生成，写入自定义字段（默认 `ai_summary`）。
- **跳过已有**：已有摘要的文章再次保存会跳过，不重复调接口、不覆盖手工修订。
- **后台管理**：后台左侧「摘要管理」面板可查看全部文章的摘要，点摘要文字就地编辑，也可单篇或批量生成。
- **编辑页手填**：文章编辑页的自定义字段区有「AI 摘要」，手填即视为已有摘要，跳过自动生成。
- **正文前置**：开启「正文前置摘要」后，正文顶部按模板渲染摘要，默认关闭。
- **前台输出**：`<?php $this->fields->ai_summary(); ?>`

## 排查问题

插件目录下的 `debug.log` 会记录关键节点：

```
[2025-01-01 10:20:01] 跳过生成：cid=128 已有摘要，不再调用接口
[2025-01-01 10:21:12] 生成成功：cid=130 摘要长度=96
```

> `debug.log` 直接部署在 Web 目录下，可能被公网访问到（内容为运行日志，不含密钥），排查完可以删除；插件目录中的 `test.php`、`regen.php` 由 API KEY 派生令牌保护，不校验后台登录。

## 开源协议

[Apache License 2.0](../LICENSE)

## 致谢

- [@Andrew8102](https://github.com/Andrew8102) 在 [PR #3](https://github.com/Rockytkg/AIContentSummary/pull/3) 中贡献了 PHP 8.4 兼容、Kimi K2/K3 等思考型模型适配，以及摘要管理面板与正文前置摘要的整合；
- 完整贡献者名单见根目录 [README.md](../README.md) 的致谢部分。
