<?php
declare(strict_types=1);

/**
 * 通过 AI 接口依据文章内容生成摘要并写入自定义字段
 *
 * @package AIContentSummary
 * @author Rockytkg, Andrew8102
 * @version 2.2.0
 * @link https://github.com/Rockytkg/AIContentSummary
 */

namespace TypechoPlugin\AIContentSummary;

use AIContentSummary_Api;
use Typecho\Common;
use Typecho\Db;
use Typecho\Http\Client;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Url;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Base\Contents;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Api.php';

/**
 * 插件主体。必须 implements PluginInterface：parseInfo() 依赖 T_IMPLEMENTS
 * 识别 activate/config 等入口，继承式声明会被当作「即插即用」插件。
 */
final class Plugin implements PluginInterface
{
    public const NAME = 'AIContentSummary';

    /** 后台面板模板（相对插件目录） */
    public const PANEL = 'AIContentSummary/template/summaries.php';

    /** 后台动作名 */
    public const ACTION = 'summaries';

    /** 日志文件（位于插件目录） */
    public const LOG_FILE = 'debug.log';

    /** 请求内摘要缓存（cid => summary），减少列表页对 fields 表的重复查询 */
    private static array $summaryCache = [];

    /**
     * 注册前台渲染钩子、发布钩子、编辑页字段与后台管理入口。
     */
    public static function activate(): void
    {
        if (Client::get() === null) {
            throw new PluginException(_t('需要启用 PHP cURL 扩展才能使用 AIContentSummary'));
        }

        TypechoPlugin::factory('Widget\Base\Contents')->excerptEx = self::class . '::excerpt';
        TypechoPlugin::factory('Widget\Base\Contents')->contentEx = self::class . '::content';
        TypechoPlugin::factory('Widget\Contents\Post\Edit')->finishPublish = self::class . '::finishPublish';
        TypechoPlugin::factory('Widget\Contents\Post\Edit')->getDefaultFieldItems = self::class . '::addField';

        Helper::addPanel(3, self::PANEL, _t('摘要管理'), _t('管理 AI 摘要'), 'administrator');
        Helper::addAction(self::ACTION, Action::class);
    }

    /**
     * 卸载插件时移除面板与动作。
     */
    public static function deactivate(): void
    {
        Helper::removePanel(3, self::PANEL);
        Helper::removeAction(self::ACTION);
    }

