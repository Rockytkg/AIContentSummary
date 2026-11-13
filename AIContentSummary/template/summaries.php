<?php
declare(strict_types=1);

/**
 * AIContentSummary 摘要管理面板：文章摘要的就地编辑 / 清空 / 单篇与批量生成
 */

use Typecho\Common;
use Typecho\Cookie;
use TypechoPlugin\AIContentSummary\Plugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$user->pass('administrator');

include 'header.php';
include 'menu.php';

$posts = \Widget\Contents\Post\Admin::alloc();
$settings = \Widget\Options::alloc()->plugin(Plugin::NAME);
$fieldName = \AIContentSummary_Api::fieldName($settings);

$isAllPosts = $request->get('__typecho_all_posts') === 'on' || Cookie::get('__typecho_all_posts') === 'on';
$panelPath = 'extending.php?panel=' . Plugin::PANEL;
$actionBase = $security->getIndex('/action/' . Plugin::ACTION);

$statusTabs = array(
    array('value' => 'all', 'label' => _t('可用')),
    array('value' => 'waiting', 'label' => _t('待审核')),
    array('value' => 'draft', 'label' => _t('草稿')),
);

$currentStatus = (string) $request->get('status', 'all');
if (!in_array($currentStatus, array('all', 'waiting', 'draft'), true)) {
    $currentStatus = 'all';
}

$buildPanelUrl = static function (array $query = array()) use ($options, $panelPath): string {
    $query = array_filter($query, static function ($value): bool {
        return $value !== null && $value !== '';
    });
    $suffix = $query ? '&' . http_build_query($query) : '';

    return $options->adminUrl($panelPath . $suffix, true);
};
?>

<style>
    .summary-cell {
        min-width: 320px;
    }

    .summary-display {
        white-space: pre-wrap;
        word-break: break-word;
        cursor: pointer;
    }

    .summary-editing .summary-display {
        display: none;
    }

    .summary-editor,
    .summary-actions {
        display: none;
    }

    .summary-editing .summary-editor,
    .summary-editing .summary-actions {
        display: block;
    }

    .summary-editor textarea {
        width: 100%;
        min-height: 96px;
        box-sizing: border-box;
        resize: vertical;
    }

    .summary-actions {
        margin-top: 8px;
    }

    .summary-actions .btn {
        margin-right: 8px;
    }

    .summary-meta {
        margin-top: 8px;
        color: #999;
        font-size: 12px;
    }

    .summary-busy {
        opacity: .65;
    }
</style>

