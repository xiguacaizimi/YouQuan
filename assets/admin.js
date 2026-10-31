/*!
 * 有圈 YouQuan - 后台交互
 * 负责弹窗、Ajax 抓取进度、友链导入与自动发现。无第三方依赖。
 */
(function () {
    'use strict';

    var cfg = window.YQ_ADMIN || {};
    var endpoint = cfg.endpoint || '';
    var toastTimer = null;

    /* ---------------- 基础 ---------------- */

    function toast(message, type) {
        var el = document.getElementById('yq-toast');
        if (!el) return;
        el.textContent = message;
        el.className = 'yq-toast' + (type ? ' is-' + type : '');
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            el.hidden = true;
        }, 3800);
    }

    function ajax(action, data) {
        var form = new FormData();
        form.append('_', cfg.token || '');
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    form.append(key + '[]', item);
                });
            } else {
                form.append(key, value);
            }
        });

        return fetch(endpoint + '&yq_ajax=1&action=' + encodeURIComponent(action), {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function (response) {
            return response.json();
        }).catch(function () {
            return { ok: false, message: '网络请求失败，请重试' };
        });
    }

    /* ---------------- 弹窗 ---------------- */

    function openModal(id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = false;
        var first = modal.querySelector('input:not([type=hidden]), select, textarea');
        if (first) {
            setTimeout(function () {
                first.focus();
            }, 60);
        }
    }

    function closeModal(modal) {
        if (modal) modal.hidden = true;
    }

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-open-modal]');
        if (opener) {
            openModal(opener.getAttribute('data-open-modal'));
            return;
        }
        var closer = event.target.closest('[data-close-modal]');
        if (closer) {
            closeModal(closer.closest('.yq-modal'));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.yq-modal:not([hidden])').forEach(function (modal) {
            modal.hidden = true;
        });
    });

    /* ---------------- 全选 ---------------- */

    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            var group = master.getAttribute('data-check-all');
            document.querySelectorAll('[data-check="' + group + '"]').forEach(function (box) {
                box.checked = master.checked;
            });
        });
    });

    var importAll = document.getElementById('yq-import-all');
    if (importAll) {
        importAll.addEventListener('change', function () {
            document.querySelectorAll('#import-modal input[name="ids[]"]:not(:disabled)').forEach(function (box) {
                box.checked = importAll.checked;
            });
        });
    }

    /* ---------------- 单个抓取 ---------------- */

    function applyFeedResult(fid, result) {
        var badge = document.querySelector('[data-feed-badge="' + fid + '"]');
        if (badge && result.badge) badge.innerHTML = result.badge;

        var time = document.querySelector('[data-feed-time="' + fid + '"]');
        if (time && result.time) time.textContent = result.time;

        var count = document.querySelector('[data-feed-count="' + fid + '"]');
        if (count && typeof result.count !== 'undefined') count.textContent = result.count;
    }

    function fetchFeed(fid) {
        var button = document.querySelector('[data-fetch="' + fid + '"]');
        var original = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = '抓取中';
        }
        return ajax('fetch', { fid: fid }).then(function (result) {
            if (button) {
                button.disabled = false;
                button.textContent = original;
            }
            applyFeedResult(fid, result || {});
            return result;
        });
    }

    document.querySelectorAll('[data-fetch]').forEach(function (button) {
        button.addEventListener('click', function () {
            var fid = button.getAttribute('data-fetch');
            fetchFeed(fid).then(function (result) {
                toast(result.message || '抓取完成', result.ok ? '' : 'error');
            });
        });
    });

    /* ---------------- 全部抓取 ---------------- */

    function fetchAllFeeds(trigger) {
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-feed-row]')).filter(function (row) {
            return row.getAttribute('data-feed-status') !== '0';
        });
        if (!rows.length) {
            toast('没有可抓取的圈子', 'warn');
            return;
        }

        var label = trigger ? trigger.textContent : '';
        var index = 0;
        var added = 0;
        if (trigger) trigger.disabled = true;

        function next() {
            if (index >= rows.length) {
                if (trigger) {
                    trigger.disabled = false;
                    trigger.textContent = label;
                }
                toast('抓取完成：' + rows.length + ' 个圈子，新增 ' + added + ' 篇文章', '');
                setTimeout(function () {
                    window.location.reload();
                }, 900);
                return;
            }
            var row = rows[index];
            var fid = row.getAttribute('data-feed-row');
            if (trigger) trigger.textContent = '抓取中 ' + (index + 1) + '/' + rows.length;
            ajax('fetch', { fid: fid }).then(function (result) {
                applyFeedResult(fid, result || {});
                if (result && result.ok) added += result.added || 0;
                index++;
                next();
            });
        }

        next();
    }

    ['yq-fetch-all', 'yq-fetch-all-2'].forEach(function (id) {
        var button = document.getElementById(id);
        if (!button) return;
        button.addEventListener('click', function () {
            fetchAllFeeds(button);
        });
    });

    /* ---------------- 从友链导入 ---------------- */

    var importSubmit = document.getElementById('yq-import-submit');
    if (importSubmit) {
        importSubmit.addEventListener('click', function () {
            var boxes = document.querySelectorAll('#import-modal input[name="ids[]"]:checked');
            var ids = Array.prototype.map.call(boxes, function (box) {
                return box.value;
            });
            if (!ids.length) {
                toast('请先勾选要导入的友链', 'warn');
                return;
            }

            var progress = document.getElementById('yq-import-progress');
            importSubmit.disabled = true;
            if (progress) progress.textContent = '正在导入…';

            ajax('import', { ids: ids }).then(function (result) {
                if (!result.ok) {
                    toast(result.message || '导入失败', 'error');
                    importSubmit.disabled = false;
                    if (progress) progress.textContent = '';
                    return;
                }

                if (progress) progress.textContent = result.message;

                var fids = result.fids || [];
                var index = 0;

                function next() {
                    if (index >= fids.length) {
                        toast(result.message, result.failed && result.failed.length ? 'warn' : '');
                        setTimeout(function () {
                            window.location.href = cfg.feedsTab;
                        }, 700);
                        return;
                    }
                    if (progress) progress.textContent = '抓取文章 ' + (index + 1) + '/' + fids.length;
                    ajax('fetch', { fid: fids[index] }).then(function () {
                        index++;
                        next();
                    });
                }

                next();
            });
        });
    }

    /* ---------------- 自动发现 RSS ---------------- */

    var discover = document.getElementById('yq-discover');
    if (discover) {
        discover.addEventListener('click', function () {
            var siteInput = document.getElementById('yq-site-url');
            var feedInput = document.getElementById('yq-feed-url');
            var tip = document.getElementById('yq-discover-tip');
            var site = siteInput ? siteInput.value.trim() : '';
            if (!site) {
                toast('请先填写站点地址', 'warn');
                return;
            }

            var label = discover.textContent;
            discover.disabled = true;
            discover.textContent = '查找中';
            if (tip) tip.textContent = '正在读取站点页面里的 RSS 声明…';

            ajax('discover', { site_url: site }).then(function (result) {
                discover.disabled = false;
                discover.textContent = label;
                if (result.ok && result.feeds && result.feeds.length) {
                    feedInput.value = result.feeds[0];
                    if (tip) tip.textContent = '已找到：' + result.feeds[0];
                    toast('已填入 RSS 地址');
                } else {
                    if (tip) tip.textContent = '没有自动发现 RSS，请手动填写。';
                    toast(result.message || '没有找到 RSS', 'warn');
                }
            });
        });
    }

    /* ---------------- 创建页面 ---------------- */

    var createAjax = document.getElementById('yq-create-page-ajax');
    if (createAjax) {
        createAjax.addEventListener('click', function () {
            var form = document.getElementById('yq-create-page-form');
            var title = form.querySelector('[name="page_title"]').value.trim() || '博友圈';
            var slug = form.querySelector('[name="page_slug"]').value.trim() || 'youquan';
            var label = createAjax.textContent;

            createAjax.disabled = true;
            createAjax.textContent = '创建中…';

            ajax('create_page', { page_title: title, page_slug: slug }).then(function (result) {
                createAjax.disabled = false;
                createAjax.textContent = label;
                if (result.ok) {
                    toast('页面已创建：' + result.url);
                    setTimeout(function () {
                        window.location.href = cfg.settingsTab;
                    }, 800);
                } else {
                    toast(result.message || '创建失败', 'error');
                }
            });
        });
    }

    /* ---------------- 初始化提示 ---------------- */

    if (cfg.flash && cfg.flash.m) {
        toast(cfg.flash.m, cfg.flash.t === 'error' ? 'error' : (cfg.flash.t === 'warn' ? 'warn' : ''));
    }

    if (cfg.autoOpen) {
        openModal('feed-modal');
    }
})();
