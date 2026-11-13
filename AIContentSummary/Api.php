<?php
declare(strict_types=1);

/**
 * AIContentSummary 公共 API 逻辑
 *
 * 不依赖任何 Typecho 类，供 Plugin.php、Action.php 与独立接口（test.php / regen.php）共同加载。
 * 依赖 ext-mbstring 与 ext-curl（Typecho 本身同样依赖）。
 *
 * 环境要求：PHP 8.0+
 *
 * 版权与来源：原项目由 Rockytkg 开发（Apache-2.0）；
 * 修改者 Andrew8102，修改部分同样以 Apache-2.0 发布。
 */

if (!class_exists('AIContentSummary_Api')) {

    class AIContentSummary_Api
    {
        /** 版本号（与插件头保持一致） */
        const VERSION = '2.2.0';

        const DEFAULT_MODEL = 'gpt-4o-mini';

        /** 摘要字段名默认值（与插件设置表单一致） */
        const DEFAULT_FIELD = 'ai_summary';

        /** 正文前置摘要默认模板 */
        const DEFAULT_TEMPLATE = '<aside class="ai-content-summary"><strong>摘要：</strong><p>{summary}</p></aside>';

        /**
         * 摘要字段名（过滤非法字符，不合法时回退默认值）
         */
        public static function fieldName(mixed $config): string
        {
            $name = trim(self::opt($config, 'fieldName', self::DEFAULT_FIELD));

            return 1 === preg_match('/^[_a-zA-Z][_a-zA-Z0-9]*$/', $name) ? $name : self::DEFAULT_FIELD;
        }

        /**
         * 摘要目标长度（<=0 时取 100）
         */
        public static function maxLength(mixed $config): int
        {
            $value = (int) self::opt($config, 'summaryLength', '100');

            return $value > 0 ? $value : 100;
        }

        /**
         * 把摘要套进模板（正文前置摘要用），摘要内容经过 HTML 转义；
         * 模板没有占位符时直出摘要，避免吞掉正文。
         */
        public static function wrapSummary(string $template, string $summary): string
        {
            if ($template === '') {
                $template = self::DEFAULT_TEMPLATE;
            }

            if (!str_contains($template, '{summary}')) {
                return $summary;
            }

            return str_replace('{summary}', htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'), $template);
        }

        /**
         * 安全读取配置值。
         * Typecho 的 Config 值存于内部数组，需直接取值（缺失返回 null，不会抛异常）。
         */
        public static function opt(mixed $config, string $name, string $default = ''): string
        {
            if (is_array($config)) {
                $value = $config[$name] ?? null;
            } elseif (is_object($config)) {
                try {
                    $value = $config->{$name};
                } catch (\Throwable) {
                    return $default;
                }
            } else {
                return $default;
            }

            return ($value === null || $value === '') ? $default : (string) $value;
        }

        /**
         * 生成摘要的系统提示词（配置损坏时内置一份兜底）
         */
        public static function systemPrompt(mixed $config, string $title, int $maxLength): string
        {
            $custom = trim(self::opt($config, 'prompt', ''));
            if ($custom !== '') {
                return $custom;
            }

            return '请你扮演一个文本摘要生成器，下面是一篇关于 “' . $title . '” 的文章，'
                . '请你根据文章内容生成 ' . $maxLength . ' 字左右的摘要，'
                . '除了你生成的摘要内容，请不要输出其他任何无关内容';
        }

        /**
         * 组装 chat/completions 的 messages
         */
        public static function buildMessages(mixed $config, string $title, string $plainText, int $maxLength): array
        {
            return array(
                array('role' => 'system', 'content' => self::systemPrompt($config, $title, $maxLength)),
                array('role' => 'user', 'content' => $plainText)
            );
        }

        /**
         * 一次性生成摘要（截断放大重试 + 推理内容兜底）。
         * 发布钩子、后台手动生成、regen.php 统一走这里，保证行为一致。
         *
         * @param mixed  $config 插件配置（Config 对象或数组）
         * @return array{ok:bool,summary:string,msg:string,detail:string,cost:int,finish:string,maxTokens:int}
         */
        public static function generateSummary(mixed $config, string $title, string $text, int $timeout = 60): array
        {
            $fail = array(
                'ok' => false,
                'summary' => '',
                'msg' => '',
                'detail' => '',
                'cost' => 0,
                'finish' => '',
                'maxTokens' => 0
            );

            $model = trim(self::opt($config, 'modelName', self::DEFAULT_MODEL));
            $apiKey = trim(self::opt($config, 'apiKey'));
            $apiUrl = trim(self::opt($config, 'apiUrl'));
            $maxLength = self::maxLength($config);

            if ($apiKey === '' || $apiUrl === '') {
                $fail['msg'] = 'API 地址或 API KEY 为空，请先在插件设置里填写并保存';
                return $fail;
            }

            $plainText = self::prepareText($text, 8000);
            if ($plainText === '') {
                $fail['msg'] = '文章内容为空，无法生成摘要';
                return $fail;
            }

            $endpoint = self::buildEndpoint($apiUrl);
            $payload = self::buildPayload(
                $model,
                self::buildMessages($config, $title, $plainText, $maxLength),
                // 思考型模型要额外留推理预算，否则正文会被 reasoning 挤空
                array('max_tokens' => self::maxTokensFor($model, $maxLength))
            );

            $fail['maxTokens'] = (int) $payload['max_tokens'];

            // 最多两轮：正文为空且 finish=length（被截断）时，放大预算再试一次
            $parsed = array('reply' => '', 'finish' => '', 'reasoning' => '', 'error' => '');
            $res = null;
            $cost = 0;

            for ($round = 0; $round < 2; $round++) {
                $start = microtime(true);
                $res = self::postChat($endpoint, $apiKey, $payload, $timeout);
                $cost += (int) round((microtime(true) - $start) * 1000);

                if (!$res['success'] || $res['httpCode'] !== 200) {
                    break;
                }

                $parsed = self::parseReply($res['body']);

                if ($parsed['reply'] !== '') {
                    break;
                }

                if ($round === 0 && $parsed['finish'] === 'length') {
                    $payload['max_tokens'] = min(32768, max(8192, (int) $payload['max_tokens'] * 4));
                    $fail['maxTokens'] = $payload['max_tokens'];
                    continue;
                }

                break;
            }

            if ($res['success'] === false || $res['httpCode'] !== 200) {
                $fail['cost'] = $cost;
                $fail['msg'] = 'API 调用失败';
                $fail['detail'] = self::cut($res['success'] ? $res['body'] : $res['error'], 400);
                return $fail;
            }

            $fail['cost'] = $cost;
            $fail['finish'] = $parsed['finish'];

            $summary = trim($parsed['reply']);

            // 正文为空时从推理残留里捞一份草稿，避免整批任务全部失败
            if ($summary === '') {
                $summary = self::extractFromReasoning($parsed['reasoning'], $maxLength);
            }

            if ($summary === '') {
                $fail['msg'] = '模型没有返回正文（finish_reason=' . $parsed['finish']
                    . '，已用 max_tokens=' . $payload['max_tokens'] . ' 重试）'
                    . ($parsed['finish'] === 'length' ? '，建议换成非思考型模型或调小摘要长度' : '');
                $fail['detail'] = self::cut((string) $res['body'], 400);
                return $fail;
            }

            return array(
                'ok' => true,
                'summary' => $summary,
                'msg' => '摘要生成成功',
                'detail' => '',
                'cost' => $cost,
                'finish' => $parsed['finish'],
                'maxTokens' => (int) $payload['max_tokens']
            );
        }

        /**
         * 组装 chat/completions 地址，兼容是否携带 /v1
         */
        public static function buildEndpoint(string $apiUrl): string
        {
            $apiUrl = rtrim(trim($apiUrl), '/');

            return match (true) {
                preg_match('#/chat/completions$#i', $apiUrl) === 1 => $apiUrl,
                preg_match('#/v1$#i', $apiUrl) === 1 => $apiUrl . '/chat/completions',
                default => $apiUrl . '/v1/chat/completions'
            };
        }

        /**
         * 独立接口（test.php / regen.php）的校验令牌，由 API KEY 派生
         */
        public static function token(string $apiKey): string
        {
            return substr(sha1('aicontentsummary|' . $apiKey), 0, 24);
        }

        /**
         * 该模型是否「只接受默认 temperature」。
         * Kimi 新模型、思考型模型、OpenAI o 系与 gpt-5 系不接受自定义采样温度，
         * 传 temperature=0 会被直接 400 拒绝，这类模型一律不传。
         */
        public static function isFixedTemperature(string $model): bool
        {
            $model = strtolower(trim($model));

            foreach (array('kimi', 'moonshot', 'thinking', 'reasoning') as $kw) {
                if (str_contains($model, $kw)) {
                    return true;
                }
            }

            // 去掉 vendor 前缀后按开头判断（openai/o3-mini、azure/gpt-5 之类）
            $core = str_contains($model, '/') ? substr($model, (int) strrpos($model, '/') + 1) : $model;

            return 1 === preg_match('/^(o[1-9]|gpt-5)/', $core);
        }

        /**
         * 是否为「先推理、再作答」的思考型模型。
         * 这类模型会把大量 token 花在 reasoning 上，max_tokens 给小了
         * 正文会变成空字符串、finish_reason=length。
         */
        public static function isThinkingModel(string $model): bool
        {
            $model = strtolower(trim($model));

            foreach (array('kimi-k3', 'kimi-k2', 'kimi-thinking', 'thinking', 'reasoning', 'reasoner') as $kw) {
                if (str_contains($model, $kw)) {
                    return true;
                }
            }

            $core = preg_replace('#^[^/]+/#', '', $model) ?? $model;

            return 1 === preg_match('/^(o[1-9]|gpt-5)/', $core);
        }

        /**
         * 计算 max_tokens：普通模型按字数的 4 倍给，
         * 思考型模型额外准备推理预算（4096~32768）。
         */
        public static function maxTokensFor(string $model, int $maxLength): int
        {
            if ($maxLength <= 0) {
                $maxLength = 100;
            }

            if (self::isThinkingModel($model)) {
                return max(4096, min(32768, $maxLength * 8 + 4096));
            }

            return max(256, min(2048, $maxLength * 4));
        }

        /**
         * 组装 chat/completions 请求体。
         * 只对支持自定义温度的模型传 0，其余交给服务端默认值。
         */
        public static function buildPayload(string $model, array $messages, array $extra = array()): array
        {
            $payload = array('model' => $model, 'messages' => $messages);

            if (!self::isFixedTemperature($model)) {
                $payload['temperature'] = 0;
            }

            return array_merge($payload, $extra);
        }

        /**
         * 请求体是否因 temperature 不合法被拒
         */
        public static function isTemperatureError(int $httpCode, string $body): bool
        {
            return in_array($httpCode, array(400, 422), true) && stripos($body, 'temperature') !== false;
        }

        /**
         * 带 temperature 兼容回退的 chat 请求。
         * 服务端抱怨 temperature 时依次尝试 =1、不带该参数，最终都不行
         * 则退回最初的报错（信息更贴近真实原因）。
         *
         * @return array 同 request()，命中回退时多一个 adjusted 字段
         */
        public static function postChat(string $endpoint, string $apiKey, array $payload, int $timeout = 30): array
        {
            $result = self::request($endpoint, $apiKey, $payload, $timeout);

            if (!$result['success'] || !self::isTemperatureError($result['httpCode'], $result['body'])) {
                return $result;
            }

            $firstError = $result['body'];

            $retry = $payload;
            $retry['temperature'] = 1;
            $second = self::request($endpoint, $apiKey, $retry, $timeout);
            if ($second['success'] && !self::isTemperatureError($second['httpCode'], $second['body'])) {
                $second['adjusted'] = 'temperature=1';
                return $second;
            }

            unset($retry['temperature']);
            $third = self::request($endpoint, $apiKey, $retry, $timeout);
            if ($third['success'] && !self::isTemperatureError($third['httpCode'], $third['body'])) {
                $third['adjusted'] = 'omit temperature';
                return $third;
            }

            $result['adjusted'] = '';
            $result['firstError'] = $firstError;
            return $result;
        }

        /**
         * 清洗正文：去掉 markdown 标记、HTML 标签与多余空白，并限制长度。
         * 不用 /u 修饰符，避免非法 UTF-8 导致 preg_replace 返回 null。
         */
        public static function prepareText(string $text, int $limit = 8000): string
        {
            $text = str_replace(array('<!--markdown-->', '<!--more-->'), ' ', $text);

            // script / style 整段删除，避免把 CSS/JS 当正文
            $text = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $text);
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

            // &nbsp; 与全角空格解码后统一成普通空格
            $text = str_replace(array("\xC2\xA0", "\xE3\x80\x80"), ' ', $text);
            $text = trim((string) preg_replace('/[ \t\r\n]+/', ' ', $text));

            if (mb_strlen($text, 'UTF-8') > $limit) {
                $text = (string) mb_substr($text, 0, $limit, 'UTF-8');
            }

            return $text;
        }

        /**
         * 从 chat/completions 响应里取出正文、结束原因、错误与推理内容
         *
         * @return array{reply:string,finish:string,error:string,reasoning:string}
         */
        public static function parseReply(string $body): array
        {
            $json = json_decode($body, true);
            $first = is_array($json) && is_array($json['choices'][0] ?? null) ? $json['choices'][0] : array();
            $message = is_array($first['message'] ?? null) ? $first['message'] : array();

            $error = '';
            if (is_array($json)) {
                if (isset($json['error']) && is_array($json['error']) && is_string($json['error']['message'] ?? null)) {
                    $error = $json['error']['message'];
                } elseif (is_string($json['message'] ?? null)) {
                    $error = $json['message'];
                }
            }

            return array(
                'reply' => isset($message['content']) && is_scalar($message['content']) ? trim((string) $message['content']) : '',
                'finish' => is_string($first['finish_reason'] ?? null) ? $first['finish_reason'] : '',
                'error' => $error,
                'reasoning' => is_string($message['reasoning_content'] ?? null) ? trim($message['reasoning_content']) : ''
            );
        }

        /**
         * 从推理残留文字里保守提取可用摘要（最后兜底）。
         * 思考型模型预算被推理吃光时正文为空，但 reasoning_content 里
         * 往往已经复述了文章要点；提取失败时返回空串。
         * 只拦「我要做什么」这类指令叙述句，不拦「这篇文章……」等正常摘要开头。
         */
        public static function extractFromReasoning(string $reasoning, int $maxLength = 100): string
        {
            $text = trim($reasoning);
            if ($text === '') {
                return '';
            }

            if ($maxLength <= 0) {
                $maxLength = 100;
            }

            $paragraphs = preg_split('/\r\n|\r|\n/', $text);
            if ($paragraphs === false) {
                return '';
            }

            $banned = '/^(the user|user|i |i\'?ll|i will|let me|let\'s|first,|first |step \d|okay|ok,|'
                . 'sure|here|we |we\'?ll|now |next,|开始|首先|用户|我需要|我将|让我|好的|现在)/iu';

            $picked = array();

            foreach ($paragraphs as $p) {
                // 去掉列表/标题标记后按段判断
                $p = trim((string) preg_replace('/^\s*(?:[#>*\-+]|\d+[.)、])\s*/u', '', trim($p)) ?? '');

                $len = mb_strlen($p, 'UTF-8');

                // 过短碎片、以冒号结尾的过渡句、指令叙述句都跳过
                if ($len < 12 || str_ends_with($p, ':') || str_ends_with($p, '：') || preg_match($banned, $p)) {
                    continue;
                }

                // 中文占比过低视为英文思考过程
                $cn = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $p);
                if ($cn < 8) {
                    continue;
                }

                $picked[] = $p;

                if (mb_strlen(implode('', $picked), 'UTF-8') >= $maxLength) {
                    break;
                }
            }

            if (empty($picked)) {
                return '';
            }

            $out = trim((string) preg_replace('/^["“「]+|["”」]+$/u', '', implode('', $picked)));

            return mb_strlen($out, 'UTF-8') > $maxLength ? (string) mb_substr($out, 0, $maxLength, 'UTF-8') : $out;
        }

        /**
         * 截断字符串
         */
        public static function cut(string $str, int $len): string
        {
            return (string) mb_substr($str, 0, $len, 'UTF-8');
        }

        /**
         * curl 错误码对应的可操作提示
         */
        public static function curlHint(int $errno): string
        {
            return match ($errno) {
                6 => '域名解析失败：API 地址拼写是否正确？',
                7 => '无法连接：目标端口不通，或被服务器防火墙拦截。',
                28 => '请求超时：网络慢或 API 无响应，可稍后重试。',
                35, 51, 60 => 'SSL 证书校验失败：检查服务器时间是否准确、证书链是否完整（宝塔可更新 CA 证书）。',
                default => ''
            };
        }

        /**
         * 统一的 curl POST：固定校验上游证书；禁止跟随重定向（CURLOPT_HTTPHEADER 会在
         * 重定向间重发，避免 Authorization 头被带到外部域名）；上游 3xx 视为异常返回。
         *
         * @return array{success:bool,httpCode:int,body:string,error:string,errno:int}
         */
        public static function request(string $url, string $apiKey, array $data, int $timeout = 30): array
        {
            if (!function_exists('curl_init')) {
                return array(
                    'success' => false,
                    'httpCode' => 0,
                    'body' => '',
                    'error' => '服务器没有启用 curl 扩展（宝塔：软件商店 → PHP → 安装扩展 curl）',
                    'errno' => -2
                );
            }

            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($payload === false) {
                return array(
                    'success' => false,
                    'httpCode' => 0,
                    'body' => '',
                    'error' => '请求体 JSON 编码失败：' . json_last_error_msg(),
                    'errno' => -1
                );
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . str_replace(array("\r", "\n"), '', $apiKey)
                ),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Typecho-AIContentSummary/' . self::VERSION,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ));

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = $errno !== 0 ? curl_error($ch) : '';

            curl_close($ch);

            if ($errno !== 0) {
                return array('success' => false, 'httpCode' => 0, 'body' => '', 'error' => $error, 'errno' => $errno);
            }

            return array('success' => true, 'httpCode' => $httpCode, 'body' => (string) $body, 'error' => '', 'errno' => 0);
        }

        /**
         * 执行一次连通性测试，返回可直接 JSON 化的结果
         *
         * @param mixed $cfg 插件配置（数组）
         */
        public static function runTest(mixed $cfg): array
        {
            $apiUrl = trim(self::opt($cfg, 'apiUrl'));
            $apiKey = trim(self::opt($cfg, 'apiKey'));
            $model = trim(self::opt($cfg, 'modelName', self::DEFAULT_MODEL));

            if ($apiUrl === '' || $apiKey === '') {
                return array(
                    'ok' => false,
                    'msg' => 'API 地址或 API KEY 为空（改完配置记得点页面底部「保存设置」再测试）',
                    'stage' => 'config'
                );
            }

            if (!str_starts_with(strtolower($apiUrl), 'http://') && !str_starts_with(strtolower($apiUrl), 'https://')) {
                return array('ok' => false, 'msg' => 'API 地址必须以 http:// 或 https:// 开头', 'stage' => 'config');
            }

            $endpoint = self::buildEndpoint($apiUrl);
            $payload = self::buildPayload(
                $model,
                array(array('role' => 'user', 'content' => '只回复四个字：测试成功')),
                array('max_tokens' => 128)
            );

            $start = microtime(true);
            $result = self::postChat($endpoint, $apiKey, $payload, 30);
            $cost = (int) round((microtime(true) - $start) * 1000);

            if (!$result['success']) {
                return array(
                    'ok' => false,
                    'msg' => '网络层请求失败：' . $result['error'],
                    'stage' => 'network',
                    'endpoint' => $endpoint,
                    'cost' => $cost . 'ms',
                    'hint' => self::curlHint($result['errno'])
                );
            }

            $body = $result['body'];
            $json = json_decode($body, true);
            $first = is_array($json) && is_array($json['choices'][0] ?? null) ? $json['choices'][0] : array();
            $message = is_array($first['message'] ?? null) ? $first['message'] : array();
            $reply = isset($message['content']) && is_scalar($message['content']) ? trim((string) $message['content']) : '';
            $finish = is_string($first['finish_reason'] ?? null) ? $first['finish_reason'] : '';

            // 思考型模型可能把 max_tokens 全用于推理，内容为空但请求已成功，不算测试失败
            if ($result['httpCode'] === 200 && $reply === '' && $finish !== '') {
                return array(
                    'ok' => true,
                    'msg' => $finish === 'length'
                        ? 'API 连通正常（回复被 max_tokens 截断，正文较短时不影响摘要生成）'
                        : 'API 连通正常（模型未返回正文，finish_reason=' . $finish . '）',
                    'stage' => 'ok',
                    'endpoint' => $endpoint,
                    'model' => $model,
                    'reply' => '（空）',
                    'httpCode' => $result['httpCode'],
                    'temperature' => self::temperatureLabel($model, $result),
                    'cost' => $cost . 'ms'
                );
            }

            if ($result['httpCode'] !== 200 || $reply === '') {
                $errMsg = '';
                if (is_array($json)) {
                    if (isset($json['error']) && is_array($json['error']) && is_string($json['error']['message'] ?? null)) {
                        $errMsg = $json['error']['message'];
                    } elseif (is_string($json['message'] ?? null)) {
                        $errMsg = $json['message'];
                    }
                }

                return array(
                    'ok' => false,
                    'msg' => 'API 返回异常' . ($errMsg !== '' ? '：' . $errMsg : ''),
                    'stage' => 'api',
                    'httpCode' => $result['httpCode'],
                    'endpoint' => $endpoint,
                    'model' => $model,
                    'response' => self::cut($body, 800),
                    'cost' => $cost . 'ms',
                    'hint' => self::apiHint($result['httpCode'], $body, $model)
                );
            }

            return array(
                'ok' => true,
                'msg' => 'API 连通正常',
                'stage' => 'ok',
                'endpoint' => $endpoint,
                'model' => $model,
                'reply' => $reply,
                'httpCode' => $result['httpCode'],
                'temperature' => self::temperatureLabel($model, $result),
                'cost' => $cost . 'ms'
            );
        }

        /**
         * 展示用 temperature 说明：固定温度模型用服务端默认值，其余显示实际值或回退状态
         */
        private static function temperatureLabel(string $model, array $result): string
        {
            if (self::isFixedTemperature($model)) {
                return '默认（该模型不支持自定义）';
            }

            return isset($result['adjusted']) ? '已自动回退：' . $result['adjusted'] : '0';
        }

        /**
         * 常见 API 报错的可读解释
         */
        public static function apiHint(int $httpCode, string $body, string $model = ''): string
        {
            if (self::isTemperatureError($httpCode, $body)) {
                return '该模型不接受自定义 temperature（如 Kimi K2 只允许 1），插件已自动回退，可重试；'
                    . '若仍报错请把模型名填成供应商文档里的完整名称。';
            }
            if (stripos($body, 'model_not_found') !== false
                || stripos($body, 'model not found') !== false
                || stripos($body, 'invalid model') !== false) {
                return '模型名不对：' . ($model !== '' ? '当前填的是 ' . $model . '，' : '')
                    . '请到供应商控制台复制准确的模型 ID（注意大小写与日期后缀）。';
            }

            return match (true) {
                $httpCode === 401 || $httpCode === 403 => 'API KEY 无效或没有该模型的权限：检查 KEY 是否复制完整、是否过期、是否已充值。',
                $httpCode === 404 => '接口地址不对：一般应填 https://api.xxx.com/v1（插件会自动补 /chat/completions）。',
                $httpCode === 429 => '触发限流或余额不足：稍后重试，或检查账户额度。',
                $httpCode === 400 && stripos($body, 'max_tokens') !== false => 'max_tokens 超出该模型上限，或该模型要求用 max_completion_tokens。',
                $httpCode >= 500 => '供应商服务端异常，通常稍后重试即可。',
                default => ''
            };
        }
    }
}
