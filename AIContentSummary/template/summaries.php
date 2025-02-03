<?php
// 引入必要的文件
require 'header.php';
require 'menu.php';

// 初始化组件
$stat = \Widget\Stat::alloc();
$posts = \Widget\Contents\Post\Admin::alloc();
$isAllPosts = ($request->get('__typecho_all_posts') === 'on' || \Typecho\Cookie::get('__typecho_all_posts') === 'on');
$fieldName = Widget\Options::alloc()->plugin('AIContentSummary')->fieldName;
// 主页面结构
?>

<style>
    /* 编辑框样式 */
    .summary-edit {
        width: 100%;
        height: 60px;
        padding: 5px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 14px;
        box-sizing: border-box;
    }

    .disabled-row input[type="checkbox"] {
        pointer-events: none;
    }

    .disabled-row {
        opacity: 0.6;
        pointer-events: none;
        transition: opacity 0.3s ease;
        background-color: #f9f9f9;
    }

    [class^="summary-"] {
        transition: display 0.2s ease;
    }
</style>

<div class="main">
    <div class="body container">
        <?php require 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <div class="clearfix">
                    <?php if ($user->pass('editor', true) && !isset($request->uid)): ?>
                        <ul class="typecho-option-tabs right">
                            <li class="<?php echo $isAllPosts ? 'current' : ''; ?>">
                                <a href="<?php echo $request->makeUriByRequest('__typecho_all_posts=on&page=1'); ?>">所有</a>
                            </li>
                            <li class="<?php echo !$isAllPosts ? 'current' : ''; ?>">
                                <a href="<?php echo $request->makeUriByRequest('__typecho_all_posts=off&page=1'); ?>">我的</a>
                            </li>
                        </ul>
                    <?php endif; ?>
                    <ul class="typecho-option-tabs">
                        <?php
                        $statusTabs = [
                            ['status' => 'all', 'label' => '可用'],
                            ['status' => 'waiting', 'label' => '待审核'],
                            ['status' => 'draft', 'label' => '草稿']
                        ];
                        foreach ($statusTabs as $tab) {
                            $currentStatus = $request->get('status');
                            $isCurrent = (!isset($currentStatus) && $tab['status'] === 'all') || $currentStatus === $tab['status'];
                            ?>
                            <li class="<?php echo $isCurrent ? 'current' : ''; ?>">
                                <a href="<?php
                                echo $options->adminUrl('extending.php?panel=AIContentSummary/template/summaries.php&status=' . $tab['status']
                                    . (isset($request->uid) ? '&uid=' . $request->filter('encode')->uid : ''));
                                ?>"><?php echo $tab['label']; ?></a>
                            </li>
                        <?php } ?>
                    </ul>
                </div>

                <div class="typecho-list-operate clearfix">
                    <form method="get">
                        <div class="operate">
                            <label><input type="checkbox" class="typecho-table-select-all"/></label>
                            <div class="btn-group btn-drop">
                                <button class="btn dropdown-toggle btn-s" type="button">选中项 <i
                                        class="i-caret-down"></i></button>
                                <ul class="dropdown-menu">
                                    <?php if ($user->pass('editor', true)): ?>
                                        <li>
                                            <a href="javascript:void(0);" id="generate-summary-batch"
                                               lang="你确认为这些文章生成摘要吗?">生成摘要</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="search" role="search">
                            <?php if ($request->keywords || $request->category): ?>
                                <a href="<?php
                                $url = $options->adminUrl('extending.php?panel=AIContentSummary/template/summaries.php');
                                $queryParams = [];
                                if ($request->status) {
                                    $queryParams[] = 'status=' . $request->filter('encode')->status;
                                }
                                if (isset($request->uid)) {
                                    $queryParams[] = 'uid=' . $request->filter('encode')->uid;
                                }
                                $url . ($queryParams ? '&' . implode('&', $queryParams) : '');
                                ?>">&laquo; 取消筛选</a>
                            <?php endif; ?>
                            <input type="text" class="text-s" placeholder="请输入关键字"
                                   value="<?php echo $request->filter('html')->keywords; ?>" name="keywords"/>
                            <select name="category">
                                <option value="">所有分类</option>
                                <?php \Widget\Metas\Category\Rows::alloc()->to($category); ?>
                                <?php while ($category->next()): ?>
                                    <option
                                        value="<?php echo $category->mid(); ?>" <?php echo ($request->get('category') == $category->mid) ? 'selected' : ''; ?>><?php echo $category->name(); ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button type="submit" class="btn btn-s">筛选</button>
                            <input type="hidden" name="panel" value="AIContentSummary/template/summaries.php"/>
                            <?php if (isset($request->uid)): ?>
                                <input type="hidden" name="uid" value="<?php echo $request->filter('html')->uid; ?>"/>
                            <?php endif; ?>
                            <?php if ($request->status): ?>
                                <input type="hidden" name="status"
                                       value="<?php echo $request->filter('html')->status; ?>"/>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="20" class="kit-hidden-mb"/>
                            <col width="25%"/>
                            <col width="" class="kit-hidden-mb"/>
                            <col width="12%" class="kit-hidden-mb"/>
                            <col width="12%" class="kit-hidden-mb"/>
                            <col width="12%"/>
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="kit-hidden-mb"></th>
                            <th>标题</th>
                            <th class="kit-hidden-mb">摘要</th>
                            <th class="kit-hidden-mb">摘要字数</th>
                            <th class="kit-hidden-mb">作者</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($posts->have()): ?>
                            <?php while ($posts->next()): ?>
                                <tr id="post-<?php $posts->theId(); ?>">
                                    <td class="kit-hidden-mb"><input type="checkbox" value="<?php $posts->cid(); ?>"
                                                                     name="cid[]"/></td>
                                    <td>
                                        <a href="<?php $options->adminUrl('write-post.php?cid=' . $posts->cid); ?>"><?php $posts->title(); ?></a>
                                        <a href="<?php $options->adminUrl('write-post.php?cid=' . $posts->cid); ?>"
                                           title="编辑 <?php echo htmlspecialchars($posts->title); ?>"><i
                                                class="i-edit"></i></a>
                                    </td>
                                    <td class="kit-hidden-mb summary-cell">
                                            <span
                                                class="summary-text"><?php echo !empty($posts->fields->{$fieldName}) ? $posts->fields->{$fieldName} : '暂无摘要'; ?></span>
                                        <textarea class="summary-edit" style="display:none;"
                                                  placeholder="请输入摘要..."><?php echo $posts->fields->{$fieldName} ?? ''; ?></textarea>
                                        <div class="summary-buttons" style="display:none;">
                                            <button type="button" class="btn btn-primary save-summary">保存</button>
                                            <button type="button" class="btn btn-cancel cancel-summary">取消
                                            </button>
                                        </div>
                                    </td>
                                    <td class="kit-hidden-mb">
                                        <span><?php echo mb_strlen($posts->fields->{$fieldName} ?? ''); ?></span>
                                    </td>
                                    <td class="kit-hidden-mb"><a
                                            href="<?php $options->adminUrl('extending.php?panel=AIContentSummary/template/summaries.php&__typecho_all_posts=off&uid=' . $posts->author->uid); ?>"><?php $posts->author(); ?></a>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-generate generate-summary"
                                                data-cid="<?php $posts->cid(); ?>">生成摘要
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5"><h6 class="typecho-list-table-title">没有任何文章</h6></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="typecho-list-operate clearfix">
                    <?php if ($posts->have()): ?>
                        <ul class="typecho-pager">
                            <?php $posts->pageNav(); ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Typecho 通知工具函数（纯前端版）
        const showTypechoNotice = (() => {
            let currentNotice = null; // 当前正在显示的通知
            let isShowingNotice = false; // 标记当前是否有通知正在显示

            return (message, type = 'success') => {
                const head = document.querySelector('.typecho-head-nav');
                const messageHtml = `
            <div class="message popup ${type}">
                <ul>
                    <li>${message}</li>
                </ul>
            </div>
        `;

                // 创建通知元素
                const notice = $(messageHtml);
                let offset = 0;

                // 插入到 DOM
                if (head) {
                    $(head).after(notice);
                    offset = head.offsetHeight;
                } else {
                    $('body').prepend(notice);
                }

                // 滚动处理
                const checkScroll = () => {
                    const scrollTop = $(window).scrollTop();
                    notice.css({
                        'position': scrollTop >= offset ? 'fixed' : 'absolute',
                        'top': scrollTop >= offset ? 0 : offset
                    });
                };

                // 显示动画
                const showNotice = () => {
                    notice.slideDown(() => {
                        let highlightColor = '#C6D880'; // success
                        if (type === 'error') highlightColor = '#FBC2C4';
                        if (type === 'notice') highlightColor = '#FFD324';

                        notice.effect('highlight', {color: highlightColor}, 500, () => {
                            notice.delay(3000).fadeOut(() => {
                                notice.remove();
                                isShowingNotice = false; // 当前通知消失
                                currentNotice = null;
                            });
                        });
                    });

                    // 绑定滚动事件
                    $(window).scroll(checkScroll);
                    checkScroll();
                };

                // 如果有通知正在显示，立即触发当前通知的消失动画
                if (isShowingNotice && currentNotice) {
                    currentNotice.stop(true, true).fadeOut(300, () => {
                        currentNotice.remove();
                        isShowingNotice = false;
                        currentNotice = null;

                        // 显示新的通知
                        isShowingNotice = true;
                        currentNotice = notice;
                        showNotice();
                    });
                } else {
                    // 如果没有通知正在显示，直接显示新通知
                    isShowingNotice = true;
                    currentNotice = notice;
                    showNotice();
                }
            };
        })();

        // 通用工具函数
        const toggleElements = (elements, displayStates) =>
            elements.forEach((el, i) => el.style.display = displayStates[i]);

        const handleFetch = async (url, body) => {
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(body)
                });
                return await res.json();
            } catch (e) {
                console.error('请求失败，请检查网络连接！');
                return {success: false};
            }
        };

        // 摘要编辑功能
        const setupSummaryEditing = () => {
            document.querySelectorAll('.summary-cell').forEach(cell => {
                const [
                    summaryText,
                    summaryEdit,
                    summaryButtons,
                    saveButton,
                    cancelButton
                ] = ['summary-text', 'summary-edit', 'summary-buttons', 'save-summary', 'cancel-summary']
                    .map(cls => cell.querySelector(`.${cls}`));

                const toggleEditMode = (showEdit) => {
                    const displayStates = showEdit
                        ? ['none', 'block', 'block']
                        : ['block', 'none', 'none'];
                    toggleElements([summaryText, summaryEdit, summaryButtons], displayStates);
                    summaryEdit.value = summaryText.textContent === '暂无摘要' ? '' : summaryText.textContent;
                };

                // 将点击事件监听器添加到整个单元格
                cell.addEventListener('click', (event) => {
                    event.stopPropagation();
                    // 如果点击的是按钮区域，则不触发编辑模式
                    if (!event.target.closest('.summary-buttons')) {
                        toggleEditMode(true);
                    }
                });

                saveButton.addEventListener('click', async () => {
                    const cid = cell.closest('tr').id.split('-').pop();
                    try {
                        const data = await handleFetch(
                            '<?php echo \Utils\Helper::options()->index . "/action/summaries?do=save"; ?>',
                            {cid, summary: summaryEdit.value}
                        );

                        if (data.success) {
                            showTypechoNotice('摘要保存成功！');
                            summaryText.textContent = summaryEdit.value || '暂无摘要';
                            const row = cell.closest('tr');
                            const lengthSpan = row.querySelector('td:nth-child(4) span');
                            lengthSpan.textContent = summaryEdit.value.length;
                            toggleEditMode(false); // 保存成功后隐藏编辑框
                        } else {
                            showTypechoNotice(data.message, 'error');
                        }
                    } catch {
                        showTypechoNotice('摘要保存失败！', 'error');
                    }
                });

                cancelButton.addEventListener('click', (event) => {
                    event.stopPropagation(); // 阻止事件冒泡
                    toggleEditMode(false); // 取消编辑时隐藏编辑框
                });

                // 点击页面其他区域时隐藏编辑框
                document.addEventListener('click', (event) => {
                    if (!cell.contains(event.target)) {
                        toggleEditMode(false);
                    }
                });
            });
        };

        // 单个生成功能
        const setupGenerateButtons = () => {
            document.querySelectorAll('.generate-summary').forEach(button => {
                button.addEventListener('click', async () => {
                    const cid = button.dataset.cid;
                    const row = button.closest('tr');
                    const summaryCell = row.querySelector('.summary-text');

                    // 更新UI状态
                    const toggleLoading = (isLoading) => {
                        row.classList.toggle('disabled-row', isLoading);
                        button.textContent = isLoading ? '生成中...' : '生成摘要';
                        button.disabled = isLoading;
                    };

                    toggleLoading(true);

                    try {
                        const data = await handleFetch(
                            '<?php echo \Utils\Helper::options()->index . "/action/summaries?do=generate"; ?>',
                            {cid}
                        );

                        if (data.success) {
                            summaryCell.textContent = data.summary || '暂无摘要';
                            const lengthSpan = row.querySelector('td:nth-child(4) span'); // 注意列位置调整
                            lengthSpan.textContent = data.summary ? data.summary.length : 0;
                        } else {
                            showTypechoNotice(data.message || '摘要生成失败，请重试！', 'error');
                        }
                    } finally {
                        toggleLoading(false);
                    }
                });
            });
        };

        // 批量生成功能
        const setupBatchGenerate = () => {
            document.getElementById('generate-summary-batch').addEventListener('click', async () => {
                const checkboxes = document.querySelectorAll('input[name="cid[]"]:checked');

                if (!checkboxes.length) {
                    showTypechoNotice('请至少选择一篇文章！', 'notice');
                    return;
                }

                // 第一阶段：设置所有选中行状态
                const processList = [];
                checkboxes.forEach(checkbox => {
                    const row = checkbox.closest('tr');
                    const button = row.querySelector('.generate-summary');
                    const summaryCell = row.querySelector('.summary-text');

                    // 设置加载状态
                    row.classList.add('disabled-row');
                    button.textContent = '生成中...';
                    button.disabled = true;

                    // 创建处理队列
                    processList.push({
                        checkbox,
                        row,
                        button,
                        summaryCell
                    });
                });

                // 第二阶段：顺序处理请求
                for (const {checkbox, row, button, summaryCell} of processList) {
                    try {
                        const data = await handleFetch(
                            '<?php echo \Utils\Helper::options()->index . "/action/summaries?do=generate"; ?>',
                            {cid: checkbox.value}
                        );

                        if (data.success) {
                            summaryCell.textContent = data.summary || '暂无摘要';
                            const lengthSpan = row.querySelector('td:nth-child(4) span'); // 注意列位置调整
                            lengthSpan.textContent = data.summary ? data.summary.length : 0;
                            showTypechoNotice(`文章 ID ${checkbox.value} 生成成功`);
                        } else {
                            showTypechoNotice(`文章 ID ${checkbox.value} 生成失败`, 'error');
                        }
                    } catch (e) {
                        showTypechoNotice(`文章 ID ${checkbox.value} 请求异常：${e.message}`, 'error');
                    } finally {
                        // 逐条恢复状态
                        row.classList.remove('disabled-row');
                        button.textContent = '生成摘要';
                        button.disabled = false;
                    }
                }
            });
        };

        // 初始化所有功能
        setupSummaryEditing();
        setupGenerateButtons();
        setupBatchGenerate();
    });
</script>

<?php
// 引入页脚文件
require 'copyright.php';
require 'common-js.php';
require 'table-js.php';
require 'footer.php';
?>