    /**
     * 构建配置表单，并输出设置页附加工具（连通性测试、历史文章补生成）。
     * 校验规则只能使用 Typecho\Validate 已有方法或闭包，不存在的规则名会在保存设置时抛 TypeError。
     */
    public static function config(Form $form): void
    {
        $form->addInput(
            new Text(
                'modelName',
                null,
                'gpt-4o-mini',
                _t('模型名称'),
                _t('聊天补全接口使用的模型名，例如 gpt-4o-mini。<br>'
                    . 'Kimi / Moonshot：kimi-k3.1-pro、kimi-k3、kimi-k2.6（地址填 https://api.moonshot.cn/v1）；'
                    . 'DeepSeek：deepseek-chat。Kimi 新模型只接受 temperature=1，插件已自动适配。')
            )
        );

        $form->addInput(
            new Password(
                'apiKey',
                null,
                '',
                _t('API Key'),
                _t('用于调用摘要接口的密钥，保存后以掩码回显。')
            )
        );

        $form->addInput(
            (new Url(
                'apiUrl',
                null,
                '',
                _t('API 地址'),
                _t('填写接口根地址，例如 https://api.example.com/v1，也支持直接填写 /chat/completions 完整地址。'
                    . '是否带 /v1 都可以，插件会自动补全。')
            ))->addRule('url', _t('请填写合法的 API 地址'))
        );

        $form->addInput(
            (new Textarea(
                'prompt',
                null,
                <<<'PROMPT'
你是一名专业的文章摘要编辑。

请基于输入的完整文章生成一段简体中文摘要，并严格遵守以下要求：
1. 只输出摘要正文，不要添加标题、标签、引号或解释。
2. 摘要要准确、克制、信息密度高。
3. 保留原文核心观点、结论和语气。
4. 输出长度控制在 100 字以内。
PROMPT,
                _t('系统提示词'),
                _t('用于约束摘要风格与输出格式。')
            ))->addRule('required', _t('系统提示词不能为空'))
        );

        $form->addInput(
            (new Text(
                'fieldName',
                null,
                'ai_summary',
                _t('摘要字段名'),
                _t('摘要保存在文章自定义字段中的字段名，仅支持字母、数字和下划线，且不能以数字开头。')
            ))
                ->addRule('required', _t('摘要字段名不能为空'))
                ->addRule(
                    static fn(?string $value): bool => 1 === preg_match('/^[_a-zA-Z][_a-zA-Z0-9]*$/', (string) $value),
                    _t('摘要字段名格式不正确')
                )
        );

        $form->addInput(
            new Radio(
                'finishPublishSummary',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '0',
                _t('发布时自动生成'),
                _t('开启后会在文章发布或更新时为没有摘要的文章自动生成摘要（仅管理员）。<br>'
                    . '<strong>已有摘要的文章不会重复调用接口</strong>（避免重复消耗额度、避免覆盖手工修订的摘要）；'
                    . '想让某篇重新生成，在「摘要管理」面板操作，或清空它的摘要字段再保存一次。')
            )
        );

        $form->addInput(
            (new Number(
                'summaryLength',
                null,
                100,
                _t('摘要截断长度'),
                _t('用于 excerptEx 输出时的最大字符数。')
            ))
                ->addRule('required', _t('摘要截断长度不能为空'))
                ->addRule('isInteger', _t('摘要截断长度必须为整数'))
                ->addRule(static fn(?string $value): bool => (int) $value >= 1, _t('摘要截断长度不能小于 1'))
        );

        $form->addInput(
            new Radio(
                'outputSummaryInHeader',
                array('1' => _t('开启'), '0' => _t('关闭')),
                '0',
                _t('正文前置摘要'),
                _t('开启后会在文章正文顶部渲染摘要模板。默认关闭，避免改变现有主题的排版。')
            )
        );

        $form->addInput(
            (new Textarea(
                'summaryTemplate',
                null,
                <<<'HTML'
<aside class="ai-content-summary"><strong>摘要：</strong><p>{summary}</p></aside>
HTML,
                _t('摘要模板'),
                _t('正文前置摘要模板，必须包含 {summary} 占位符，摘要内容会被 HTML 转义后填入。')
            ))
                ->addRule('required', _t('摘要模板不能为空'))
                ->addRule(
                    static fn(?string $template): bool => str_contains((string) $template, '{summary}'),
                    _t('摘要模板必须包含 {summary} 占位符')
                )
        );

        /*
         * test.php / regen.php 为不经路由的独立文件，只取 path 让浏览器同源请求，
         * 规避 CORS 与混合内容；令牌由 API KEY 派生，防止接口被陌生请求占用额度。
         */
        $testPath = Common::url(self::NAME . '/test.php', Helper::options()->pluginUrl);
        $regenPath = Common::url(self::NAME . '/regen.php', Helper::options()->pluginUrl);

        try {
            $apiKey = AIContentSummary_Api::opt(Options::alloc()->plugin(self::NAME), 'apiKey');
        } catch (\Throwable) {
            $apiKey = '';
        }

        $testPathJson = json_encode(parse_url($testPath, PHP_URL_PATH) ?: '/usr/plugins/' . self::NAME . '/test.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $regenPathJson = json_encode(parse_url($regenPath, PHP_URL_PATH) ?: '/usr/plugins/' . self::NAME . '/regen.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tokenJson = json_encode(AIContentSummary_Api::token($apiKey));

        echo <<<HTML
<div style="padding:12px 0;border-top:1px dashed #ddd;margin-top:15px;">
    <strong style="display:block;margin-bottom:8px;">API 连通性测试</strong>
    <button type="button" id="ai-test-btn" class="btn btn-s">测试 API 连通性</button>
    <span id="ai-test-loading" style="display:none;margin-left:10px;color:#888;">测试中，最多等 30 秒...</span>
    <div id="ai-test-result" style="display:none;margin-top:10px;padding:10px 12px;border-radius:4px;font-size:13px;line-height:1.7;"></div>
    <p style="color:#888;font-size:12px;margin-top:8px;">
        提示：先点页面底部的「保存设置」，再点测试。<br>
        测试由<strong>服务器</strong>直接访问 API（不经浏览器），因此不受 CORS 限制；失败时会指出卡在哪一步。
    </p>
</div>
<div style="padding:12px 0;border-top:1px dashed #ddd;margin-top:15px;">
    <strong style="display:block;margin-bottom:8px;">补生成历史文章的摘要</strong>
    <button type="button" id="ai-regen-btn" class="btn btn-s">扫描并补生成（最多 50 篇）</button>
    <span id="ai-regen-status" style="margin-left:10px;color:#888;"></span>
    <div id="ai-regen-log" style="display:none;margin-top:10px;padding:10px 12px;border:1px solid #e3e3e3;border-radius:4px;font-size:12px;line-height:1.7;max-height:220px;overflow:auto;background:#fafafa;"></div>
    <p style="color:#888;font-size:12px;margin-top:8px;">
        只处理「已发布且没有摘要字段」的文章，逐篇串行调用接口，页面保持打开即可。<br>
        生成结果同时写入 <code>usr/plugins/AIContentSummary/debug.log</code>，排查问题时可直接看这个文件。
    </p>
</div>
<script>
(function () {
    var PATH = {$testPathJson};
    var REGEN = {$regenPathJson};
    var TOKEN = {$tokenJson};
    var btn = document.getElementById('ai-test-btn');
    var loading = document.getElementById('ai-test-loading');
    var box = document.getElementById('ai-test-result');
    if (!btn) { return; }

    function esc(v) {
        return String(v === undefined || v === null ? '' : v).replace(/[&<>]/g, function (c) {
            return c === '&' ? '&amp;' : (c === '<' ? '&lt;' : '&gt;');
        });
    }

    function paint(ok, title, lines) {
        box.style.display = 'block';
        box.style.background = ok ? '#f0f9f2' : '#fdf1f1';
        box.style.border = '1px solid ' + (ok ? '#bfe3c6' : '#f0c2c2');
        box.style.color = ok ? '#1f5b2b' : '#8a2222';
        var html = '<strong>' + esc(title) + '</strong>';
        if (lines && lines.length) {
            html += '<div style="margin-top:6px;">';
            for (var i = 0; i < lines.length; i++) {
                if (lines[i]) { html += '<div>' + lines[i] + '</div>'; }
            }
            html += '</div>';
        }
        box.innerHTML = html;
    }

    function linesOf(r) {
        var out = [];
        if (r.msg) { out.push(esc(r.msg)); }
        if (r.hint) { out.push('建议：' + esc(r.hint)); }
        if (r.endpoint) { out.push('接口地址：<code>' + esc(r.endpoint) + '</code>'); }
        if (r.model) { out.push('模型：' + esc(r.model)); }
        if (r.httpCode) { out.push('上游 HTTP：' + esc(r.httpCode)); }
        if (r.reply) { out.push('模型回复：' + esc(r.reply)); }
        if (r.temperature) { out.push('temperature：' + esc(r.temperature)); }
        if (r.cost) { out.push('耗时：' + esc(r.cost)); }
        if (r.response) { out.push('上游返回：<code style="word-break:break-all;">' + esc(r.response) + '</code>'); }
        if (r.stage && r.stage !== 'ok') { out.push('失败环节：' + esc(r.stage)); }
        return out;
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        loading.style.display = 'inline';
        box.style.display = 'none';

        var url = PATH + (PATH.indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now();

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: 't=' + encodeURIComponent(TOKEN)
        }).then(function (resp) {
            return resp.text().then(function (text) {
                return {status: resp.status, text: text};
            });
        }).then(function (resp) {
            var data = null;
            try { data = JSON.parse(resp.text); } catch (e) { data = null; }

            if (!data) {
                var snippet = resp.text.replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').slice(0, 300);
                paint(false, '接口返回的不是 JSON（HTTP ' + resp.status + '）', [
                    '请求地址：<code>' + esc(PATH) + '</code>',
                    '返回片段：' + esc(snippet),
                    '常见原因：① test.php 没上传（404）；② 主机/宝塔禁止直接访问 usr/plugins 下的 PHP（403）；③ CDN 缓存了旧内容。'
                ]);
                return;
            }

            paint(!!data.ok, (data.ok ? '连通正常：' : '测试未通过：') + (data.msg || ''), linesOf(data));
        }).catch(function (err) {
            paint(false, '请求没发出去', [
                '浏览器报错：' + esc(err && err.message ? err.message : err),
                '请求地址：<code>' + esc(PATH) + '</code>'
            ]);
        }).then(function () {
            btn.disabled = false;
            loading.style.display = 'none';
        });
    });

    var regenBtn = document.getElementById('ai-regen-btn');
    var regenStatus = document.getElementById('ai-regen-status');
    var regenLog = document.getElementById('ai-regen-log');

    function regenSay(html, color) {
        regenLog.style.display = 'block';
        regenLog.innerHTML += '<div style="color:' + (color || '#444') + ';">' + html + '</div>';
        regenLog.scrollTop = regenLog.scrollHeight;
    }

    function postRegen(params) {
        return fetch(REGEN + '?_t=' + Date.now(), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: params
        }).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) {
                    return {ok: false, msg: 'regen.php 返回的不是 JSON：' + t.replace(/<[^>]*>/g, ' ').slice(0, 200)};
                }
            });
        });
    }

    if (regenBtn) {
        regenBtn.addEventListener('click', function () {
            regenBtn.disabled = true;
            regenLog.innerHTML = '';
            regenStatus.textContent = '正在扫描...';

            postRegen('t=' + encodeURIComponent(TOKEN)).then(function (data) {
                if (!data.ok) {
                    regenStatus.textContent = '扫描失败';
                    regenSay(esc(data.msg || '未知错误'), '#8a2222');
                    regenBtn.disabled = false;
                    return;
                }

                var list = data.list || [];
                if (!list.length) {
                    regenStatus.textContent = '没有需要补生成的文章';
                    regenSay('全部已发布文章都已有摘要，无需补生成。', '#1f5b2b');
                    regenBtn.disabled = false;
                    return;
                }

                var done = 0;
                var failed = 0;
                regenStatus.textContent = '共 ' + list.length + ' 篇待处理';

                function next(i) {
                    if (i >= list.length) {
                        regenStatus.textContent = '完成：成功 ' + done + ' 篇，失败 ' + failed + ' 篇';
                        regenBtn.disabled = false;
                        return;
                    }

                    var item = list[i];
                    regenStatus.textContent = '处理中 ' + (i + 1) + '/' + list.length + '：' + item.title;

                    postRegen('t=' + encodeURIComponent(TOKEN) + '&cid=' + item.cid).then(function (r) {
                        if (r.ok) {
                            done++;
                            regenSay('✔ ' + esc(item.title) + '（' + esc(r.cost || '') + '）', '#1f5b2b');
                        } else {
                            failed++;
                            regenSay('✘ ' + esc(item.title) + '：' + esc(r.msg || '') + (r.detail ? '｜' + esc(r.detail) : ''), '#8a2222');
                        }
                        next(i + 1);
                    }).catch(function (e) {
                        failed++;
                        regenSay('✘ ' + esc(item.title) + '：请求失败 ' + esc(e && e.message ? e.message : e), '#8a2222');
                        next(i + 1);
                    });
                }

                next(0);
            }).catch(function (e) {
                regenStatus.textContent = '扫描失败';
                regenSay('请求失败：' + esc(e && e.message ? e.message : e), '#8a2222');
                regenBtn.disabled = false;
            });
        });
    }
})();
</script>
HTML;
    }

    /**
     * 插件未提供个人配置项，保留空实现以符合接口约定。
     */
    public static function personalConfig(Form $form): void
    {
    }

    /**
     * 存在 AI 摘要时优先输出摘要作为 excerpt。
     */
    public static function excerpt(string $excerpt, Contents $widget): string
    {
        $summary = self::postSummary($widget);
        if ($summary === '') {
            return $excerpt;
        }

        try {
            $maxLength = AIContentSummary_Api::maxLength(self::settings());
        } catch (\Throwable) {
            $maxLength = 100;
        }

        // Common::subStr 截断后自带「...」后缀
        if (Common::strLen($summary) > $maxLength) {
            $summary = Common::subStr($summary, 0, $maxLength);
        }

        return $summary;
    }

    /**
     * 按配置把摘要插入正文顶部（默认关闭）。
     */
    public static function content(string $content, Contents $widget): string
    {
        try {
            $settings = self::settings();

            if (AIContentSummary_Api::opt($settings, 'outputSummaryInHeader', '0') !== '1') {
                return $content;
            }

            $summary = self::postSummary($widget);
            if ($summary === '') {
                return $content;
            }

            $template = AIContentSummary_Api::opt($settings, 'summaryTemplate', AIContentSummary_Api::DEFAULT_TEMPLATE);

            return AIContentSummary_Api::wrapSummary($template, $summary) . $content;
        } catch (\Throwable) {
            return $content;
        }
    }

    /**
     * 发布完成后按需生成摘要。该钩子在跳转前执行，任何失败只能记日志、
     * 弹后台提示，阻塞或中断会跳过 Notice 与 redirect，表现为保存白屏。
     */
    public static function finishPublish(array $contents, mixed $editor): void
    {
        try {
            $settings = self::settings();

            if (AIContentSummary_Api::opt($settings, 'finishPublishSummary', '0') !== '1' || !self::isAdministrator()) {
                return;
            }

            $cid = self::resolveCid($editor, $contents);
            $text = (string) ($contents['text'] ?? '');

            if ($cid <= 0) {
                self::log('跳过生成：cid=0（未能从钩子参数取到 cid）');
                return;
            }

            if (trim($text) === '') {
                self::log('跳过生成：cid=' . $cid . ' 正文为空');
                return;
            }

            // 有摘要则跳过：避免重复扣费或覆盖手工修订
            if (self::getSummary($cid) !== '') {
                self::log('跳过生成：cid=' . $cid . ' 已有摘要，不再调用接口');
                return;
            }

            @set_time_limit(120);

            $result = AIContentSummary_Api::generateSummary(
                $settings,
                (string) ($contents['title'] ?? ''),
                $text
            );

            if (!$result['ok']) {
                // 日志只记结论；上游响应体可能含任意文本，Notice 展示前先转义
                self::log('生成失败：cid=' . $cid . ' ' . $result['msg']);
                self::notice(
                    'AI 摘要生成失败：' . $result['msg']
                    . ($result['detail'] !== '' ? '（' . htmlspecialchars($result['detail'], ENT_QUOTES, 'UTF-8') . '）' : '')
                    . '，可在「设置 → 插件 → AIContentSummary」点测试查看具体原因',
                    'error'
                );
                return;
            }

            self::saveSummary($cid, $result['summary']);
            self::log('生成成功：cid=' . $cid . ' 摘要长度=' . Common::strLen($result['summary']));
        } catch (\Throwable $e) {
            self::log('异常：' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /**
     * 为管理员添加文章编辑页的「AI 摘要」自定义字段。
     */
    public static function addField(Layout $layout): void
    {
        if (!self::isAdministrator()) {
            return;
        }

        try {
            $settings = self::settings();
        } catch (\Throwable) {
            return;
        }

        $field = new Textarea(
            AIContentSummary_Api::fieldName($settings),
            null,
            null,
            _t('AI 摘要'),
            _t('手动填写后将优先作为文章摘要输出，同时跳过发布时的自动生成。')
        );

        $field->input?->setAttribute('class', 'w-100');
        $layout->addItem($field);
    }

    /**
     * 生成并保存摘要（后台单篇生成）。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     * @throws \RuntimeException 生成失败
     * @throws \Typecho\Widget\Exception 文章不存在
     */
    public static function generateForPost(int $cid): array
    {
        $post = self::post($cid);
        $result = AIContentSummary_Api::generateSummary(
            self::settings(),
            (string) $post['title'],
            (string) ($post['text'] ?? '')
        );

        if (!$result['ok']) {
            throw new \RuntimeException($result['msg'] . ($result['detail'] !== '' ? '：' . $result['detail'] : ''));
        }

        self::saveSummary($cid, $result['summary']);

        return self::summaryResponse($cid, $result['summary']);
    }

    /**
     * 手动保存或清空摘要。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     * @throws \Typecho\Widget\Exception 文章不存在
     */
    public static function saveManual(int $cid, string $summary): array
    {
        self::post($cid);

        $summary = trim($summary);

        if ($summary === '') {
            // 清空即删除字段，前台渲染自然回退到默认摘要逻辑
            $db = Db::get();
            $db->query(
                $db->delete('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', self::currentFieldName())
            );
            unset(self::$summaryCache[$cid]);

            return self::summaryResponse($cid, '');
        }

        self::saveSummary($cid, $summary);

        return self::summaryResponse($cid, $summary);
    }

    /**
     * 读取某篇文章的摘要（供面板与接口使用）。
     */
    public static function getSummary(int $cid): string
    {
        if ($cid <= 0) {
            return '';
        }

        try {
            $db = Db::get();
            $row = $db->fetchRow(
                $db->select('str_value')
                    ->from('table.fields')
                    ->where('cid = ?', $cid)
                    ->where('name = ?', self::currentFieldName())
                    ->limit(1)
            );
        } catch (\Throwable $e) {
            self::log('读取已有摘要失败：cid=' . $cid . ' ' . $e->getMessage());
            return '';
        }

        return isset($row['str_value']) ? trim((string) $row['str_value']) : '';
    }

    /**
     * 当前生效的摘要字段名（面板、接口与前台共用）。
     */
    public static function currentFieldName(): string
    {
        return AIContentSummary_Api::fieldName(self::settings());
    }

    /**
     * 读取插件配置；未保存过配置时返回空对象。
     */
    public static function settings(): object
    {
        try {
            $settings = Options::alloc()->plugin(self::NAME);

            return is_object($settings) ? $settings : new \stdClass();
        } catch (\Throwable) {
            return new \stdClass();
        }
    }

    /**
     * 写一行日志，失败静默。
     */
    public static function log(string $message): void
    {
        try {
            @file_put_contents(
                __DIR__ . '/' . self::LOG_FILE,
                '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable) {
        }
    }

    /**
     * 当前用户是否为管理员。
     */
    public static function isAdministrator(): bool
    {
        try {
            return User::alloc()->pass('administrator', true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 读取文章记录（仅允许正式文章与草稿）。
     *
     * @return array<string, mixed>
     * @throws \Typecho\Widget\Exception 无效 ID 时 400，文章不存在时 404
     */
    public static function post(int $cid): array
    {
        if ($cid <= 0) {
            throw new \Typecho\Widget\Exception(_t('无效的文章 ID'), 400);
        }

        $db = Db::get();
        $post = $db->fetchRow(
            $db->select('cid', 'title', 'text')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type IN ?', array('post', 'post_draft'))
                ->limit(1)
        );

        if (!$post) {
            throw new \Typecho\Widget\Exception(_t('文章不存在'), 404);
        }

        return (array) $post;
    }

    /**
     * 从钩子参数中取得 cid。Typecho 的 Widget 魔术属性没有实现 __isset，不能用 isset() 判断。
     */
    private static function resolveCid(mixed $editor, array $contents): int
    {
        if (isset($contents['cid']) && (int) $contents['cid'] > 0) {
            return (int) $contents['cid'];
        }

        if (!is_object($editor)) {
            return 0;
        }

        foreach (array('cid', 'theId', 'id') as $prop) {
            try {
                $value = (int) $editor->{$prop};
                if ($value > 0) {
                    return $value;
                }
            } catch (\Throwable) {
            }
        }

        return 0;
    }

    /**
     * 从内容 Widget 上取当前文章的摘要（单次请求内按 cid 缓存）。
     */
    private static function postSummary(Contents $widget): string
    {
        try {
            $cid = (int) $widget->cid;
        } catch (\Throwable) {
            return '';
        }

        if (array_key_exists($cid, self::$summaryCache)) {
            return self::$summaryCache[$cid];
        }

        try {
            $fields = $widget->fields;
            $value = is_object($fields) ? $fields->{self::currentFieldName()} : null;
        } catch (\Throwable) {
            $value = null;
        }

        $summary = is_string($value) ? trim($value) : '';
        self::$summaryCache[$cid] = $summary;

        return $summary;
    }

    /**
     * 保存摘要字段（存在则更新，不存在则插入）。
     */
    private static function saveSummary(int $cid, string $summary): void
    {
        $summary = trim($summary);
        $fieldName = self::currentFieldName();
        $db = Db::get();

        $exists = $db->fetchRow(
            $db->select('cid')
                ->from('table.fields')
                ->where('cid = ?', $cid)
                ->where('name = ?', $fieldName)
                ->limit(1)
        );

        $rows = array(
            'type' => 'str',
            'str_value' => $summary,
            'int_value' => 0,
            'float_value' => 0
        );

        if ($exists) {
            $db->query(
                $db->update('table.fields')
                    ->rows($rows)
                    ->where('cid = ?', $cid)
                    ->where('name = ?', $fieldName)
            );
        } else {
            $db->query(
                $db->insert('table.fields')->rows(array('cid' => $cid, 'name' => $fieldName) + $rows)
            );
        }

        self::$summaryCache[$cid] = $summary;
    }

    /**
     * 面板/接口共用的返回结构。
     *
     * @return array{cid:int,summary:string,length:int,hasSummary:bool}
     */
    private static function summaryResponse(int $cid, string $summary): array
    {
        $summary = trim($summary);

        return array(
            'cid' => $cid,
            'summary' => $summary,
            'length' => Common::strLen($summary),
            'hasSummary' => $summary !== ''
        );
    }

    /**
     * 弹出后台提示（失败时用，不打断主流程）。
     */
    private static function notice(string $message, string $type): void
    {
        try {
            \Widget\Notice::alloc()->set($message, $type);
        } catch (\Throwable) {
        }
    }
}
