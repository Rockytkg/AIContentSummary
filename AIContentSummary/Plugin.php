<?php

/**
 * AIContentSummary 是一个用于通过调用AI接口，根据文章内容生成摘要的 Typecho 插件
 *
 * @package AIContentSummary
 * @author Joinliu
 * @version 1.2
 * @link https://letanml.xyz
 */
class AIContentSummary_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Abstract_Contents')->excerptEx = array('AIContentSummary_Plugin', 'customExcerpt');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('AIContentSummary_Plugin', 'onFinishPublish');
    }

    public static function deactivate()
    {
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 添加输入框：模型名
        $modelName = new Typecho_Widget_Helper_Form_Element_Text(
            'modelName',
            NULL,
            'gpt-3.5-turbo-16k',
            _t('输入总结内容使用的模型名'),
            _t('请输入模型名，默认为gpt-3.5-turbo-16k')
        );
        $form->addInput($modelName);

        // 添加输入框：key值
        $keyValue = new Typecho_Widget_Helper_Form_Element_Text(
            'keyValue',
            NULL,
            NULL,
            _t('API KEY'),
            _t('输入调用用API的key')
        );
        $form->addInput($keyValue);

        // 添加输入框：API地址
        $apiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiUrl',
            NULL,
            NULL,
            _t('输入地址'),
            _t('请输入API地址，不要省略（https://）或（http://）不要带有（/v1）')
        );
        $form->addInput($apiUrl);

        // 添加输入框：摘要最大长度
        $maxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'maxLength',
            NULL,
            '100',
            _t('摘要最大长度'),
            _t('请输入输出摘要的最大文字数量。')
        );
        $form->addInput($maxLength);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function customExcerpt($excerpt, $widget)
    {
        $customContent = $widget->fields->content;
        $maxLength = Typecho_Widget::widget('Widget_Options')->plugin('AIContentSummary')->maxLength;

        if (!empty($customContent)) {
            $excerpt = $customContent;

            if (mb_strlen($excerpt) > $maxLength) {
                $excerpt = mb_substr($excerpt, 0, $maxLength) . '...';
            }
        }

        return $excerpt;
    }

    public static function onFinishPublish($contents, $obj)
    {
        $db = Typecho_Db::get();
        
        // 检查 'content' 字段是否存在并获取其值
        $rows = $db->fetchRow($db->select('str_value')->from('table.fields')->where('cid = ?', $obj->cid)->where('name = ?', 'content'));
        
        // 如果 'content' 字段不存在或其值为空，则使用 callApi 生成内容
        if (!$rows || empty($rows['str_value'])) {
            $title = $contents['title'];
            $text = $contents['text'];
            $apiResponse = self::callApi($title, $text);
    
            // 保存到自定义字段 'content'
            if ($rows) {
                $db->query($db->update('table.fields')->rows(array('str_value' => $apiResponse))->where('cid = ?', $obj->cid)->where('name = ?', 'content'));
            } else {
                $db->query($db->insert('table.fields')->rows(array('cid' => $obj->cid, 'name' => 'content', 'type' => 'str', 'str_value' => $apiResponse, 'int_value' => 0, 'float_value' => 0)));
            }
        }
    
        return $contents;
    }
    

    private static function callApi($title, $text)
    {
        // 获取用户填入的值
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIContentSummary');
        $modelName = $options->modelName;
        $keyValue = $options->keyValue;
        $apiUrl = rtrim($options->apiUrl, '/') . '/v1/chat/completions';
        $maxLength = $options->maxLength;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keyValue
        ];

        $title = addslashes($title);
        $prompt = addslashes($text);

        $data = array(
            "model" => $modelName,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => "请你扮演一个文本摘要生成器，下面是一篇关于 ’{$title}‘ 的文章，请你根据文章内容生成 {$maxLength} 字左右的摘要，除了你生成的的摘要内容，请不要输出其他任何无关内容"
                ),
                array(
                    "role" => "user",
                    "content" => $prompt
                )
            ),
            "temperature" => 0
        );

        $maxRetries = 5;
        $retries = 0;
        $waitTime = 2;

        while ($retries < $maxRetries) {
            try {
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new Exception(curl_error($ch), curl_errno($ch));
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                if ($httpCode == 200) {
                    $decodedResponse = json_decode($response, true);
                    return trim($decodedResponse['choices'][0]['message']['content']);
                }

                throw new Exception("HTTP status code: " . $httpCode);
            } catch (Exception $e) {
                $retries++;
                sleep($waitTime);
                $waitTime *= 2;
            }
        }

        return "";
    }
}