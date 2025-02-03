<?php

namespace TypechoPlugin\AIContentSummary;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Db;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Url;
use Typecho\Widget\Helper\Layout;
use Utils\Helper;
use Widget\Base\Contents;
use Widget\Contents\Post\Edit;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * AIContentSummary 是一个用于通过 AI 生成文章摘要的 Typecho 插件
 *
 * @package AIContentSummary
 * @author Rockytkg
 * @version 1.4
 * @link https://github.com/Rockytkg/AIContentSummary
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法，如果激活失败，直接抛出异常
     *
     * 注册自定义摘要生成方法和文章发布完成时的回调
     * @throws Exception
     */
    public static function activate(): void
    {
        // 检查 curl 扩展是否已安装
        if (!extension_loaded('curl')) {
            // 使用 Typecho 的插件异常机制，阻止插件激活
            throw new Exception(_t('需要启用 PHP cURL 扩展才能使用本插件'));
        }

        // 注册自定义摘要生成方法
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = __CLASS__ . '::customExcerpt';
        // 注册文章发布完成时的回调
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::onFinishPublish';
        // 注册文章删除时的回调
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->delete = __CLASS__ . '::onDelete';
        // 添加后台管理页面
        Helper::addPanel(3, 'AIContentSummary/template/summaries.php', _t('摘要管理'), _t('管理AI摘要'), 'administrator');
        // 注册管理路由
        Helper::addAction('summaries', 'TypechoPlugin\AIContentSummary\Action');
        // 注册文章编辑页的默认字段
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->getDefaultFieldItems = __CLASS__ . '::addDefaultFieldItems';
        // 注册输出文章内容时的回调
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = __CLASS__ . '::customContent';
    }

    /**
     * 禁用插件方法，如果禁用失败，直接抛出异常
     *
     * 插件禁用时无需额外操作
     */
    public static function deactivate()
    {
        // 移除后台管理页面
        Helper::removePanel(3, 'AIContentSummary/template/summaries.php');
        // 注销路由
        Helper::removeAction('summaries');
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板对象
     */
    public static function config(Form $form): void
    {
        /** 模型名称 */
        $modelName = new Text('modelName', null, 'gpt-3.5-turbo', _t('模型名称'), _t('用于生成摘要的 AI 模型名称'));
        $form->addInput($modelName->addRule('required', _t('模型名称不能为空')));

        /** API Key */
        $apiKey = new Password('apiKey', null, null, _t('API Key'), _t('用于调用 API 的密钥'));
        $form->addInput($apiKey->addRule('required', _t('API Key 不能为空')));

        /** API 地址 */
        $apiUrl = new Url('apiUrl', null, null, _t('API 地址'), _t('API 的完整地址，例如 https://api.example.com/v1'));
        $form->addInput($apiUrl->addRule('required', _t('API 地址不能为空')));

        /** 系统提示词 */
        $prompt = new Textarea('prompt', null,
            "你是一个专业的文章摘要生成专家。请严格按照以下要求执行：\n\n" .
            "1. 输入：完整的文章内容\n" .
            "2. 输出语言：简体中文\n" .
            "3. 输出：简洁的摘要\n" .
            "4. 限制条件：\n" .
            "   - 最大长度：100 字符\n" .
            "   - 保持关键信息密度\n" .
            "   - 保留原文语气和风格\n" .
            "   - 聚焦核心概念和发现\n" .
            "5. 格式：纯文本，无标记\n" .
            "6. 质量检查：\n" .
            "   - 事实准确性\n" .
            "   - 逻辑连贯性\n" .
            "   - 信息完整性\n" .
            "   - 语言一致性",
            _t('系统提示词'),
            _t('生成摘要的系统提示词')
        );
        $form->addInput($prompt->addRule('required', _t('Prompt 不能为空')));

        /** 自定义字段名称 */
        $fieldName = new Text('fieldName', null, 'ai_summary', _t('自定义字段名称'), _t('用于保存生成的摘要的自定义字段名称，默认为 ai_summary'));
        $form->addInput($fieldName->addRule('required', _t('字段名称不能为空')));

        /** 发布是否生成摘要 */
        $finishPublishSummary = new Radio('finishPublishSummary', array('1' => _t('是'), '0' => _t('否')), '1', _t('是否生成摘要'), _t('是否在文章修改或发布时生成摘要，会使得发布速度变慢，请耐心等待'));
        $form->addInput($finishPublishSummary);

        /** 摘要长度 */
        $summaryLength = new Text('summaryLength', null, '100', _t('摘要长度'), _t('首页输出的摘要的最大长度（字符数）'));
        $form->addInput($summaryLength->addRule('required', _t('摘要长度不能为空'))->addRule('isInteger', _t('摘要长度必须为整数'))->addRule('min', _t('摘要长度不能小于 1'), 1));

        /** 正文头部是否输出摘要 */
        $outputSummaryInHeader = new Radio('outputSummaryInHeader', array('1' => _t('是'), '0' => _t('否')), '1', _t('文章头部是否输出摘要'), _t('是否在文章头部输出生成的摘要'));
        $form->addInput($outputSummaryInHeader);

        /** 正文摘要模板 */
        $summaryTemplate = new Textarea('summaryTemplate', null,
            "<div class=\"ai-summary\">\n" .
            "    <b>摘要：</b>{summary}\n" .
            "</div>\n\n" .
            "<style>\n" .
            "    .ai-summary {\n" .
            "        background-color: #2C3E50;\n" .
            "        color: white;\n" .
            "        padding: 20px;\n" .
            "        border-radius: 10px;\n" .
            "        margin-bottom: 1rem;\n" .
            "    }\n" .
            "</style>",
            _t('正文摘要模板'),
            _t('用于在正文中显示摘要的模板，使用 {summary} 作为摘要内容的占位符')
        );
        $form->addInput($summaryTemplate->addRule('required', _t('正文摘要模板不能为空')));
    }


    /**
     * 个人用户的配置面板
     *
     * @param Form $form 配置面板对象
     */
    public static function personalConfig(Form $form)
    {
        // 无需个人配置
    }

    /**
     * 自定义配置方法
     *
     * @param array $settings 配置项
     * @param bool $isInit 是否为初始化
     * @throws Db\Exception|Exception
     */
    public static function configHandle(array $settings, bool $isInit)
    {
        // 只在非初始化时处理配置更新
        if (!$isInit) {
            $settings = array_map('trim', $settings);

            if (isset($settings['fieldName'])) {
                $newFieldName = $settings['fieldName'];
                $options = \Typecho\Widget::widget('Widget\Options');
                $oldFieldName = $options->plugin('AIContentSummary')->fieldName ?? 'ai_summary';

                if ($oldFieldName !== $newFieldName) {
                    $db = Db::get();
                    $db->query(
                        $db->delete('table.fields')
                            ->where('name = ?', $newFieldName)
                    );
                    $db->query(
                        $db->update('table.fields')
                            ->rows(['name' => $newFieldName])
                            ->where('name = ?', $oldFieldName)
                    ); // 确保执行数据库更新操作
                }
            }
        }

        // 保存插件配置
        \Widget\Plugins\Edit::configPlugin('AIContentSummary', $settings);
    }


    /**
     * 自定义摘要输出方法
     *
     * 根据文章内容生成摘要，如果自定义字段中有内容，则优先使用自定义字段的内容
     *
     * @param string $excerpt 原始摘要
     * @param Contents $widget 文章内容对象
     * @return string 生成的摘要
     * @throws Exception
     */
    public static function customExcerpt(string $excerpt, Contents $widget): string
    {
        $options = Options::alloc()->plugin('AIContentSummary');
        $fieldName = $options->fieldName ?? 'ai_summary'; // 默认字段名称为 ai_summary
        $customContent = $widget->fields->$fieldName ?? null; // 获取自定义字段的内容
        $maxLength = $options->summaryLength;

        // 如果自定义字段中有内容，则使用该内容作为摘要
        if (!empty($customContent)) {
            $excerpt = $customContent;

            // 如果摘要长度超过最大长度，则截断并添加省略号
            if (mb_strlen($excerpt) > $maxLength) {
                $excerpt = mb_substr($excerpt, 0, $maxLength) . '...';
            }
        }

        return $excerpt;
    }

    /**
     * 文章发布完成时的回调
     *
     * @param array $contents 文章内容
     * @param Edit $obj 文章编辑对象
     * @throws Db\Exception|Exception
     */
    public static function onFinishPublish(array $contents, Edit $obj): void
    {
        $options = Options::alloc()->plugin('AIContentSummary');
        // 判断是否开启发布文章生成摘要
        if ($options->finishPublishSummary === '0') {
            return;
        }
        $fieldName = $options->fieldName ?? 'ai_summary';

        $db = Db::get();
        $rows = $db->fetchRow($db->select('str_value')
            ->from('table.fields')
            ->where('cid = ?', $obj->cid)
            ->where('name = ?', $fieldName));

        if (!$rows || empty($rows['str_value'])) {
            $apiResponse = self::callApi($contents['text']);
            self::saveSummary($obj->cid, $apiResponse);
        }
    }

    /**
     * 保存文章摘要到数据库
     *
     * @param int $cid 文章ID
     * @param string $summary 摘要内容
     * @throws Db\Exception
     */
    public static function saveSummary(int $cid, string $summary): void
    {
        $db = Db::get();
        $fieldName = Options::alloc()->plugin('AIContentSummary')->fieldName;
        $rows = $db->fetchRow($db->select('str_value')
            ->from('table.fields')
            ->where('cid = ?', $cid)
            ->where('name = ?', $fieldName));

        if ($rows) {
            // 更新已存在的字段
            $db->query($db->update('table.fields')
                ->rows(['str_value' => $summary])
                ->where('cid = ?', $cid)
                ->where('name = ?', $fieldName));
        } else {
            // 插入新字段
            $db->query($db->insert('table.fields')
                ->rows([
                    'cid' => $cid,
                    'name' => $fieldName,
                    'type' => 'str',
                    'str_value' => $summary,
                    'int_value' => 0,
                    'float_value' => 0
                ]));
        }
    }

    /**
     * 文章删除时的回调
     *
     * 在文章删除时，删除与该文章关联的摘要字段
     *
     * @param int $cid 文章 ID
     * @throws Db\Exception|Exception
     */
    public static function onDelete(int $cid): void
    {
        $db = Db::get();
        $options = Options::alloc()->plugin('AIContentSummary');
        $fieldName = $options->fieldName ?? 'ai_summary'; // 默认字段名称为 ai_summary

        // 删除与该文章关联的摘要字段
        $db->query($db->delete('table.fields')
            ->where('cid = ?', $cid)
            ->where('name = ?', $fieldName));
    }

    /**
     * 为文章编辑页添加自定义字段
     *
     * @param Layout $layout 布局
     * @return void
     * @throws Exception
     */
    public static function addDefaultFieldItems(Layout $layout)
    {
        $summaries = new Textarea(
            Options::alloc()->plugin('AIContentSummary')->fieldName,
            NULL,
            NULL,
            '自定义摘要',
            '介绍：自定义摘要字段，输出摘要会优先输出这个字段的摘要'
        );
        $layout->addItem($summaries);
        echo '<style>
            textarea[name="fields[' . Options::alloc()->plugin('AIContentSummary')->fieldName . ']"] {
                width: 100%;
                height: 80px;
            }
        </style>';
    }

    /**
     *
     *
     */
    public static function customContent(string $content, Contents $widget): string
    {
        $options = Options::alloc()->plugin('AIContentSummary');
        if ($options->outputSummaryInHeader === '0') {
            return $content;
        }
        $fieldName = $options->fieldName ?? 'ai_summary'; // 默认字段名称为 ai_summary
        $excerpt = $widget->fields->$fieldName ?? null; // 获取自定义字段的内容

        // 如果自定义字段中有内容，则使用该内容作为摘要
        if (!empty($excerpt)) {
            $result = str_replace('{summary}', $excerpt, $options->summaryTemplate);
            $content = $result . $content;
        }

        return $content;
    }

    /**
     * 调用 AI API 生成摘要
     *
     * 通过调用 AI API 生成文章摘要，支持重试机制
     *
     * @param string $text 文章内容
     * @return string 生成的摘要
     * @throws Exception
     */
    public static function callApi(string $text): string
    {
        $opt = Options::alloc()->plugin('AIContentSummary');

        // 参数校验
        if (empty($opt->modelName) || empty($opt->apiKey) || empty($opt->apiUrl)) {
            throw new \InvalidArgumentException("插件配置为空！");
        }

        $ch = curl_init();
        try {
            $url = rtrim($opt->apiUrl, '/') . '/chat/completions';
            $payload = json_encode([
                'model' => $opt->modelName,
                'messages' => [
                    ['role' => 'system', 'content' => $opt->prompt],
                    ['role' => 'user', 'content' => $text]
                ],
                'temperature' => 0
            ], JSON_UNESCAPED_UNICODE);

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $opt->apiKey],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                throw new \RuntimeException(curl_error($ch), curl_errno($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                $errorInfo = json_decode($response, true);
                throw new \RuntimeException($errorInfo['error']['message'] ?? '未知错误');
            }

            $responseData = json_decode($response, true);
            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new \RuntimeException("API响应错误");
            }

            return trim($responseData['choices'][0]['message']['content']);
        } finally {
            curl_close($ch);
        }
    }
}
