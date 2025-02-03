<?php

namespace TypechoPlugin\AIContentSummary;

use Typecho\Widget;
use Typecho\Db;
use Typecho\Widget\Exception;
use Widget\ActionInterface;

/**
 * 摘要生成与管理控制器
 *
 * @package AIContentSummary
 */
class Action extends Widget implements ActionInterface
{
    /**
     * 前置校验（权限+请求方法）
     *
     * @throws Exception|Db\Exception
     */
    private function preCheck()
    {
        // 统一权限校验
        if (!Widget::widget('Widget\User')->pass('administrator')) {
            throw new Exception(_t('对不起,只有管理员才能进行此操作'), 403);
        }

        // 统一请求方法校验
        if (!$this->request->isPost()) {
            throw new Exception(_t('请求方式错误'), 405);
        }
    }

    /**
     * 主入口方法
     */
    public function action()
    {
        try {
            $this->preCheck();

            $operation = $this->request->get('do');
            switch ($operation) {
                case 'generate':
                    $this->generateSummary();
                    break;
                case 'save':
                    $this->saveSummary();
                    break;
                default:
                    throw new Exception(_t('未知的操作类型'), 400);
            }
        } catch (\Exception $e) {
            $this->response->setStatus($e->getCode() ?: 500);
            $this->response->throwJson([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 生成文章摘要
     *
     * @throws Db\Exception
     * @throws Exception
     */
    private function generateSummary()
    {
        $data = $this->parseJsonBody();
        $cid = $data['cid'] ?? null;

        if (empty($cid) || !is_numeric($cid)) {
            throw new Exception(_t('无效的文章ID'), 400);
        }

        $content = $this->getPostContent((int)$cid);
        try {
            $summary = Plugin::callApi($content);
            Plugin::saveSummary((int)$cid, $summary);
        } catch (\Exception $e) {
            throw new Exception($e->getMessage(), 500);
        }

        $this->response->throwJson([
            'success' => true,
            'summary' => $summary,
            'message' => _t('生成成功')
        ]);
    }

    /**
     * 手动保存摘要
     *
     * @throws Exception|Db\Exception
     */
    private function saveSummary()
    {
        $data = $this->parseJsonBody();
        $cid = $data['cid'] ?? null;

        if (empty($cid) || !is_numeric($cid)) {
            throw new Exception(_t('无效的文章ID'), 400);
        }

        if (!isset($data['summary'])) {
            throw new Exception(_t('缺少摘要内容'), 400);
        }

        Plugin::saveSummary((int)$cid, trim($data['summary']));

        $this->response->throwJson([
            'success' => true,
            'message' => _t('保存成功')
        ]);
    }

    /**
     * 解析并验证 JSON 请求体
     *
     * @return array
     * @throws Exception
     */
    private function parseJsonBody(): array
    {
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            throw new Exception(_t('请求体为空'), 400);
        }

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(_t('JSON解析失败'), 400);
        }

        return $data;
    }

    /**
     * 获取文章内容
     *
     * @param int $cid 文章ID
     * @return string
     * @throws Db\Exception
     * @throws Exception
     */
    private function getPostContent(int $cid): string
    {
        $db = Db::get();
        $post = $db->fetchRow($db->select('text')
            ->from('table.contents')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->limit(1)
        );

        if (empty($post)) {
            throw new Exception(_t('文章不存在或未发布'), 404);
        }

        return (string)$post['text'];
    }
}
