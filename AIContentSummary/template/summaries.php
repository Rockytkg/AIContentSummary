<?php

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
$fieldName = $settings->fieldName;
$isAllPosts = $request->get('__typecho_all_posts') === 'on' || Cookie::get('__typecho_all_posts') === 'on';
$panelBase = 'extending.php?panel=AIContentSummary/template/summaries.php';
$actionBase = $security->getIndex('/action/summaries');

$statusTabs = [
    ['value' => 'all', 'label' => _t('可用')],
    ['value' => 'waiting', 'label' => _t('待审核')],
    ['value' => 'draft', 'label' => _t('草稿')],
];

$currentStatus = (string) $request->get('status', 'all');
$currentStatus = in_array($currentStatus, ['all', 'waiting', 'draft'], true) ? $currentStatus : 'all';

// 统一组装当前面板链接，避免切换筛选条件时手写 query。
$buildPanelUrl = static function (array $query = []) use ($options, $panelBase): string {
    $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    $suffix = $query ? '&' . http_build_query($query) : '';

    return $options->adminUrl($panelBase . $suffix, true);
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
                                <a href="<?php echo htmlspecialchars($buildPanelUrl([
                                    'status' => $statusValue,
                                    'uid' => isset($request->uid) ? $request->filter('encode')->uid : null,
                                ]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?></a>
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
                            <a href="<?php echo htmlspecialchars($buildPanelUrl([
                                'status' => $currentStatus === 'all' ? null : $currentStatus,
                                'uid' => isset($request->uid) ? $request->filter('encode')->uid : null,
                            ]), ENT_QUOTES, 'UTF-8'); ?>"><?php _e('&laquo; 取消筛选'); ?></a>
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
                        <input type="hidden" name="panel" value="AIContentSummary/template/summaries.php"/>
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
                                $summary = trim((string) ($posts->fields->{$fieldName} ?? ''));
                                $title = (string) $posts->title;
                                $summaryLength = Common::strLen($summary);
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
                                            <div class="summary-meta"><?php _e('字数'); ?>：<span class="summary-length"><?php echo $summaryLength; ?></span></div>
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
        var actionUrl = <?php echo json_encode($actionBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var emptyText = <?php echo json_encode(_t('暂无摘要，点击此处编辑'), JSON_UNESCAPED_UNICODE); ?>;
        var generatingText = <?php echo json_encode(_t('生成中...'), JSON_UNESCAPED_UNICODE); ?>;
        var generateText = <?php echo json_encode(_t('生成摘要'), JSON_UNESCAPED_UNICODE); ?>;
        var requestFailText = <?php echo json_encode(_t('请求失败'), JSON_UNESCAPED_UNICODE); ?>;
        var currentNotice = null;
        var actionJoiner = actionUrl.indexOf('?') >= 0 ? '&' : '?';
        var $table = $('.typecho-list-table');
        var $batchButton = $('.js-generate-summary-batch');
        var batchRunning = false;

        // 只复用 Typecho 的选择能力，不接管动作链接，避免干扰当前页的自定义按钮事件。
        $('.typecho-list-table').tableSelectable({
            checkEl: 'input[name="cid[]"]',
            rowEl: 'tbody tr[data-cid]',
            selectAllEl: '.typecho-table-select-all'
        });

        $('.btn-drop').dropdownMenu({
            btnEl: '.dropdown-toggle',
            menuEl: '.dropdown-menu'
        });

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

            // data-summary 保存“真实值”，取消编辑时不依赖展示文案反推内容。
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
                url: actionUrl + actionJoiner + 'do=' + encodeURIComponent(action),
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
            }, '摘要已清空');
        });

        $table.find('.js-save-summary').on('click', function () {
            var $row = $(this).closest('tr[data-cid]');

            runAction('save', $row, {
                cid: $row.data('cid'),
                summary: $row.find('textarea').val()
            }, '摘要保存成功');
        });

        $table.find('.js-generate-summary').on('click', function (event) {
            var $row = $(this).closest('tr[data-cid]');

            event.preventDefault();
            runAction('generate', $row, {cid: $row.data('cid')}, '摘要生成成功');
        });

        // 点击空白区域时关闭其它编辑器，保持单行编辑体验。
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
                notice('批量生成完成');
                return;
            }

            $row = $rows.eq(index);
            toggleBusy($row, true);

            // 批量生成按顺序串行执行，减少接口并发带来的限流和提示混乱。
            request('generate', {cid: $row.data('cid')}).done(function (response) {
                if (response && response.success) {
                    syncRow($row, response.data || {});
                    return;
                }

                notice('文章 ' + $row.data('cid') + ' 生成失败：' + (response && response.message ? response.message : requestFailText), 'error');
            }).fail(function (xhr) {
                notice('文章 ' + $row.data('cid') + ' 生成失败：' + failMessage(xhr), 'error');
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
                notice('请至少选择一篇文章', 'notice');
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
