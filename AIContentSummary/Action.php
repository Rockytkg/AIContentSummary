<?php

namespace TypechoPlugin\AIContentSummary;

use Typecho\Widget\Exception;
use Widget\ActionInterface;
use Widget\Base;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 摘要管理动作入口。
 */
final class Action extends Base implements ActionInterface
{
    /**
     * 处理摘要管理请求。
     *
     * 仅接受管理员 POST 请求，并统一返回 JSON 结构给后台模板页消费。
     */
    public function action(): void
    {
        $status = 200;
        $response = ['success' => true];

        try {
            $this->user->pass('administrator');
            $this->security->protect();

            if (!$this->request->isPost()) {
                throw new Exception(_t('请求方式错误'), 405);
            }

            $cid = $this->request->filter('int')->get('cid');
            if ($cid < 1) {
                throw new Exception(_t('无效的文章 ID'), 400);
            }

            $response += match ((string) $this->request->get('do')) {
                'generate' => $this->generate($cid),
                'save' => $this->save($cid),
                default => throw new Exception(_t('未知操作'), 400),
            };
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            $response = [
                'success' => false,
                'message' => $exception->getMessage() ?: _t('请求失败'),
            ];
        }

        // 兜底修正异常码，避免非标准 code 影响前端错误处理。
        if (!$response['success'] && ($status < 400 || $status >= 600)) {
            $status = 500;
        }

        $this->response
            ->setStatus($status)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->throwJson($response);
    }

    /**
     * 生成并返回摘要响应。
     *
     * @return array{message:string,data:array{cid:int,summary:string,length:int,hasSummary:bool}}
     */
    private function generate(int $cid): array
    {
        return [
            'message' => _t('摘要生成成功'),
            // 统一复用插件主逻辑，保持编辑页自动生成与后台手动生成行为一致。
            'data' => Plugin::generateForPost($cid),
        ];
    }

    /**
     * 保存并返回摘要响应。
     *
     * @return array{message:string,data:array{cid:int,summary:string,length:int,hasSummary:bool}}
     */
    private function save(int $cid): array
    {
        return [
            'message' => _t('摘要保存成功'),
            // 保存与清空都走同一个入口，由插件主逻辑判断摘要内容是否为空。
            'data' => Plugin::saveManual(
                $cid,
                (string) $this->request->get('summary', '')
            ),
        ];
    }
}
