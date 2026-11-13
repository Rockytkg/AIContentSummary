<?php
/**
 * AIContentSummary 连通性测试接口（独立文件，不经过 Typecho 路由）
 *
 * 直接访问 https://你的域名/usr/plugins/AIContentSummary/test.php 即可，
 * 只接受 POST，始终返回 JSON。
 *
 * 之所以做成独立文件：Typecho 的路由表存在数据库里，插件升级后
 * 旧路由（指向插件类而非 Widget）会一直留在 routingTable 里，
 * 导致 /aicontentsummary/test 直接 500。独立文件不依赖路由表、
 * 不依赖插件是否重新启用，上传即可用。
 *
 * 版权与来源：基于 Rockytkg/AIContentSummary 修改，原项目版权归 Rockytkg 所有；
 * 修改者 Andrew8102，Apache-2.0。
 *
 * @package AIContentSummary
 * @author Rockytkg, Andrew8102
 * @version 2.1.0
 * @link https://github.com/Rockytkg/AIContentSummary
 */

// 先关掉错误显示，避免 PHP 警告混进 JSON（错误仍会写进 error_log）
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
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
function aics_respond(array $data, $status = 200)
{
    $GLOBALS['__aics_done'] = true;

    // 统一 HTTP 200：nginx 的 error_page 会把 4xx/5xx 的 body 换成 HTML 错误页，
    // 那样前端又拿不到 JSON 了。真正的成功/失败看 JSON 里的 ok 字段。
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

// 致命错误兜底：无论如何都返回 JSON，而不是 HTML 错误页
register_shutdown_function(function () {
    if (!empty($GLOBALS['__aics_done'])) {
        return;
    }

    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }

    aics_respond(array(
        'ok' => false,
        'msg' => '接口内部错误：' . $err['message'],
        'stage' => 'bootstrap',
        'file' => basename($err['file']),
        'line' => $err['line']
    ), 500);
});

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
    aics_respond(array(
        'ok' => false,
        'msg' => '该地址只接受 POST 请求（用于后台点「测试 API 连通性」时调用）',
        'stage' => 'method'
    ), 405);
}

$apiFile = __DIR__ . '/Api.php';
if (!is_file($apiFile)) {
    aics_respond(array('ok' => false, 'msg' => '缺少 Api.php，请把插件的 Api.php 一起上传', 'stage' => 'files'), 500);
}
require_once $apiFile;

// 向上查找 Typecho 根目录（兼容 usr 目录被改名、插件目录被软链的情况）
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
    aics_respond(array(
        'ok' => false,
        'msg' => '找不到 Typecho 根目录（未找到 config.inc.php）。请确认 test.php 位于 usr/plugins/AIContentSummary/ 下',
        'stage' => 'bootstrap'
    ), 500);
}

try {
    // Typecho 的 config.inc.php 自己会定义 __TYPECHO_ROOT_DIR__ 并注册自动加载
    require_once $root . '/config.inc.php';

    if (!class_exists('\Typecho\Db')) {
        aics_respond(array('ok' => false, 'msg' => 'Typecho 自动加载失败（\Typecho\Db 不存在）', 'stage' => 'bootstrap'), 500);
    }

    $db = \Typecho\Db::get();
    $row = $db->fetchRow(
        $db->select('value')->from('table.options')->where('name = ?', 'plugin:AIContentSummary')
    );

    $raw = is_array($row) && isset($row['value']) ? (string) $row['value'] : '';
    if ($raw === '') {
        aics_respond(array(
            'ok' => false,
            'msg' => '数据库里没有 AIContentSummary 的配置：请先在后台「设置 → 插件 → AIContentSummary」填好并点「保存设置」',
            'stage' => 'config'
        ));
    }

    $cfg = @unserialize($raw, array('allowed_classes' => false));
    if (!is_array($cfg)) {
        $cfg = json_decode($raw, true);
    }
    if (!is_array($cfg)) {
        aics_respond(array('ok' => false, 'msg' => '插件配置无法解析（不是合法的序列化数据）', 'stage' => 'config'));
    }

    // 令牌校验：由 API KEY 派生，外部猜不到，避免接口被陌生请求拿去刷额度
    $apiKey = trim((string) AIContentSummary_Api::opt($cfg, 'apiKey'));
    if ($apiKey !== '') {
        $expect = AIContentSummary_Api::token($apiKey);
        $given = isset($_POST['t']) ? (string) $_POST['t'] : '';
        if (!hash_equals($expect, $given)) {
            aics_respond(array(
                'ok' => false,
                'msg' => '校验令牌不匹配：请重新打开插件设置页再点测试（页面刷新后会带上新令牌）',
                'stage' => 'token'
            ), 403);
        }
    }

    $out = AIContentSummary_Api::runTest($cfg);
    $out['stage'] = isset($out['stage']) ? $out['stage'] : 'ok';
    $out['via'] = 'test.php';
    $out['php'] = PHP_VERSION;

    aics_respond($out);
} catch (\Throwable $e) {
    aics_respond(array(
        'ok' => false,
        'msg' => '服务端异常：' . $e->getMessage(),
        'stage' => 'exception',
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ), 500);
}
