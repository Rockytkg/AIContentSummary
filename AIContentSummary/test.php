<?php
declare(strict_types=1);

/**
 * AIContentSummary 连通性测试接口。
 * 独立文件不经 Typecho 路由（路由表存库，插件升级后旧路由不会自动清理），上传即可用；
 * 仅接受 POST，始终返回 JSON。
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
}

$GLOBALS['__aics_done'] = false;

/**
 * 输出 JSON 并结束（统一 HTTP 200：成败看 ok 字段，避免 nginx error_page 换掉 4xx/5xx 响应体）
 */
function aics_respond(array $data, int $status = 200): void
{
    $GLOBALS['__aics_done'] = true;
    $data['httpStatus'] = $status;

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

// 致命错误兜底：无论如何返回 JSON 而不是 HTML 错误页
register_shutdown_function(function (): void {
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
        'file' => basename($err['file'] ?? ''),
        'line' => isset($err['line']) ? (int) $err['line'] : 0
    ), 500);
});

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
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
    require_once $root . '/config.inc.php';

    if (!class_exists('\Typecho\Db')) {
        aics_respond(array('ok' => false, 'msg' => 'Typecho 自动加载失败（\Typecho\Db 不存在）', 'stage' => 'bootstrap'), 500);
    }

    $db = \Typecho\Db::get();
    $row = $db->fetchRow(
        $db->select('value')->from('table.options')->where('name = ?', 'plugin:AIContentSummary')
    );

    $raw = is_array($row) ? (string) ($row['value'] ?? '') : '';
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

    // 令牌由 API KEY 派生（sha1 前 24 位），外部无法猜测，避免接口被陌生请求占用额度
    $apiKey = trim(AIContentSummary_Api::opt($cfg, 'apiKey'));
    if ($apiKey === '') {
        aics_respond(array(
            'ok' => false,
            'msg' => 'API KEY 为空，请先在后台保存配置后再测试',
            'stage' => 'config'
        ));
    }

    if (!hash_equals(AIContentSummary_Api::token($apiKey), (string) ($_POST['t'] ?? ''))) {
        aics_respond(array(
            'ok' => false,
            'msg' => '校验令牌不匹配：请重新打开插件设置页再点测试（页面刷新后会带上新令牌）',
            'stage' => 'token'
        ), 403);
    }

    $out = AIContentSummary_Api::runTest($cfg);
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
