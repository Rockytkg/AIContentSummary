<?php
declare(strict_types=1);

/**
 * AIContentSummary 摘要管理动作
 *
 * 后台「摘要管理」面板的接口层：生成 / 保存 / 清空。仅接受管理员 POST，统一返回 JSON。
 * 生成逻辑统一走 Api::generateSummary()，与发布钩子、独立接口行为一致。
 *
 * 环境要求：Typecho 1.3+，PHP 8.0+
 *
 * 版权与来源：原项目由 Rockytkg 开发（Apache-2.0）。
 */

namespace TypechoPlugin\AIContentSummary;

use Typecho\Widget\Exception;
use Widget\ActionInterface;
use Widget\Base;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Action extends Base implements ActionInterface
{
    /**
     * 处理摘要管理请求。
     */
    public function action(): void
    {
        $status = 200;
        $response = array('success' => true);

        try {
            $this->user->pass('administrator');
            $this->security->protect();

            if (!$this->request->isPost()) {
                throw new Exception(_t('请求方式错误'), 405);
            }

            $cid = (int) $this->request->filter('int')->get('cid');
            if ($cid < 1) {
                throw new Exception(_t('无效的文章 ID'), 400);
            }

            $summary = $this->request->get('summary', '');
            $summary = is_string($summary) ? $summary : '';

            // 复用插件主逻辑，与编辑页自动生成行为一致；保存与清空同一入口
            [$response['data'], $response['message']] = match ((string) $this->request->get('do')) {
                'generate' => array(Plugin::generateForPost($cid), _t('摘要生成成功')),
                'save' => array(
                    Plugin::saveManual($cid, $summary),
                    trim($summary) === '' ? _t('摘要已清空') : _t('摘要保存成功')
                ),
                default => throw new Exception(_t('未知操作'), 400)
            };
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            $response = array(
                'success' => false,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : _t('请求失败')
            );
        }

        // 兜底收敛状态码，避免传给 setStatus() 的值越出 4xx/5xx 区间
        if (!$response['success'] && ($status < 400 || $status >= 600)) {
            $status = 500;
        }

        $this->response
            ->setStatus($status)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->throwJson($response);
    }
}
