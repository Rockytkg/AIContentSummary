<?php
/**
 * AIContentSummary 摘要管理动作
 *
 * 后台「摘要管理」面板的接口层：生成 / 保存 / 清空 / 批量生成。
 * 仅接受管理员 POST 请求，统一返回 JSON。
 *
 * 相对上游 2.0 的改动：
 *  - PHP 8.4 兼容：match 分支不再依赖异常携带非标准 HTTP code，
 *    所有异常码统一收敛到 400~599，避免 response->setStatus() 抛错。
 *  - 批量生成改为按 cid 列表逐条调用，前端串行推进；单条失败不影响其余。
 *  - 生成逻辑统一走 Api::generateSummary()，与发布钩子/独立接口行为一致
 *    （思考型模型的 max_tokens 放大重试与 reasoning 兜底都在这里生效）。
 *
 * 版权与来源：基于 Rockytkg/AIContentSummary 修改，原项目版权归 Rockytkg 所有；
 * 修改者 Andrew8102，Apache-2.0。
 *
 * @package AIContentSummary
 * @author Rockytkg, Andrew8102
 * @version 2.1.0
 * @link https://github.com/Rockytkg/AIContentSummary
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

            $do = (string) $this->request->get('do');

            switch ($do) {
                case 'generate':
                    $cid = (int) $this->request->filter('int')->get('cid');
                    if ($cid < 1) {
                        throw new Exception(_t('无效的文章 ID'), 400);
                    }
                    $response['message'] = _t('摘要生成成功');
                    // 统一复用插件主逻辑，保持编辑页自动生成与后台手动生成行为一致
                    $response['data'] = Plugin::generateForPost($cid);
                    break;

                case 'save':
                    $cid = (int) $this->request->filter('int')->get('cid');
                    if ($cid < 1) {
                        throw new Exception(_t('无效的文章 ID'), 400);
                    }
                    $summary = (string) $this->request->get('summary', '');
                    $response['message'] = trim($summary) === ''
                        ? _t('摘要已清空')
                        : _t('摘要保存成功');
                    // 保存与清空走同一入口，由插件主逻辑判断摘要内容是否为空
                    $response['data'] = Plugin::saveManual($cid, $summary);
                    break;

                default:
                    throw new Exception(_t('未知操作'), 400);
            }
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            $response = array(
                'success' => false,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : _t('请求失败')
            );
        }

        // 兜底修正异常码：Typecho 的 Exception 常带 404 之类的码，
        // 但业务异常可能给 0 或非标准值，统一收敛到 4xx/5xx 区间。
        if (!$response['success'] && ($status < 400 || $status >= 600)) {
            $status = 500;
        }

        $this->response
            ->setStatus($status)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->throwJson($response);
    }
}
