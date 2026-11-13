<?php
/**
 * AIContentSummary 补生成接口（独立文件，不经过 Typecho 路由）
 *
 * 用途：给「历史文章」补生成摘要，或手动重跑某一篇。
 * 只接受 POST，始终返回 JSON。
 *
 * 参数：
 *   t    = 由 API KEY 派生的令牌（必填）
 *   cid  = 指定文章 id；不传则返回「缺少摘要的文章列表」
 *
 * 版权与来源：基于 Rockytkg/AIContentSummary 修改，原项目版权归 Rockytkg 所有；
 * 修改者 Andrew8102，Apache-2.0。
 *
 * @package AIContentSummary
 * @author Rockytkg, Andrew8102
 * @version 2.1.0
 * @link https://github.com/Rockytkg/AIContentSummary
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
}

$GLOBALS['__aics_done'] = false;

/**
 * 输出 JSON 并结束
 *
 * @param array $data
 * @param int   $status
 * @return void
 */
function aics_regen_respond(array $data, $status = 200)
{
    $GLOBALS['__aics_done'] = true;
    $data['httpStatus'] = (int) $status;

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

register_shutdown_function(function () {
    if (!empty($GLOBALS['__aics_done'])) {
        return;
    }

    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }

    aics_regen_respond(array(
        'ok' => false,
        'msg' => '接口内部错误：' . $err['message'],
        'stage' => 'bootstrap',
        'line' => $err['line']
    ), 500);
});

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
    aics_regen_respond(array('ok' => false, 'msg' => '该地址只接受 POST 请求', 'stage' => 'method'), 405);
}

$apiFile = __DIR__ . '/Api.php';
if (!is_file($apiFile)) {
    aics_regen_respond(array('ok' => false, 'msg' => '缺少 Api.php，请把插件的 Api.php 一起上传', 'stage' => 'files'), 500);
}
require_once $apiFile;

// 向上查找 Typecho 根目录
$root = null;
$dir = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $dir = dirname($dir);
    if ($dir === '' || $dir === '/' || $dir === '.') {
        break;
    }
    if (is_file($dir . '/config.inc.php') && is_dir($dir . '/var/Typecho')) {
        $root = $dir;
        break;
    }
}

if ($root === null) {
    aics_regen_respond(array('ok' => false, 'msg' => '找不到 Typecho 根目录（未找到 config.inc.php）', 'stage' => 'bootstrap'), 500);
}

