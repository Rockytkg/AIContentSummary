<?php

namespace TypechoPlugin\AIContentSummary;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Db;
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
 * @version 1.3
 * @link https://github.com/Rockytkg/AIContentSummary
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法，如果激活失败，直接抛出异常
     *
     * 注册自定义摘要生成方法和文章发布完成时的回调
     */
    public static function activate(): void
    {
        // 注册自定义摘要生成方法
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = __CLASS__ . '::customExcerpt';
        // 注册文章发布完成时的回调
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->finishPublish = __CLASS__ . '::onFinishPublish';
        // 注册文章删除时的回调
        \Typecho\Plugin::factory('Widget\Contents\Post\Edit')->delete = __CLASS__ . '::onDelete';
    }

    /**
     * 禁用插件方法，如果禁用失败，直接抛出异常
     *
     * 插件禁用时无需额外操作
     */
    public static function deactivate()
    {
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板对象
     */
    public static function config(Form $form): void
    {
        /** 模型名称 */
        $modelName = new Text('modelName', null, 'gpt-3.5-turbo-16k', _t('模型名称'), _t('用于生成摘要的 AI 模型名称'));
        $form->addInput($modelName->addRule('required', _t('模型名称不能为空')));

        /** API Key */
        $keyValue = new Text('keyValue', null, null, _t('API Key'), _t('用于调用 API 的密钥'));
        $form->addInput($keyValue->addRule('required', _t('API Key 不能为空')));

        /** API 地址 */
        $apiUrl = new Text('apiUrl', null, null, _t('API 地址'), _t('API 的完整地址，例如 https://api.example.com/v1'));
        $form->addInput($apiUrl->addRule('required', _t('API 地址不能为空')));

        /** 摘要最大长度 */
        $maxLength = new Text('maxLength', null, '100', _t('摘要最大长度'), _t('生成摘要的最大字符数, 无法准确控制'));
        $form->addInput($maxLength->addRule('isInteger', _t('请输入一个整数'))->addRule('required', _t('摘要长度不能为空')));

        /** 自定义字段名称 */
        $fieldName = new Text('fieldName', null, 'ai_summary', _t('自定义字段名称'), _t('用于保存生成的摘要的自定义字段名称，默认为 ai_summary'));
        $form->addInput($fieldName->addRule('required', _t('字段名称不能为空')));
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
     * 自定义摘要生成方法
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
        $maxLength = $options->maxLength;

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
     * 在文章发布完成后，调用 AI API 生成摘要并保存到自定义字段中
     *
     * @param array $contents 文章内容
     * @param Edit $obj 文章编辑对象
     * @return array 文章内容
     * @throws Db\Exception|Exception
     */
    public static function onFinishPublish(array $contents, Edit $obj): array
    {
        $db = Db::get();
        $options = Options::alloc()->plugin('AIContentSummary');
        $fieldName = $options->fieldName ?? 'ai_summary'; // 默认字段名称为 ai_summary

        // 检查是否已存在自定义字段
        $rows = $db->fetchRow($db->select('str_value')
            ->from('table.fields')
            ->where('cid = ?', $obj->cid)
            ->where('name = ?', $fieldName));

        // 如果不存在或为空，调用 API 生成摘要
        if (!$rows || empty($rows['str_value'])) {
            $title = $contents['title'];
            $text = $contents['text'];
            $apiResponse = self::callApi($title, $text);

            // 保存生成的摘要到自定义字段
            if ($rows) {
                // 更新已存在的字段
                $db->query($db->update('table.fields')
                    ->rows(['value' => $apiResponse])
                    ->where('cid = ?', $obj->cid)
                    ->where('name = ?', $fieldName));
            } else {
                // 插入新字段
                $db->query($db->insert('table.fields')
                    ->rows([
                        'cid' => $obj->cid,
                        'name' => $fieldName,
                        'type' => 'str',
                        'str_value' => $apiResponse,
                        'int_value' => 0,
                        'float_value' => 0
                    ]));
            }
        }

        return $contents;
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
     * 调用 AI API 生成摘要
     *
     * 通过调用 AI API 生成文章摘要，支持重试机制
     *
     * @param string $text 文章内容
     * @return string 生成的摘要
     * @throws Exception
     */
    private static function callApi(string $text): string
    {
        $options = Options::alloc()->plugin('AIContentSummary');
        $modelName = $options->modelName;
        $keyValue = $options->keyValue;
        $apiUrl = rtrim($options->apiUrl, '/') . '/chat/completions';
        $maxLength = $options->maxLength;

        // 设置请求头
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keyValue
        ];

        // 构造请求数据
        $data = [
            "model" => $modelName,
            "messages" => [
                [
                    "role" => "system",
                    "content" => "请对以下文章内容进行概括并生成摘要，摘要不要超过 $maxLength 字。"
                ],
                [
                    "role" => "user",
                    "content" => addslashes($text)
                ]
            ],
            "temperature" => 0
        ];

        $maxRetries = 5; // 最大重试次数
        $retries = 0; // 当前重试次数
        $waitTime = 2; // 初始等待时间（秒）

        // 重试机制
        while ($retries < $maxRetries) {
            try {
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $response = curl_exec($ch);

                // 检查是否有错误
                if (curl_errno($ch)) {
                    throw new \Exception(curl_error($ch), curl_errno($ch));
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                // 如果请求成功，返回生成的摘要
                if ($httpCode == 200) {
                    $decodedResponse = json_decode($response, true);
                    return trim($decodedResponse['choices'][0]['message']['content']);
                }

                // 如果请求失败，抛出异常
                throw new \Exception("HTTP 状态码: " . $httpCode);
            } catch (\Exception $e) {
                $retries++;
                sleep($waitTime); // 等待一段时间后重试
                $waitTime *= 2; // 每次重试等待时间翻倍
            }
        }

        // 如果重试次数用尽，返回空字符串
        return "";
    }
}