<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <div class="typecho-list-operate">
                    <ul class="typecho-option-tabs">
                        <?php foreach ($statusTabs as $tab): ?>
                            <?php
                            $isCurrent = $currentStatus === $tab['value'];
                            $statusValue = $tab['value'] === 'all' ? null : $tab['value'];
                            ?>
                            <li<?php if ($isCurrent): ?> class="current"<?php endif; ?>>
                                <a href="<?php echo htmlspecialchars($buildPanelUrl(array(
                                    'status' => $statusValue,
                                    'uid' => isset($request->uid) ? $request->filter('encode')->uid : null,
                                )), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if (!isset($request->uid)): ?>
                        <ul class="typecho-option-tabs">
                            <li<?php if ($isAllPosts): ?> class="current"<?php endif; ?>>
                                <a href="<?php echo htmlspecialchars($request->makeUriByRequest('__typecho_all_posts=on&page=1'), ENT_QUOTES, 'UTF-8'); ?>"><?php _e('所有'); ?></a>
                            </li>
                            <li<?php if (!$isAllPosts): ?> class="current"<?php endif; ?>>
                                <a href="<?php echo htmlspecialchars($request->makeUriByRequest('__typecho_all_posts=off&page=1'), ENT_QUOTES, 'UTF-8'); ?>"><?php _e('我的'); ?></a>
                            </li>
                        </ul>
                    <?php endif; ?>
                </div>

                <form method="get" class="typecho-list-operate">
                    <div class="operate">
                        <label><i class="sr-only"><?php _e('全选'); ?></i><input type="checkbox" class="typecho-table-select-all"/></label>
                        <div class="btn-group btn-drop">
                            <button class="btn dropdown-toggle btn-s" type="button"><?php _e('选中项'); ?> <i class="i-caret-down"></i></button>
                            <ul class="dropdown-menu">
                                <li><a href="#" class="js-generate-summary-batch"><?php _e('生成摘要'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="search" role="search">
                        <?php if ($request->keywords || $request->category): ?>
                            <a href="<?php echo htmlspecialchars($buildPanelUrl(array(
                                'status' => $currentStatus === 'all' ? null : $currentStatus,
                                'uid' => isset($request->uid) ? $request->filter('encode')->uid : null,
                            )), ENT_QUOTES, 'UTF-8'); ?>"><?php _e('&laquo; 取消筛选'); ?></a>
                        <?php endif; ?>
                        <input type="text" class="text-s" placeholder="<?php _e('请输入关键字'); ?>" value="<?php echo htmlspecialchars((string) $request->filter('html')->keywords, ENT_QUOTES, 'UTF-8'); ?>" name="keywords"/>
                        <select name="category">
                            <option value=""><?php _e('所有分类'); ?></option>
                            <?php \Widget\Metas\Category\Rows::alloc()->to($category); ?>
                            <?php while ($category->next()): ?>
                                <option value="<?php $category->mid(); ?>"<?php if ($request->get('category') == $category->mid): ?> selected="true"<?php endif; ?>><?php $category->name(); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <button type="submit" class="btn btn-s"><?php _e('筛选'); ?></button>
                        <input type="hidden" name="panel" value="<?php echo htmlspecialchars(Plugin::PANEL, ENT_QUOTES, 'UTF-8'); ?>"/>
                        <?php if (isset($request->uid)): ?>
                            <input type="hidden" name="uid" value="<?php echo htmlspecialchars((string) $request->filter('html')->uid, ENT_QUOTES, 'UTF-8'); ?>"/>
                        <?php endif; ?>
                        <?php if ($currentStatus !== 'all'): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8'); ?>"/>
                        <?php endif; ?>
                    </div>
                </form>

                <form method="post" class="operate-form">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="3%" class="kit-hidden-mb"/>
                            <col width="28%"/>
                            <col width=""/>
                            <col width="14%"/>
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="kit-hidden-mb"></th>
                            <th><?php _e('标题'); ?></th>
                            <th><?php _e('摘要'); ?></th>
                            <th><?php _e('操作'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($posts->have()): ?>
                            <?php while ($posts->next()): ?>
                                <?php
                                // fields 是魔术属性，不能用 ?? 直接链式取值（__isset 恒为 false）
                                $summary = '';
                                try {
                                    $summary = trim((string) $posts->fields->{$fieldName});
                                } catch (\Throwable $e) {
                                    $summary = '';
                                }
                                $title = (string) $posts->title;
                                $summaryLen = Common::strLen($summary);
                                ?>
                                <tr id="post-<?php $posts->cid(); ?>" data-cid="<?php $posts->cid(); ?>">
                                    <td class="kit-hidden-mb"><input type="checkbox" value="<?php $posts->cid(); ?>" name="cid[]"/></td>
                                    <td>
                                        <a href="<?php $options->adminUrl('write-post.php?cid=' . $posts->cid); ?>"><?php $posts->title(); ?></a>
                                        <?php if ('post_draft' === $posts->type): ?>
                                            <em class="status"><?php _e('草稿'); ?></em>
                                        <?php elseif ($posts->revision): ?>
                                            <em class="status"><?php _e('有修订版'); ?></em>
                                        <?php endif; ?>
                                        <?php if ('waiting' === $posts->status): ?>
                                            <em class="status"><?php _e('待审核'); ?></em>
                                        <?php elseif ('hidden' === $posts->status): ?>
                                            <em class="status"><?php _e('隐藏'); ?></em>
                                        <?php elseif ('private' === $posts->status): ?>
                                            <em class="status"><?php _e('私密'); ?></em>
                                        <?php elseif ($posts->password): ?>
                                            <em class="status"><?php _e('密码保护'); ?></em>
                                        <?php endif; ?>
                                        <a href="<?php $options->adminUrl('write-post.php?cid=' . $posts->cid); ?>" title="<?php _e('编辑 %s', htmlspecialchars($title, ENT_QUOTES, 'UTF-8')); ?>"><i class="i-edit"></i></a>
                                    </td>
                                    <td class="kit-hidden-mb">
                                        <div class="summary-cell" data-summary="<?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="summary-display"><?php echo htmlspecialchars($summary !== '' ? $summary : _t('暂无摘要，点击此处编辑'), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="summary-editor">
                                                <textarea><?php echo htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                            <div class="summary-actions">
                                                <button type="button" class="btn btn-s btn-primary js-save-summary"><?php _e('保存'); ?></button>
                                                <button type="button" class="btn btn-s js-clear-summary"><?php _e('清空'); ?></button>
                                                <button type="button" class="btn btn-s js-cancel-summary"><?php _e('取消'); ?></button>
                                            </div>
                                            <div class="summary-meta"><?php _e('字数'); ?>：<span class="summary-length"><?php echo $summaryLen; ?></span></div>
                                        </div>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-s btn-primary js-generate-summary"><?php _e('生成摘要'); ?></button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="none"><?php _e('没有任何文章'); ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </form>

                <form method="get" class="typecho-list-operate">
                    <?php if ($posts->have()): ?>
                        <ul class="typecho-pager">
                            <?php $posts->pageNav(); ?>
                        </ul>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include 'copyright.php'; ?>
<?php include 'common-js.php'; ?>
<script>
(function ($) {
    $(function () {
        // JSON_HEX_TAG 等标志避免字符串里的 </script> 提前闭合脚本块
        var actionUrl = <?php echo json_encode($actionBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var emptyText = <?php echo json_encode(_t('暂无摘要，点击此处编辑'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var generatingText = <?php echo json_encode(_t('生成中...'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var generateText = <?php echo json_encode(_t('生成摘要'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var generateDoneText = <?php echo json_encode(_t('摘要生成成功'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var generateFailText = <?php echo json_encode(_t('生成失败'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var savedText = <?php echo json_encode(_t('摘要保存成功'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var clearedText = <?php echo json_encode(_t('摘要已清空'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var requestFailText = <?php echo json_encode(_t('请求失败'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var batchDoneText = <?php echo json_encode(_t('批量生成完成'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var batchEmptyText = <?php echo json_encode(_t('请至少选择一篇文章'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var currentNotice = null;
        var actionJoiner = actionUrl.indexOf('?') >= 0 ? '&' : '?';
        var $table = $('.typecho-list-table');
        var $batchButton = $('.js-generate-summary-batch');
        var batchRunning = false;

        // 复用 Typecho 的表格选择能力，不接管动作链接
        $table.tableSelectable({
            checkEl: 'input[name="cid[]"]',
            rowEl: 'tbody tr[data-cid]',
            selectAllEl: '.typecho-table-select-all'
        });

        if ($.fn.dropdownMenu) {
            $('.btn-drop').dropdownMenu({
                btnEl: '.dropdown-toggle',
                menuEl: '.dropdown-menu'
            });
        }

        function notice(message, type) {
            var $notice = $('<div class="message popup ' + (type || 'success') + '" style="display:none;"><ul><li></li></ul></div>');
            var $host = $('.typecho-head-nav');

            $notice.find('li').text(message);
            if (currentNotice) {
                currentNotice.remove();
            }

            if ($host.length) {
                $host.after($notice);
            } else {
                $('body').prepend($notice);
            }

            currentNotice = $notice;
            $notice.slideDown().delay(2400).fadeOut(200, function () {
                $(this).remove();
                if (currentNotice && currentNotice[0] === this) {
                    currentNotice = null;
                }
            });
        }

        function cellOf($row) {
            return $row.find('.summary-cell');
        }

        function summaryOf($cell) {
            return $.trim($cell.attr('data-summary') || '');
        }

        function resetEditor($cell) {
            $cell.find('textarea').val(summaryOf($cell));
        }

        function syncRow($row, data) {
            var summary = $.trim(data && data.summary ? data.summary : '');
            var $cell = cellOf($row).removeClass('summary-editing');

            // data-summary 保存真实值，取消编辑时不依赖展示文案反推内容
            $cell.attr('data-summary', summary);
            $cell.find('.summary-display').text(summary || emptyText);
            resetEditor($cell);
            $cell.find('.summary-length').text(data && data.length != null ? data.length : summary.length);
        }

        function toggleBusy($row, busy) {
            $row.toggleClass('summary-busy', !!busy);
            $row.find('.js-generate-summary, .js-save-summary, .js-clear-summary, .js-cancel-summary').prop('disabled', !!busy);
            $row.find('.js-generate-summary').text(busy ? generatingText : generateText);
        }

        function closeEditors($keep) {
            $table.find('.summary-cell.summary-editing').not($keep).each(function () {
                var $cell = $(this);

                resetEditor($cell);
                $cell.removeClass('summary-editing');
            });
        }

        function request(action, data) {
            return $.ajax({
                url: actionUrl + actionJoiner + 'do=' + encodeURIComponent(action) + '&_t=' + Date.now(),
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        }

        function failMessage(xhr, fallback) {
            var response = xhr.responseJSON || {};

            return response.message || fallback || requestFailText;
        }

        function runAction(action, $row, data, doneMessage) {
            toggleBusy($row, true);

            return request(action, data).done(function (response) {
                if (!response || !response.success) {
                    notice(response && response.message ? response.message : requestFailText, 'error');
                    return;
                }

                syncRow($row, response.data || {});
                if (doneMessage) {
                    notice(response.message || doneMessage);
                }
            }).fail(function (xhr) {
                notice(failMessage(xhr), 'error');
            }).always(function () {
                toggleBusy($row, false);
            });
        }

        $table.find('.summary-cell').on('click', function (event) {
            event.stopPropagation();
        });

        $table.find('.summary-display').on('click', function () {
            var $cell = $(this).closest('.summary-cell');

            closeEditors($cell);
            $cell.addClass('summary-editing').find('textarea').trigger('focus');
        });

        $table.find('.js-cancel-summary').on('click', function () {
            var $cell = $(this).closest('.summary-cell');

            resetEditor($cell);
            $cell.removeClass('summary-editing');
        });

        $table.find('.js-clear-summary').on('click', function () {
            var $row = $(this).closest('tr[data-cid]');

            runAction('save', $row, {
                cid: $row.data('cid'),
                summary: ''
            }, clearedText);
        });

        $table.find('.js-save-summary').on('click', function () {
            var $row = $(this).closest('tr[data-cid]');

            runAction('save', $row, {
                cid: $row.data('cid'),
                summary: $row.find('textarea').val()
            }, savedText);
        });

        $table.find('.js-generate-summary').on('click', function (event) {
            var $row = $(this).closest('tr[data-cid]');

            event.preventDefault();
            runAction('generate', $row, {cid: $row.data('cid')}, generateDoneText);
        });

        // 点击空白区域关闭其它编辑器，保持单行编辑体验
        $(document).on('click', function (event) {
            if (!$(event.target).closest('.summary-cell').length) {
                closeEditors();
            }
        });

        function runBatch($rows, index) {
            var $row;

            if (index >= $rows.length) {
                batchRunning = false;
                $batchButton.removeClass('disabled');
                notice(batchDoneText);
                return;
            }

            $row = $rows.eq(index);
            toggleBusy($row, true);

            // 批量生成按顺序串行执行，降低接口限流风险
            request('generate', {cid: $row.data('cid')}).done(function (response) {
                if (response && response.success) {
                    syncRow($row, response.data || {});
                    return;
                }

                notice('文章 ' + $row.data('cid') + ' ' + generateFailText + '：' + (response && response.message ? response.message : requestFailText), 'error');
            }).fail(function (xhr) {
                notice('文章 ' + $row.data('cid') + ' ' + generateFailText + '：' + failMessage(xhr), 'error');
            }).always(function () {
                toggleBusy($row, false);
                runBatch($rows, index + 1);
            });
        }

        $batchButton.on('click', function (event) {
            var $rows = $table.find('input[name="cid[]"]:checked').closest('tr[data-cid]');

            event.preventDefault();
            if (batchRunning) {
                return;
            }

            if (!$rows.length) {
                notice(batchEmptyText, 'notice');
                return;
            }

            batchRunning = true;
            $batchButton.addClass('disabled');
            runBatch($rows, 0);
        });
    });
})(jQuery);
</script>
<?php include 'footer.php'; ?>