try {
    require_once $root . '/config.inc.php';

    if (!class_exists('\Typecho\Db')) {
        aics_regen_respond(array('ok' => false, 'msg' => 'Typecho 自动加载失败', 'stage' => 'bootstrap'), 500);
    }

    $db = \Typecho\Db::get();

    $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:AIContentSummary'));
    $raw = is_array($row) && isset($row['value']) ? (string) $row['value'] : '';
    if ($raw === '') {
        aics_regen_respond(array('ok' => false, 'msg' => '插件配置为空，请先在后台保存配置', 'stage' => 'config'));
    }

    $cfg = @unserialize($raw, array('allowed_classes' => false));
    if (!is_array($cfg)) {
        $cfg = json_decode($raw, true);
    }
    if (!is_array($cfg)) {
        aics_regen_respond(array('ok' => false, 'msg' => '插件配置无法解析', 'stage' => 'config'));
    }

    // 令牌校验
    $apiKey = trim((string) AIContentSummary_Api::opt($cfg, 'apiKey'));
    if ($apiKey === '') {
        aics_regen_respond(array('ok' => false, 'msg' => 'API KEY 为空，请先在后台保存配置后再使用补生成', 'stage' => 'config'));
    }
    $expect = AIContentSummary_Api::token($apiKey);
    $given = isset($_POST['t']) ? (string) $_POST['t'] : '';
    if (!hash_equals($expect, $given)) {
        aics_regen_respond(array('ok' => false, 'msg' => '校验令牌不匹配，请刷新插件设置页重试', 'stage' => 'token'), 403);
    }

    $cid = isset($_POST['cid']) ? (int) $_POST['cid'] : 0;
    // 摘要字段名（默认 content；上游 2.0 允许自定义，这里跟着配置走）
    $fieldName = AIContentSummary_Api::fieldName($cfg);

    // ---------- 1. 没传 cid：返回缺少摘要的文章列表 ----------
    if ($cid <= 0) {
        // 已有「非空」摘要字段的 cid（字段存在但值为空的，视为还没有摘要，仍要补）
        $hasRows = $db->fetchAll($db->select('cid', 'str_value')->from('table.fields')->where('name = ?', $fieldName));
        $has = array();
        foreach ($hasRows as $r) {
            if (!isset($r['cid'])) {
                continue;
            }

            if (trim((string) $r['str_value']) === '') {
                continue;
            }

            $has[(int) $r['cid']] = true;
        }

        $posts = $db->fetchAll(
            $db->select('cid', 'title', 'created')
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('cid', \Typecho\Db::SORT_DESC)
                ->limit(200)
        );

        $list = array();
        foreach ($posts as $p) {
            $id = (int) $p['cid'];
            if (isset($has[$id])) {
                continue;
            }
            $list[] = array(
                'cid' => $id,
                'title' => (string) $p['title'],
                'created' => (int) $p['created']
            );
        }

        aics_regen_respond(array(
            'ok' => true,
            'stage' => 'list',
            'total' => count($list),
            'list' => array_slice($list, 0, 50)
        ));
    }

    // ---------- 2. 传了 cid：生成并写入 ----------
    $post = $db->fetchRow(
        $db->select('cid', 'title', 'text')
            ->from('table.contents')
            ->where('cid = ?', $cid)
            ->where('type IN ?', array('post', 'post_draft'))
            ->limit(1)
    );

    if (!is_array($post) || empty($post['cid'])) {
        aics_regen_respond(array('ok' => false, 'msg' => '文章不存在：cid=' . $cid, 'stage' => 'content'));
    }

    $title = (string) $post['title'];

    // 生成逻辑统一走 Api::generateSummary()：截断放大重试与 reasoning 兜底都在那里
    $result = AIContentSummary_Api::generateSummary($cfg, $title, isset($post['text']) ? $post['text'] : '', 60);

    if (!$result['ok']) {
        aics_regen_respond(array(
            'ok' => false,
            'msg' => $result['msg'],
            'stage' => 'api',
            'detail' => AIContentSummary_Api::cut($result['detail'], 400),
            'cost' => $result['cost'] . 'ms'
        ));
    }

    $summary = $result['summary'];
    $cost = (int) $result['cost'];

    $exists = $db->fetchRow(
        $db->select('cid')->from('table.fields')->where('cid = ?', $cid)->where('name = ?', $fieldName)
    );

    if ($exists) {
        $db->query(
            $db->update('table.fields')
                ->rows(array('str_value' => $summary, 'type' => 'str'))
                ->where('cid = ?', $cid)
                ->where('name = ?', $fieldName)
        );
    } else {
        $db->query(
            $db->insert('table.fields')->rows(array(
                'cid' => $cid,
                'name' => $fieldName,
                'type' => 'str',
                'str_value' => $summary,
                'int_value' => 0,
                'float_value' => 0
            ))
        );
    }

    aics_regen_respond(array(
        'ok' => true,
        'stage' => 'ok',
        'cid' => $cid,
        'title' => $title,
        'summary' => $summary,
        'cost' => $cost . 'ms'
    ));
} catch (\Throwable $e) {
    aics_regen_respond(array(
        'ok' => false,
        'msg' => '服务端异常：' . $e->getMessage(),
        'stage' => 'exception',
        'line' => $e->getLine()
    ), 500);
}
