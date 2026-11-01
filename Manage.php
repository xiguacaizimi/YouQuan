<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

if (!class_exists('YouQuan_Feed')) {
    require_once dirname(__FILE__) . '/Plugin.php';
}

require_once __TYPECHO_ROOT_DIR__ . '/admin/common.php';

$db = Typecho_Db::get();
$prefix = $db->getPrefix();
$options = Typecho_Widget::widget('Widget_Options');

/* =====================================================================
 * 工具函数
 * ================================================================== */

function yq_h($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function yq_url(array $params = array())
{
    $base = 'extending.php?panel=YouQuan%2FManage.php';
    if ($params) {
        $base .= '&' . http_build_query($params);
    }
    return Typecho_Common::url($base, Typecho_Widget::widget('Widget_Options')->adminUrl);
}

function yq_token()
{
    static $token = null;
    if ($token !== null) return $token;

    try {
        $security = Typecho_Widget::widget('Widget_Security');
        $token = $security->getToken('youquan-panel');
    } catch (Exception $e) {
        $token = md5('youquan-panel' . Helper::options()->siteUrl);
    }
    if (!$token) {
        $token = md5('youquan-panel' . Helper::options()->siteUrl);
    }
    return $token;
}

function yq_verify_token()
{
    $sent = isset($_POST['_']) ? (string) $_POST['_'] : (isset($_GET['_']) ? (string) $_GET['_'] : '');
    return $sent !== '' && hash_equals(yq_token(), $sent);
}

function yq_json($data, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function yq_go(array $params = array())
{
    $url = yq_url($params);
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>location.href=' . json_encode($url) . ';</script>';
    exit;
}

function yq_flash($message, $type = 'success')
{
    Typecho_Cookie::set('__youquan_flash', json_encode(array('m' => $message, 't' => $type)), time() + 120);
}

function yq_take_flash()
{
    $raw = Typecho_Cookie::get('__youquan_flash');
    Typecho_Cookie::delete('__youquan_flash');
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['m']) ? $data : null;
}

function yq_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function yq_post_int($key, $default = 0)
{
    return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
}

/**
 * 复用 FriendLinks 的头像（上传头像 > 邮箱头像服务 > 站点 favicon 缓存）。
 */
function yq_friend_avatar(array $row)
{
    $avatar = trim((string) ($row['avatar'] ?? ''));
    if ($avatar !== '') {
        return preg_match('#^https?://#i', $avatar)
            ? $avatar
            : Typecho_Common::url(ltrim($avatar, '/'), Helper::options()->siteUrl);
    }

    $md5 = trim((string) ($row['email_md5'] ?? ''));
    if ($md5 === '' && !empty($row['email'])) {
        $md5 = md5(strtolower(trim((string) $row['email'])));
    }
    if ($md5 !== '') {
        try {
            $friendOptions = Helper::options()->plugin('FriendLinks');
            $template = trim((string) ($friendOptions->avatarService ?? ''));
            if ($template !== '') {
                return str_replace(array('{md5}', '{size}'), array(rawurlencode($md5), '96'), $template);
            }
        } catch (Exception $e) {
            // FriendLinks 未启用时忽略
        }
    }

    if (class_exists('FriendLinks_Plugin') && method_exists('FriendLinks_Plugin', 'getWebsiteIcon')) {
        $icon = FriendLinks_Plugin::getWebsiteIcon($row['websiteurl'] ?? '', false);
        if ($icon) return $icon;
    }

    return '';
}

/**
 * 从 FriendLinks 导入带 RSS 的友链。
 *
 * @param array $ids friend_links.linkid
 * @return array array($importedFids, $skipped, $failedNames, $messages)
 */
function yq_import_links(array $ids)
{
    $db = Typecho_Db::get();
    $table = $db->getPrefix() . 'friend_links';
    $imported = array();
    $skipped = 0;
    $failed = array();
    $messages = array();

    $rows = array();
    try {
        $rows = $db->fetchAll($db->select()->from($table)) ?: array();
    } catch (Exception $e) {
        return array($imported, $skipped, array(), array('未找到 FriendLinks 友链表，请先启用友链插件'));
    }

    $byId = array();
    foreach ($rows as $row) {
        $byId[(int) $row['linkid']] = $row;
    }

    foreach ($ids as $id) {
        $id = (int) $id;
        if (!isset($byId[$id])) continue;
        $row = $byId[$id];
        $name = trim((string) ($row['SiteTitle'] ?? '')) ?: trim((string) ($row['nickname'] ?? ''));

        if ((int) ($row['rssStatus'] ?? 1) === 0) {
            $skipped++;
            $messages[] = $name . '：友链 RSS 状态为禁用，已跳过';
            continue;
        }

        $feedUrl = YouQuan_Feed::normalizeUrl($row['rssurl'] ?? '');
        if ($feedUrl === '' || !preg_match('#^https?://#i', $feedUrl)) {
            // 友链没填 RSS 时尝试从站点自动发现
            $candidates = YouQuan_Feed::discover($row['websiteurl'] ?? '');
            $feedUrl = $candidates ? $candidates[0] : '';
        }
        if ($feedUrl === '') {
            $failed[] = $name;
            continue;
        }

        $exists = YouQuan_Feed::getFeedByUrl($feedUrl);
        if ($exists) {
            // 已导入则补全头像/名称，避免友链信息更新后不同步
            $patch = array();
            if (trim((string) $exists['avatar']) === '') {
                $avatar = yq_friend_avatar($row);
                if ($avatar !== '') $patch['avatar'] = $avatar;
            }
            if ($patch) {
                YouQuan_Feed::updateFeed($exists['fid'], $patch);
            }
            $skipped++;
            continue;
        }

        $fid = YouQuan_Feed::saveFeed(array(
            'friend_link_id' => $id,
            'name'           => $name !== '' ? $name : YouQuan_Render::host($row['websiteurl'] ?? ''),
            'site_url'       => $row['websiteurl'] ?? '',
            'feed_url'       => $feedUrl,
            'avatar'         => yq_friend_avatar($row),
            'intro'          => $row['personalintro'] ?? '',
            'status'         => 1,
            'sort'           => 0,
        ));

        if ($fid) {
            $imported[] = (int) $fid;
        } else {
            $failed[] = $name;
        }
    }

    return array($imported, $skipped, $failed, $messages);
}

/**
 * 抓取状态角标。
 */
function yq_feed_badge(array $feed)
{
    $status = (string) ($feed['last_status'] ?? 'pending');
    $map = array(
        'ok'      => array('正常', 'ok'),
        'error'   => array('失败', 'error'),
        'pending' => array('待抓取', 'pending'),
    );
    list($label, $class) = $map[$status] ?? array('待抓取', 'pending');
    $title = trim((string) ($feed['last_error'] ?? ''));
    return '<span class="yq-badge is-' . $class . '"' . ($title !== '' ? ' title="' . yq_h($title) . '"' : '') . '>'
        . $label . '</span>';
}

/**
 * 分页条。
 */
function yq_pager($page, $pages, array $params, $label = '')
{
    if ($pages <= 1) return '';
    $html = '<div class="yq-pager">';
    $html .= '<span class="yq-pager-info">' . ($label !== '' ? $label . ' · ' : '') . '第 ' . $page . ' / ' . $pages . ' 页</span>';
    $html .= '<div class="yq-pager-links">';

    $build = function ($target) use ($params) {
        $params['paged'] = $target;
        return yq_url($params);
    };

    if ($page > 1) {
        $html .= '<a class="yq-btn is-small" href="' . yq_h($build($page - 1)) . '">上一页</a>';
    }
    $start = max(1, $page - 2);
    $end = min($pages, $start + 4);
    $start = max(1, $end - 4);
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            $html .= '<span class="yq-btn is-small is-primary">' . $i . '</span>';
        } else {
            $html .= '<a class="yq-btn is-small" href="' . yq_h($build($i)) . '">' . $i . '</a>';
        }
    }
    if ($page < $pages) {
        $html .= '<a class="yq-btn is-small" href="' . yq_h($build($page + 1)) . '">下一页</a>';
    }
    $html .= '</div></div>';
    return $html;
}

/* =====================================================================
 * 初始化数据表
 * ================================================================== */

if (YouQuan_Config::get('db_version', '') !== YouQuan_Plugin::VERSION) {
    YouQuan_Feed::install();
    YouQuan_Config::set('db_version', YouQuan_Plugin::VERSION);
}

/* =====================================================================
 * 请求处理
 * ================================================================== */

$ajax = isset($_GET['yq_ajax']);
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));
$tab = (string) ($_GET['tab'] ?? 'feeds');
if (!in_array($tab, array('feeds', 'posts', 'settings', 'help'), true)) {
    $tab = 'feeds';
}

if ($ajax) {
    if (!yq_verify_token()) {
        yq_json(array('ok' => false, 'message' => '安全校验失败，请刷新页面后重试'), 403);
    }

    switch ($action) {
        case 'fetch':
            $fid = yq_post_int('fid', (int) ($_GET['fid'] ?? 0));
            $result = YouQuan_Feed::fetch($fid);
            $feed = YouQuan_Feed::getFeed($fid);
            $counts = YouQuan_Feed::countsByFeed();
            yq_json(array(
                'ok'      => (bool) $result['ok'],
                'message' => $result['message'],
                'added'   => (int) ($result['added'] ?? 0),
                'count'   => (int) ($counts[$fid] ?? 0),
                'badge'   => $feed ? yq_feed_badge($feed) : '',
                'time'    => $feed ? YouQuan_Render::formatTime((int) $feed['last_fetch']) : '',
            ));
            break;

        case 'import':
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
            if (!$ids) yq_json(array('ok' => false, 'message' => '请先选择要导入的友链'));
            list($imported, $skipped, $failed, $messages) = yq_import_links($ids);
            $text = '导入完成：新增 ' . count($imported) . ' 个圈子';
            if ($skipped) $text .= '，跳过 ' . $skipped . ' 个';
            if ($failed) $text .= '，失败 ' . count($failed) . ' 个';
            yq_json(array(
                'ok'       => true,
                'message'  => $text,
                'fids'     => $imported,
                'skipped'  => $skipped,
                'failed'   => $failed,
                'details'  => $messages,
            ));
            break;

        case 'discover':
            $site = yq_post('site_url', '');
            if ($site === '') yq_json(array('ok' => false, 'message' => '请先填写站点地址'));
            $found = YouQuan_Feed::discover($site);
            if (!$found) yq_json(array('ok' => false, 'message' => '没有找到 RSS，请手动填写订阅地址'));
            yq_json(array('ok' => true, 'feeds' => array_values($found), 'message' => '已找到 ' . count($found) . ' 个候选订阅地址'));
            break;

        case 'create_page':
            $title = yq_post('page_title', '博友圈');
            $slug = YouQuan_Feed::sanitizeSlug(yq_post('page_slug', 'youquan'));
            if ($slug === '') $slug = 'youquan';
            $page = YouQuan_Feed::createPage($title, $slug);
            if (!$page) yq_json(array('ok' => false, 'message' => '页面创建失败，请检查数据库写入权限'));
            yq_json(array(
                'ok'        => true,
                'message'   => '有圈页面已就绪',
                'cid'       => (int) $page['cid'],
                'url'       => YouQuan_Feed::pageUrl($page),
                'shortLink' => YouQuan_Plugin::shortLink(),
            ));
            break;

        default:
            yq_json(array('ok' => false, 'message' => '未知操作'), 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!yq_verify_token()) {
        yq_flash('安全校验失败，请重试', 'error');
        yq_go(array('tab' => $tab));
    }

    switch ($action) {
        case 'save_feed':
            $fid = yq_post_int('fid');
            $feedUrl = YouQuan_Feed::normalizeUrl(yq_post('feed_url'));
            $siteUrl = YouQuan_Feed::normalizeUrl(yq_post('site_url'));
            $name = yq_post('name');

            if ($feedUrl === '' || !preg_match('#^https?://#i', $feedUrl)) {
                yq_flash('请填写有效的 RSS 订阅地址', 'error');
                yq_go(array('tab' => 'feeds', 'edit' => $fid));
            }
            if ($name === '') {
                $name = YouQuan_Render::host($siteUrl !== '' ? $siteUrl : $feedUrl);
            }
            if ($siteUrl === '') {
                $parts = parse_url($feedUrl);
                $siteUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            }

            $duplicate = YouQuan_Feed::getFeedByUrl($feedUrl);
            if ($duplicate && (int) $duplicate['fid'] !== $fid) {
                yq_flash('该 RSS 地址已经添加过了（' . $duplicate['name'] . '）', 'error');
                yq_go(array('tab' => 'feeds', 'edit' => $fid));
            }

            $savedId = YouQuan_Feed::saveFeed(array(
                'name'     => $name,
                'site_url' => $siteUrl,
                'feed_url' => $feedUrl,
                'avatar'   => yq_post('avatar'),
                'intro'    => yq_post('intro'),
                'status'   => yq_post_int('status', 1),
                'sort'     => yq_post_int('sort'),
            ), $fid);

            if (!$savedId) {
                yq_flash('保存失败，请稍后重试', 'error');
                yq_go(array('tab' => 'feeds'));
            }

            if (yq_post_int('fetch_now') === 1) {
                $result = YouQuan_Feed::fetch($savedId);
                yq_flash($fid ? '已保存，' . $result['message'] : '圈子已添加，' . $result['message'], $result['ok'] ? 'success' : 'error');
            } else {
                yq_flash($fid ? '圈子已更新' : '圈子已添加');
            }
            yq_go(array('tab' => 'feeds'));
            break;

        case 'delete_feed':
            $fid = yq_post_int('fid');
            if ($fid) {
                YouQuan_Feed::deleteFeed($fid);
                yq_flash('圈子及其文章已删除');
            }
            yq_go(array('tab' => 'feeds'));
            break;

        case 'toggle_feed':
            $fid = yq_post_int('fid');
            $feed = YouQuan_Feed::getFeed($fid);
            if ($feed) {
                $status = (int) $feed['status'] === 1 ? 0 : 1;
                YouQuan_Feed::updateFeed($fid, array('status' => $status));
                yq_flash($status ? '圈子已启用' : '圈子已停用');
            }
            yq_go(array('tab' => 'feeds'));
            break;

        case 'bulk_feeds':
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
            $op = yq_post('op');
            if ($ids) {
                if ($op === 'delete') {
                    foreach ($ids as $id) YouQuan_Feed::deleteFeed($id);
                    yq_flash('已删除 ' . count($ids) . ' 个圈子');
                } elseif ($op === 'enable' || $op === 'disable') {
                    $status = $op === 'enable' ? 1 : 0;
                    foreach ($ids as $id) YouQuan_Feed::updateFeed($id, array('status' => $status));
                    yq_flash('已' . ($status ? '启用' : '停用') . ' ' . count($ids) . ' 个圈子');
                }
            }
            yq_go(array('tab' => 'feeds'));
            break;

        case 'import_links':
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
            if (!$ids) {
                yq_flash('请先勾选要导入的友链', 'error');
                yq_go(array('tab' => 'feeds'));
            }
            list($imported, $skipped, $failed) = yq_import_links($ids);
            $text = '导入完成：新增 ' . count($imported) . ' 个圈子';
            if ($skipped) $text .= '，跳过 ' . $skipped . ' 个';
            if ($failed) $text .= '，失败 ' . count($failed) . ' 个（' . implode('、', array_slice($failed, 0, 5)) . '）';

            if ($imported) {
                $fetched = 0;
                foreach (array_slice($imported, 0, 5) as $fid) {
                    $result = YouQuan_Feed::fetch($fid);
                    if ($result['ok']) $fetched++;
                }
                $text .= '；已抓取 ' . $fetched . ' 个圈子';
            }
            yq_flash($text, $failed ? 'warn' : 'success');
            yq_go(array('tab' => 'feeds'));
            break;

        case 'refresh_all':
            $feeds = YouQuan_Feed::getActiveFeeds();
            $done = 0;
            $added = 0;
            foreach ($feeds as $feed) {
                if ($done >= 8) break;
                $result = YouQuan_Feed::fetch($feed['fid']);
                if ($result['ok']) $added += (int) $result['added'];
                $done++;
            }
            yq_flash('已抓取 ' . $done . ' 个圈子，新增 ' . $added . ' 篇文章');
            yq_go(array('tab' => 'feeds'));
            break;

        case 'delete_post':
            $pid = yq_post_int('pid');
            if ($pid) {
                YouQuan_Feed::deletePosts(array($pid));
                yq_flash('文章已删除');
            }
            yq_go(array('tab' => 'posts'));
            break;

        case 'bulk_posts':
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
            $op = yq_post('op');
            if ($ids) {
                if ($op === 'delete') {
                    YouQuan_Feed::deletePosts($ids);
                    yq_flash('已删除 ' . count($ids) . ' 篇文章');
                } elseif ($op === 'hide' || $op === 'show') {
                    YouQuan_Feed::setPostsHidden($ids, $op === 'hide');
                    yq_flash('已' . ($op === 'hide' ? '隐藏' : '显示') . ' ' . count($ids) . ' 篇文章');
                }
            }
            yq_go(array('tab' => 'posts'));
            break;

        case 'clear_posts':
            $fid = yq_post_int('fid');
            YouQuan_Feed::clearPosts($fid);
            yq_flash($fid ? '该圈子的文章已清空' : '所有文章已清空');
            yq_go(array('tab' => 'posts'));
            break;

        case 'prune_duplicates':
            $removed = YouQuan_Feed::pruneDuplicates();
            yq_flash($removed > 0
                ? '已清理 ' . $removed . ' 条重复文章（同一链接只保留一条）'
                : '没有发现重复文章', $removed > 0 ? 'success' : 'warn');
            yq_go(array('tab' => 'feeds'));
            break;

        case 'backfill_avatars':
            $done = YouQuan_Feed::backfillAvatars(30);
            yq_flash($done > 0
                ? '已为 ' . $done . ' 位圈友补全头像'
                : '没有可补全的头像（需启用 FriendLinks 友链插件）', $done > 0 ? 'success' : 'warn');
            yq_go(array('tab' => 'feeds'));
            break;

        case 'reregister_hooks':
            if (YouQuan_Plugin::reregisterHooks()) {
                yq_flash('钩子已重新写入，前台现在应该已生效（如仍无效请刷新页面缓存）');
            } else {
                yq_flash('写入失败，请确认数据库可写；也可以到「控制台 → 插件」把有圈停用再启用一次', 'error');
            }
            yq_go(array('tab' => 'settings'));
            break;

        case 'repair_page':
            $page = YouQuan_Feed::ensurePage();
            if ($page) {
                yq_flash('页面与导航图标已就绪：' . $page['title']);
            } else {
                yq_flash('页面创建失败，请检查数据库权限', 'error');
            }
            yq_go(array('tab' => 'settings'));
            break;

        case 'save_settings':
            $pairs = array(
                'title'            => yq_post('title', '博友圈'),
                'subtitle'         => yq_post('subtitle'),
                'per_page'         => max(1, min(50, yq_post_int('per_page', 12))),
                'layout'           => yq_post('layout') === 'grid' ? 'grid' : 'list',
                'summary_len'      => max(30, min(300, yq_post_int('summary_len', 110))),
                'time_format'      => yq_post('time_format') === 'date' ? 'date' : 'relative',
                'order'            => yq_post('order') === 'created' ? 'created' : 'published',
                'refresh_interval' => max(5, min(1440, yq_post_int('refresh_interval', 30))),
                'fetch_timeout'    => max(3, min(30, yq_post_int('fetch_timeout', 8))),
                'keep_per_feed'    => max(5, min(200, yq_post_int('keep_per_feed', 20))),
                'exclude_keywords' => yq_post('exclude_keywords'),
                'show_avatar'      => isset($_POST['show_avatar']) ? 1 : 0,
                'show_source'      => isset($_POST['show_source']) ? 1 : 0,
                'show_time'        => isset($_POST['show_time']) ? 1 : 0,
                'show_cover'       => isset($_POST['show_cover']) ? 1 : 0,
                'show_filter'      => isset($_POST['show_filter']) ? 1 : 0,
                'show_head'        => isset($_POST['show_head']) ? 1 : 0,
                'target_blank'     => isset($_POST['target_blank']) ? 1 : 0,
                'hide_page_title'  => isset($_POST['hide_page_title']) ? 1 : 0,
                'auto_refresh'     => isset($_POST['auto_refresh']) ? 1 : 0,
            );
            YouQuan_Config::setMany($pairs);
            // 同步一份给 Typecho 的插件设置页，避免两处显示不一致
            YouQuan_Config::syncTypechoRow();
            yq_flash('设置已保存');
            yq_go(array('tab' => 'settings'));
            break;

        case 'bind_page':
            $cid = yq_post_int('page_cid');
            if ($cid > 0) {
                $page = YouQuan_Feed::getPage($cid);
                if (!$page) {
                    yq_flash('所选页面不存在', 'error');
                    yq_go(array('tab' => 'settings'));
                }
                YouQuan_Config::setMany(array('page_cid' => $cid, 'page_slug' => $page['slug']));
            } else {
                YouQuan_Config::set('page_cid', 0);
            }
            $slug = YouQuan_Feed::sanitizeSlug(yq_post('page_slug', 'youquan'));
            if ($slug !== '') {
                YouQuan_Config::set('page_slug', $slug);
            }
            YouQuan_Config::syncTypechoRow();
            yq_flash('页面绑定已更新');
            yq_go(array('tab' => 'settings'));
            break;

        case 'create_page':
            $title = yq_post('page_title', '博友圈');
            $slug = YouQuan_Feed::sanitizeSlug(yq_post('page_slug', 'youquan'));
            if ($slug === '') $slug = 'youquan';
            $page = YouQuan_Feed::createPage($title, $slug);
            if ($page) {
                yq_flash('有圈页面已创建并绑定，短链接：' . YouQuan_Plugin::shortLink());
            } else {
                yq_flash('页面创建失败，请检查数据库权限', 'error');
            }
            yq_go(array('tab' => 'settings'));
            break;

        default:
            yq_flash('未知操作', 'error');
            yq_go(array('tab' => $tab));
    }
}

/* =====================================================================
 * 页面数据
 * ================================================================== */

$config = YouQuan_Config::all();

$keyword = trim((string) ($_GET['q'] ?? ''));
$statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : '';
$paged = max(1, (int) ($_GET['paged'] ?? 1));
$perPage = 20;

$feedArgs = array('keyword' => $keyword, 'status' => $statusFilter, 'page' => $paged, 'per_page' => $perPage);
$feeds = YouQuan_Feed::getFeeds($feedArgs);
$feedTotal = YouQuan_Feed::countFeeds($feedArgs);
$feedPages = max(1, (int) ceil($feedTotal / $perPage));
$counts = YouQuan_Feed::countsByFeed();

$postFeed = (int) ($_GET['feed'] ?? 0);
$postKeyword = trim((string) ($_GET['pq'] ?? ''));
$postHidden = isset($_GET['hidden']) && $_GET['hidden'] !== '' ? (int) $_GET['hidden'] : '';
$postPaged = max(1, (int) ($_GET['paged'] ?? 1));

$postArgs = array(
    'fid'      => $postFeed,
    'keyword'  => $postKeyword,
    'hidden'   => $postHidden,
    'page'     => $postPaged,
    'per_page' => $perPage,
    'order'    => 'created',
);
$posts = YouQuan_Feed::getPosts($postArgs + array('include_feeds' => true));
$postTotal = YouQuan_Feed::countPosts($postArgs);
$postPages = max(1, (int) ceil($postTotal / $perPage));

$activeFeeds = YouQuan_Feed::getActiveFeeds();
$activeCount = count($activeFeeds);
$totalPosts = YouQuan_Feed::countPosts(array('hidden' => 0));
$hiddenPosts = YouQuan_Feed::countPosts(array('hidden' => 1));
$allFeedsForSelect = YouQuan_Feed::getFeeds();

$lastFetch = 0;
foreach ($allFeedsForSelect as $row) {
    $lastFetch = max($lastFetch, (int) $row['last_fetch']);
}

$editId = (int) ($_GET['edit'] ?? 0);
$editFeed = $editId ? YouQuan_Feed::getFeed($editId) : null;

$friendRows = array();
$friendTableReady = true;
try {
    $friendRows = $db->fetchAll($db->select()->from($prefix . 'friend_links')->order('join_time', Typecho_Db::SORT_DESC)) ?: array();
} catch (Exception $e) {
    $friendTableReady = false;
}

$importedMap = array();
foreach ($allFeedsForSelect as $row) {
    $importedMap[$row['feed_url']] = (int) $row['fid'];
}

$pagesList = YouQuan_Feed::pages();
$currentPage = $config['page_cid'] ? YouQuan_Feed::getPage((int) $config['page_cid']) : null;
if (!$currentPage && !empty($config['page_slug'])) {
    $currentPage = YouQuan_Feed::getPageBySlug($config['page_slug']);
}
$currentPageUrl = $currentPage ? YouQuan_Feed::pageUrl($currentPage) : '';
$shortLink = YouQuan_Plugin::shortLink();
$hooksOk = YouQuan_Plugin::hooksRegistered();
$flash = yq_take_flash();

$pluginUrl = $options->pluginUrl . '/YouQuan';

?>
<?php require_once __TYPECHO_ROOT_DIR__ . '/admin/header.php'; ?>
<?php require_once __TYPECHO_ROOT_DIR__ . '/admin/menu.php'; ?>
<link rel="stylesheet" href="<?php echo yq_h($pluginUrl . '/assets/admin.css?v=' . YouQuan_Plugin::VERSION); ?>">

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('有圈'); ?>
                <span class="yq-ver" title="插件版本">v<?php echo yq_h(YouQuan_Plugin::VERSION); ?></span>
                <button type="button" class="btn primary" data-open-modal="feed-modal" data-feed="new">添加圈友</button>
                <button type="button" class="btn" data-open-modal="import-modal">从友链导入</button>
                <a class="btn" href="<?php echo yq_h($shortLink); ?>" target="_blank" rel="noopener">查看前台</a>
            </h2>
        </div>

        <?php if (!empty($flash)): ?>
            <p class="message <?php echo $flash['t'] === 'error' ? 'error' : ($flash['t'] === 'warn' ? 'notice' : 'success'); ?>"><?php echo yq_h($flash['m']); ?></p>
        <?php endif; ?>

        <div class="row typecho-page-main">
            <div class="col-mb-12">

                <ul class="typecho-option-tabs fix-tabs clearfix">
                    <li class="<?php echo $tab === 'feeds' ? 'active' : ''; ?>"><a href="<?php echo yq_h(yq_url(array('tab' => 'feeds'))); ?>">圈友 <em><?php echo count($allFeedsForSelect); ?></em></a></li>
                    <li class="<?php echo $tab === 'posts' ? 'active' : ''; ?>"><a href="<?php echo yq_h(yq_url(array('tab' => 'posts'))); ?>">文章 <em><?php echo $totalPosts + $hiddenPosts; ?></em></a></li>
                    <li class="<?php echo $tab === 'settings' ? 'active' : ''; ?>"><a href="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>">展示与抓取</a></li>
                    <li class="<?php echo $tab === 'help' ? 'active' : ''; ?>"><a href="<?php echo yq_h(yq_url(array('tab' => 'help'))); ?>">调用方式</a></li>
                </ul>

                <p class="yq-lead">
                    已启用 <strong><?php echo $activeCount; ?></strong> 位圈友 ·
                    <strong><?php echo $totalPosts; ?></strong> 篇文章 ·
                    最近抓取 <?php echo $lastFetch ? yq_h(YouQuan_Render::formatTime($lastFetch)) : '尚未抓取'; ?>
                </p>

                <div class="yq-panel">

            <?php if ($tab === 'feeds'): ?>
            <!-- ============ 圈子管理 ============ -->
            <div class="yq-card">
                <div class="yq-card-hd">
                    <div class="yq-card-title">
                        <h2>圈友列表</h2>
                        <span>每个圈友对应一个 RSS 订阅地址，抓取到的文章进入博友圈。</span>
                    </div>
                    <div class="yq-card-tools">
                        <form method="get" action="<?php echo yq_h(Typecho_Common::url('extending.php', $options->adminUrl)); ?>" class="yq-filter">
                            <input type="hidden" name="panel" value="YouQuan/Manage.php">
                            <input type="hidden" name="tab" value="feeds">
                            <input class="yq-input" type="search" name="q" value="<?php echo yq_h($keyword); ?>" placeholder="搜索名称 / 站点 / RSS">
                            <select class="yq-input" name="status">
                                <option value="">全部状态</option>
                                <option value="1" <?php echo $statusFilter === 1 ? 'selected' : ''; ?>>已启用</option>
                                <option value="0" <?php echo $statusFilter === 0 ? 'selected' : ''; ?>>已停用</option>
                            </select>
                            <button class="btn" type="submit">筛选</button>
                        </form>
                        <button type="button" class="btn" id="yq-fetch-all">抓取全部</button>
                        <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'feeds'))); ?>" class="yq-inline-form">
                            <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                            <input type="hidden" name="action" value="prune_duplicates">
                            <button class="btn" type="submit" title="同一篇文章被多个源抓到时会重复出现，这里按链接去重">清理重复</button>
                        </form>
                        <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'feeds'))); ?>" class="yq-inline-form">
                            <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                            <input type="hidden" name="action" value="backfill_avatars">
                            <button class="btn" type="submit" title="复用 FriendLinks 缓存的站点图标">补全头像</button>
                        </form>
                    </div>
                </div>

                <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'feeds'))); ?>" id="yq-feeds-form">
                    <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                    <input type="hidden" name="action" value="bulk_feeds">
                    <div class="yq-table-wrap">
                        <table class="yq-table">
                            <thead>
                                <tr>
                                    <th class="yq-col-check"><input type="checkbox" data-check-all="feeds"></th>
                                    <th>圈友</th>
                                    <th>RSS 订阅地址</th>
                                    <th class="yq-col-num">文章</th>
                                    <th>最近抓取</th>
                                    <th>状态</th>
                                    <th class="yq-col-op">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$feeds): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="yq-empty">
                                            <p>还没有圈友</p>
                                            <span>可以点右上角「从友链导入」一键导入 FriendLinks 里带 RSS 的友链，或手动添加。</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($feeds as $feed):
                                $fid = (int) $feed['fid'];
                                $avatar = trim((string) $feed['avatar']);
                                if ($avatar !== '' && !preg_match('#^https?://#i', $avatar)) {
                                    $avatar = Typecho_Common::url(ltrim($avatar, '/'), $options->siteUrl);
                                }
                            ?>
                                <tr data-feed-row="<?php echo $fid; ?>" data-feed-status="<?php echo (int) $feed['status']; ?>">
                                    <td class="yq-col-check"><input type="checkbox" name="ids[]" value="<?php echo $fid; ?>" data-check="feeds"></td>
                                    <td>
                                        <div class="yq-site">
                                            <span class="yq-site-av" data-letter="<?php echo yq_h(mb_substr($feed['name'], 0, 1, 'UTF-8')); ?>">
                                                <?php if ($avatar !== ''): ?><img src="<?php echo yq_h($avatar); ?>" alt="" onerror="this.remove()"><?php endif; ?>
                                                <em><?php echo yq_h(mb_substr($feed['name'], 0, 1, 'UTF-8')); ?></em>
                                            </span>
                                            <div class="yq-site-info">
                                                <a href="<?php echo yq_h($feed['site_url']); ?>" target="_blank" rel="noopener" class="yq-site-name"><?php echo yq_h($feed['name'] ?: '未命名'); ?></a>
                                                <span class="yq-site-host"><?php echo yq_h(YouQuan_Render::host($feed['site_url'])); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a class="yq-feed-url" href="<?php echo yq_h($feed['feed_url']); ?>" target="_blank" rel="noopener" title="<?php echo yq_h($feed['feed_url']); ?>"><?php echo yq_h($feed['feed_url']); ?></a>
                                    </td>
                                    <td class="yq-col-num"><span class="yq-num" data-feed-count="<?php echo $fid; ?>"><?php echo (int) ($counts[$fid] ?? 0); ?></span></td>
                                    <td>
                                        <div class="yq-time" data-feed-time="<?php echo $fid; ?>">
                                            <?php echo $feed['last_fetch'] ? yq_h(YouQuan_Render::formatTime((int) $feed['last_fetch'])) : '未抓取'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span data-feed-badge="<?php echo $fid; ?>"><?php echo yq_feed_badge($feed); ?></span>
                                        <?php if ((int) $feed['status'] !== 1): ?><span class="yq-badge is-off">已停用</span><?php endif; ?>
                                    </td>
                                    <td class="yq-col-op">
                                        <div class="yq-ops">
                                            <button type="button" class="yq-link" data-fetch="<?php echo $fid; ?>">抓取</button>
                                            <a class="yq-link" href="<?php echo yq_h(yq_url(array('tab' => 'feeds', 'edit' => $fid))); ?>">编辑</a>
                                            <button type="submit" class="yq-link" form="yq-toggle-<?php echo $fid; ?>"><?php echo (int) $feed['status'] === 1 ? '停用' : '启用'; ?></button>
                                            <button type="submit" class="yq-link is-danger" form="yq-delete-<?php echo $fid; ?>" onclick="return confirm('删除「<?php echo yq_h($feed['name']); ?>」及其全部文章？此操作不可恢复。')">删除</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($feeds): ?>
                    <div class="yq-bulk">
                        <select name="op" class="yq-input">
                            <option value="">批量操作</option>
                            <option value="enable">启用</option>
                            <option value="disable">停用</option>
                            <option value="delete">删除（含文章）</option>
                        </select>
                        <button class="yq-btn is-small" type="submit" onclick="return confirm('确定对选中的圈子执行该操作？')">应用</button>
                        <span class="yq-bulk-hint">共 <?php echo $feedTotal; ?> 个圈子</span>
                    </div>
                    <?php endif; ?>
                </form>

                <?php
                if ($feeds) {
                    echo yq_pager($paged, $feedPages, array('tab' => 'feeds', 'q' => $keyword, 'status' => $statusFilter === '' ? '' : $statusFilter), '共 ' . $feedTotal . ' 个圈子');
                }
                ?>
            </div>

            <?php
            // 单条操作的表单（放在表格外，避免嵌套 form）
            if ($feeds) {
                foreach ($feeds as $feed) {
                    $fid = (int) $feed['fid'];
                    echo '<form method="post" id="yq-toggle-' . $fid . '" action="' . yq_h(yq_url(array('tab' => 'feeds'))) . '" class="yq-hidden-form">'
                        . '<input type="hidden" name="_" value="' . yq_h(yq_token()) . '">'
                        . '<input type="hidden" name="action" value="toggle_feed">'
                        . '<input type="hidden" name="fid" value="' . $fid . '">'
                        . '</form>';
                    echo '<form method="post" id="yq-delete-' . $fid . '" action="' . yq_h(yq_url(array('tab' => 'feeds'))) . '" class="yq-hidden-form">'
                        . '<input type="hidden" name="_" value="' . yq_h(yq_token()) . '">'
                        . '<input type="hidden" name="action" value="delete_feed">'
                        . '<input type="hidden" name="fid" value="' . $fid . '">'
                        . '</form>';
                }
            }
            ?>

            <?php elseif ($tab === 'posts'): ?>
            <!-- ============ 文章管理 ============ -->
            <div class="yq-card">
                <div class="yq-card-hd">
                    <div class="yq-card-title">
                        <h2>文章列表</h2>
                        <span>抓取到的文章；隐藏后不会出现在博友圈，但保留数据。</span>
                    </div>
                    <div class="yq-card-tools">
                        <form method="get" action="<?php echo yq_h(Typecho_Common::url('extending.php', $options->adminUrl)); ?>" class="yq-filter">
                            <input type="hidden" name="panel" value="YouQuan/Manage.php">
                            <input type="hidden" name="tab" value="posts">
                            <select class="yq-input" name="feed">
                                <option value="0">全部圈子</option>
                                <?php foreach ($allFeedsForSelect as $feedOption): ?>
                                    <option value="<?php echo (int) $feedOption['fid']; ?>" <?php echo $postFeed === (int) $feedOption['fid'] ? 'selected' : ''; ?>>
                                        <?php echo yq_h($feedOption['name'] ?: $feedOption['feed_url']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select class="yq-input" name="hidden">
                                <option value="">全部文章</option>
                                <option value="0" <?php echo $postHidden === 0 ? 'selected' : ''; ?>>显示中</option>
                                <option value="1" <?php echo $postHidden === 1 ? 'selected' : ''; ?>>已隐藏</option>
                            </select>
                            <input class="yq-input" type="search" name="pq" value="<?php echo yq_h($postKeyword); ?>" placeholder="搜索标题或摘要">
                            <button class="yq-btn" type="submit">筛选</button>
                        </form>
                    </div>
                </div>

                <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'posts'))); ?>" id="yq-posts-form">
                    <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                    <input type="hidden" name="action" value="bulk_posts">
                    <div class="yq-table-wrap">
                        <table class="yq-table">
                            <thead>
                                <tr>
                                    <th class="yq-col-check"><input type="checkbox" data-check-all="posts"></th>
                                    <th>标题</th>
                                    <th>来源</th>
                                    <th>发布时间</th>
                                    <th>抓取时间</th>
                                    <th>状态</th>
                                    <th class="yq-col-op">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$posts): ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="yq-empty">
                                            <p>还没有文章</p>
                                            <span>先到「圈子管理」添加圈友并抓取，或检查订阅地址是否可访问。</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: foreach ($posts as $post): $pid = (int) $post['pid']; ?>
                                <tr>
                                    <td class="yq-col-check"><input type="checkbox" name="ids[]" value="<?php echo $pid; ?>" data-check="posts"></td>
                                    <td>
                                        <a class="yq-post-title" href="<?php echo yq_h($post['link']); ?>" target="_blank" rel="noopener"><?php echo yq_h($post['title']); ?></a>
                                        <?php if (trim((string) $post['summary']) !== ''): ?>
                                            <div class="yq-post-excerpt"><?php echo yq_h(mb_substr($post['summary'], 0, 80, 'UTF-8')); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="yq-post-source"><?php echo yq_h($post['feed_name'] ?: YouQuan_Render::host($post['feed_site'])); ?></span></td>
                                    <td class="yq-nowrap"><?php echo $post['published'] ? yq_h(date('Y-m-d H:i', (int) $post['published'])) : '—'; ?></td>
                                    <td class="yq-nowrap"><?php echo yq_h(date('Y-m-d H:i', (int) $post['created'])); ?></td>
                                    <td>
                                        <?php if ((int) $post['hidden'] === 1): ?>
                                            <span class="yq-badge is-off">已隐藏</span>
                                        <?php else: ?>
                                            <span class="yq-badge is-ok">显示中</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="yq-col-op">
                                        <div class="yq-ops">
                                            <a class="yq-link" href="<?php echo yq_h($post['link']); ?>" target="_blank" rel="noopener">查看</a>
                                            <button type="submit" class="yq-link" form="yq-post-hide-<?php echo $pid; ?>"><?php echo (int) $post['hidden'] === 1 ? '显示' : '隐藏'; ?></button>
                                            <button type="submit" class="yq-link is-danger" form="yq-post-del-<?php echo $pid; ?>" onclick="return confirm('删除这篇文章？')">删除</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($posts): ?>
                    <div class="yq-bulk">
                        <select name="op" class="yq-input">
                            <option value="">批量操作</option>
                            <option value="hide">隐藏</option>
                            <option value="show">显示</option>
                            <option value="delete">删除</option>
                        </select>
                        <button class="yq-btn is-small" type="submit" onclick="return confirm('确定对选中的文章执行该操作？')">应用</button>
                        <span class="yq-bulk-hint">共 <?php echo $postTotal; ?> 篇文章</span>
                    </div>
                    <?php endif; ?>
                </form>

                <?php
                if ($posts) {
                    echo yq_pager($postPaged, $postPages, array('tab' => 'posts', 'feed' => $postFeed, 'pq' => $postKeyword, 'hidden' => $postHidden === '' ? '' : $postHidden), '共 ' . $postTotal . ' 篇');
                }
                ?>

                <div class="yq-card-ft">
                    <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'posts'))); ?>" class="yq-inline-form">
                        <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                        <input type="hidden" name="action" value="clear_posts">
                        <input type="hidden" name="fid" value="<?php echo (int) $postFeed; ?>">
                        <button class="yq-btn is-small is-danger-ghost" type="submit" onclick="return confirm('确认清空当前筛选范围内的全部文章？')">
                            清空<?php echo $postFeed ? '该圈子的' : '全部'; ?>文章
                        </button>
                    </form>
                </div>
            </div>

            <?php
            // 单条文章操作表单
            if ($posts) {
                foreach ($posts as $post) {
                    $pid = (int) $post['pid'];
                    $nextHidden = (int) $post['hidden'] === 1 ? 0 : 1;
                    echo '<form method="post" id="yq-post-hide-' . $pid . '" action="' . yq_h(yq_url(array('tab' => 'posts'))) . '" class="yq-hidden-form">'
                        . '<input type="hidden" name="_" value="' . yq_h(yq_token()) . '">'
                        . '<input type="hidden" name="action" value="bulk_posts">'
                        . '<input type="hidden" name="op" value="' . ($nextHidden ? 'hide' : 'show') . '">'
                        . '<input type="hidden" name="ids[]" value="' . $pid . '">'
                        . '</form>';
                    echo '<form method="post" id="yq-post-del-' . $pid . '" action="' . yq_h(yq_url(array('tab' => 'posts'))) . '" class="yq-hidden-form">'
                        . '<input type="hidden" name="_" value="' . yq_h(yq_token()) . '">'
                        . '<input type="hidden" name="action" value="delete_post">'
                        . '<input type="hidden" name="pid" value="' . $pid . '">'
                        . '</form>';
                }
            }
            ?>

            <?php elseif ($tab === 'settings'): ?>
            <!-- ============ 设置 ============ -->
            <div class="yq-grid-2">
                <div class="yq-card">
                    <div class="yq-card-hd">
                        <div class="yq-card-title"><h2>展示设置</h2><span>影响前台博友圈的外观与信息密度。</span></div>
                    </div>
                    <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>">
                        <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                        <input type="hidden" name="action" value="save_settings">
                        <div class="yq-card-bd">
                            <div class="yq-field">
                                <label>标题</label>
                                <input class="yq-input" type="text" name="title" value="<?php echo yq_h($config['title']); ?>" placeholder="博友圈">
                                <span class="yq-tip">页面顶部的标题，默认「博友圈」。</span>
                            </div>
                            <div class="yq-field">
                                <label>副标题</label>
                                <input class="yq-input" type="text" name="subtitle" value="<?php echo yq_h($config['subtitle']); ?>" placeholder="选填，例如：记录每一个认真写字的人">
                            </div>
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>布局</label>
                                    <select class="yq-input" name="layout">
                                        <option value="list" <?php echo $config['layout'] === 'list' ? 'selected' : ''; ?>>列表（同主题文章列表）</option>
                                        <option value="grid" <?php echo $config['layout'] === 'grid' ? 'selected' : ''; ?>>卡片（两列）</option>
                                    </select>
                                </div>
                                <div class="yq-field">
                                    <label>每页条数</label>
                                    <input class="yq-input" type="number" name="per_page" min="1" max="50" value="<?php echo (int) $config['per_page']; ?>">
                                </div>
                            </div>
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>摘要字数</label>
                                    <input class="yq-input" type="number" name="summary_len" min="30" max="300" value="<?php echo (int) $config['summary_len']; ?>">
                                </div>
                                <div class="yq-field">
                                    <label>时间显示</label>
                                    <select class="yq-input" name="time_format">
                                        <option value="relative" <?php echo $config['time_format'] === 'relative' ? 'selected' : ''; ?>>相对时间（3 小时前）</option>
                                        <option value="date" <?php echo $config['time_format'] === 'date' ? 'selected' : ''; ?>>日期（2026-10-31）</option>
                                    </select>
                                </div>
                            </div>
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>排序</label>
                                    <select class="yq-input" name="order">
                                        <option value="published" <?php echo $config['order'] === 'published' ? 'selected' : ''; ?>>按原文发布时间</option>
                                        <option value="created" <?php echo $config['order'] === 'created' ? 'selected' : ''; ?>>按抓取时间</option>
                                    </select>
                                </div>
                            </div>
                            <div class="yq-checks">
                                <label class="yq-check"><input type="checkbox" name="show_head" value="1" <?php echo !empty($config['show_head']) ? 'checked' : ''; ?>> 显示顶部标题</label>
                                <label class="yq-check"><input type="checkbox" name="hide_page_title" value="1" <?php echo !empty($config['hide_page_title']) ? 'checked' : ''; ?>> 隐藏主题页面标题</label>
                                <label class="yq-check"><input type="checkbox" name="show_filter" value="1" <?php echo !empty($config['show_filter']) ? 'checked' : ''; ?>> 显示圈子筛选</label>
                                <label class="yq-check"><input type="checkbox" name="show_avatar" value="1" <?php echo !empty($config['show_avatar']) ? 'checked' : ''; ?>> 显示来源头像</label>
                                <label class="yq-check"><input type="checkbox" name="show_source" value="1" <?php echo !empty($config['show_source']) ? 'checked' : ''; ?>> 显示来源站点</label>
                                <label class="yq-check"><input type="checkbox" name="show_time" value="1" <?php echo !empty($config['show_time']) ? 'checked' : ''; ?>> 显示时间</label>
                                <label class="yq-check"><input type="checkbox" name="show_cover" value="1" <?php echo !empty($config['show_cover']) ? 'checked' : ''; ?>> 显示封面缩略图</label>
                                <label class="yq-check"><input type="checkbox" name="target_blank" value="1" <?php echo !empty($config['target_blank']) ? 'checked' : ''; ?>> 新窗口打开</label>
                            </div>
                        </div>
                        <div class="yq-card-ft">
                            <button class="yq-btn is-primary" type="submit">保存展示设置</button>
                        </div>
                    </form>
                </div>

                <div class="yq-card">
                    <div class="yq-card-hd">
                        <div class="yq-card-title"><h2>页面与入口</h2><span>有圈页面、短链接、短代码三种调用方式。</span></div>
                    </div>
                    <div class="yq-card-bd">
                        <?php if ($currentPage): ?>
                            <div class="yq-page-state is-ok">
                                <span class="yq-dot-ok"></span>
                                已绑定页面：<strong><?php echo yq_h($currentPage['title']); ?></strong>
                                <a href="<?php echo yq_h($currentPageUrl); ?>" target="_blank" class="yq-link">预览</a>
                                <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>" class="yq-inline-form">
                                    <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                                    <input type="hidden" name="action" value="repair_page">
                                    <button class="btn btn-s" type="submit" title="补齐导航图标与渲染标记">修复页面与图标</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="yq-page-state is-warn">
                                <span class="yq-dot-warn"></span>
                                还没有绑定有圈页面，下面一键创建。
                            </div>
                        <?php endif; ?>

                        <?php if (!$hooksOk): ?>
                            <div class="yq-page-state is-warn">
                                <span class="yq-dot-warn"></span>
                                钩子未生效：前台不会接管页面，短代码也不会渲染。
                                <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>" class="yq-inline-form">
                                    <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                                    <input type="hidden" name="action" value="reregister_hooks">
                                    <button class="btn btn-s primary" type="submit" title="把钩子重新写入 options.plugins">修复钩子</button>
                                </form>
                            </div>
                            <p class="yq-tip">Typecho 只在启用插件的那一刻记录钩子，覆盖升级文件不会自动补上。
                                点「修复钩子」即可，也可以到「控制台 → 插件」把有圈停用再启用一次。</p>
                        <?php endif; ?>

                        <div class="yq-links">
                            <div class="yq-link-row">
                                <span class="yq-link-label">页面地址</span>
                                <code><?php echo $currentPageUrl ? yq_h($currentPageUrl) : '尚未创建'; ?></code>
                            </div>
                            <div class="yq-link-row">
                                <span class="yq-link-label">短链接</span>
                                <code><?php echo yq_h($shortLink); ?></code>
                            </div>
                            <div class="yq-link-row">
                                <span class="yq-link-label">短代码</span>
                                <code>[youquan]</code>
                            </div>
                            <div class="yq-link-row">
                                <span class="yq-link-label">模板标签</span>
                                <code>&lt;?php youquan(); ?&gt;</code>
                            </div>
                        </div>

                        <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>" class="yq-form">
                            <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                            <input type="hidden" name="action" value="bind_page">
                            <div class="yq-field">
                                <label>绑定已有页面</label>
                                <select class="yq-input" name="page_cid">
                                    <option value="0">不绑定</option>
                                    <?php foreach ($pagesList as $pageOption): ?>
                                        <option value="<?php echo (int) $pageOption['cid']; ?>" <?php echo $currentPage && (int) $currentPage['cid'] === (int) $pageOption['cid'] ? 'selected' : ''; ?>>
                                            <?php echo yq_h($pageOption['title']); ?>（/<?php echo yq_h($pageOption['slug']); ?>）
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="yq-field">
                                <label>短链接路径</label>
                                <div class="yq-input-group">
                                    <span><?php echo yq_h(rtrim($options->siteUrl, '/')); ?>/</span>
                                    <input class="yq-input" type="text" name="page_slug" value="<?php echo yq_h($config['page_slug']); ?>" placeholder="youquan">
                                </div>
                                <span class="yq-tip">默认 /youquan，访问后跳转到已绑定的页面。</span>
                            </div>
                            <button class="yq-btn" type="submit">保存绑定</button>
                        </form>

                        <hr class="yq-hr">

                        <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>" class="yq-form" id="yq-create-page-form">
                            <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                            <input type="hidden" name="action" value="create_page">
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>页面标题</label>
                                    <input class="yq-input" type="text" name="page_title" value="<?php echo yq_h($config['title']); ?>">
                                </div>
                                <div class="yq-field">
                                    <label>页面缩略名</label>
                                    <input class="yq-input" type="text" name="page_slug" value="<?php echo yq_h($config['page_slug']); ?>">
                                </div>
                            </div>
                            <div class="yq-form-actions">
                                <button class="yq-btn is-primary" type="submit">创建并绑定「有圈」页面</button>
                                <span class="yq-tip">页面创建后正文会自动写入 <code>[youquan]</code>，可在页面编辑里继续调整。</span>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="yq-card">
                    <div class="yq-card-hd">
                        <div class="yq-card-title"><h2>抓取设置</h2><span>抓取频率、超时与保留条数。</span></div>
                    </div>
                    <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'settings'))); ?>">
                        <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
                        <input type="hidden" name="action" value="save_settings">
                        <!-- 隐藏域保留展示设置，避免只保存抓取时被重置 -->
                        <input type="hidden" name="title" value="<?php echo yq_h($config['title']); ?>">
                        <input type="hidden" name="subtitle" value="<?php echo yq_h($config['subtitle']); ?>">
                        <input type="hidden" name="per_page" value="<?php echo (int) $config['per_page']; ?>">
                        <input type="hidden" name="layout" value="<?php echo yq_h($config['layout']); ?>">
                        <input type="hidden" name="summary_len" value="<?php echo (int) $config['summary_len']; ?>">
                        <input type="hidden" name="time_format" value="<?php echo yq_h($config['time_format']); ?>">
                        <input type="hidden" name="order" value="<?php echo yq_h($config['order']); ?>">
                        <?php foreach (array('show_avatar', 'show_source', 'show_time', 'show_cover', 'show_filter', 'show_head', 'target_blank', 'hide_page_title') as $flag): ?>
                            <?php if (!empty($config[$flag])): ?><input type="hidden" name="<?php echo $flag; ?>" value="1"><?php endif; ?>
                        <?php endforeach; ?>

                        <div class="yq-card-bd">
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>自动更新间隔（分钟）</label>
                                    <input class="yq-input" type="number" name="refresh_interval" min="5" max="1440" value="<?php echo (int) $config['refresh_interval']; ?>">
                                </div>
                                <div class="yq-field">
                                    <label>请求超时（秒）</label>
                                    <input class="yq-input" type="number" name="fetch_timeout" min="3" max="30" value="<?php echo (int) $config['fetch_timeout']; ?>">
                                </div>
                            </div>
                            <div class="yq-field-row">
                                <div class="yq-field">
                                    <label>每个圈子保留文章数</label>
                                    <input class="yq-input" type="number" name="keep_per_feed" min="5" max="200" value="<?php echo (int) $config['keep_per_feed']; ?>">
                                </div>
                            </div>
                            <div class="yq-field">
                                <label>屏蔽关键词</label>
                                <input class="yq-input" type="text" name="exclude_keywords" value="<?php echo yq_h($config['exclude_keywords']); ?>" placeholder="广告, 抽奖, 福利">
                                <span class="yq-tip">命中关键词的文章不会入库，多个关键词用逗号或空格分隔。</span>
                            </div>
                            <label class="yq-check"><input type="checkbox" name="auto_refresh" value="1" <?php echo !empty($config['auto_refresh']) ? 'checked' : ''; ?>> 访客访问时自动在后台更新过期订阅</label>
                        </div>
                        <div class="yq-card-ft">
                            <button class="yq-btn is-primary" type="submit">保存抓取设置</button>
                            <button class="yq-btn" type="button" id="yq-fetch-all-2">立即抓取全部</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php else: ?>
            <!-- ============ 调用方式 ============ -->
            <div class="yq-card">
                <div class="yq-card-hd">
                    <div class="yq-card-title"><h2>四种调用方式</h2><span>推荐第一种：创建页面后什么都不用写，插件会自动接管页面渲染。</span></div>
                </div>
                <div class="yq-card-bd yq-doc">
                    <h3>1. 独立页面（推荐，无需短代码）</h3>
                    <p>到「展示与抓取」里点「创建并绑定「有圈」页面」。插件会：</p>
                    <ul>
                        <li>创建别名 <code>youquan</code> 的独立页面，并把正文留空；</li>
                        <li>在 <code>Widget\Archive</code> 的 <code>beforeRender</code> 阶段接管这个页面的渲染，用主题自己的页头、左栏、导航、页脚包住博友圈；</li>
                        <li>写入 <code>showPage=1</code> 与 <code>iconPage</code> 两个字段，让「博友圈」直接出现在 ilogs 左侧导航里，并带上图标。</li>
                    </ul>
                    <p>所以页面上不需要写任何短代码，也不会出现主题的空正文区域。</p>

                    <h3>2. 短代码（嵌到已有页面里时用）</h3>
                    <p>想在别的页面中间插入博友圈，正文放：</p>
                    <pre><code>[youquan]</code></pre>
                    <p>短代码支持参数，可覆盖全局设置：</p>
                    <pre><code>[youquan per_page="6" layout="grid" title="博友圈" filter="0"]</code></pre>
                    <table class="yq-table is-doc">
                        <thead><tr><th>参数</th><th>说明</th><th>默认</th></tr></thead>
                        <tbody>
                            <tr><td><code>per_page</code></td><td>每页条数（1-50）</td><td>12</td></tr>
                            <tr><td><code>layout</code></td><td><code>list</code> 列表 / <code>grid</code> 卡片</td><td>list</td></tr>
                            <tr><td><code>title</code></td><td>覆盖标题</td><td>博友圈</td></tr>
                            <tr><td><code>filter</code></td><td><code>0</code> 隐藏圈子筛选</td><td>1</td></tr>
                            <tr><td><code>head</code></td><td><code>0</code> 不输出顶部标题</td><td>1</td></tr>
                            <tr><td><code>src</code></td><td>只看某个圈子的 fid</td><td>全部</td></tr>
                        </tbody>
                    </table>

                    <h3>3. 主题模板标签</h3>
                    <p>在主题模板（如 <code>page-xxx.php</code>）里直接调用：</p>
                    <pre><code>&lt;?php if (function_exists('youquan')) youquan(array('layout' =&gt; 'list', 'per_page' =&gt; 10)); ?&gt;</code></pre>

                    <h3>4. 短链接</h3>
                    <p>默认地址 <code><?php echo yq_h($shortLink); ?></code>，可在设置里改成任意路径；访问后会跳转到绑定的有圈页面，适合放在导航或简介里。</p>

                    <h3>与 FriendLinks 的配合</h3>
                    <p>友链插件里维护的 RSS 地址可以在「圈子管理 → 从友链导入」一键同步；导入后插件会按 RSS 自动抓取，之后新增友链再点一次导入即可增量同步。头像也复用友链的上传头像 / 邮箱头像服务 / 站点 favicon 缓存。</p>

                    <h3>常见问题</h3>
                    <p><strong>抓取失败</strong>：先在浏览器打开 RSS 地址确认可访问；部分站点会拦截非浏览器 UA，插件已使用常见浏览器 UA。</p>
                    <p><strong>没有新文章</strong>：源站可能没更新，或文章已在库中（按 guid 去重）。</p>
                    <p><strong>页面标题重复</strong>：保留「隐藏主题页面标题」开启即可，页面上只留插件自己的博友圈标题（左侧带图标）。</p>
                    <p><strong>导航里没有图标</strong>：到本页最下方「页面与入口」点一次「修复页面与图标」，会重新写入 <code>showPage</code> / <code>iconPage</code> 字段。</p>
                </div>
            </div>
            <?php endif; ?>
                </div><!-- /.yq-panel -->
            </div><!-- /.col-mb-12 -->
        </div><!-- /.row -->
    </div><!-- /.body -->
</div><!-- /.main -->

<!-- 添加/编辑圈子 -->
<div class="yq-modal" id="feed-modal" hidden>
    <div class="yq-modal-mask" data-close-modal></div>
    <div class="yq-modal-box">
        <div class="yq-modal-hd">
            <h3 id="feed-modal-title"><?php echo $editFeed ? '编辑圈子' : '添加圈子'; ?></h3>
            <button type="button" class="yq-modal-close" data-close-modal>&times;</button>
        </div>
        <form method="post" action="<?php echo yq_h(yq_url(array('tab' => 'feeds'))); ?>" id="yq-feed-form">
            <input type="hidden" name="_" value="<?php echo yq_h(yq_token()); ?>">
            <input type="hidden" name="action" value="save_feed">
            <input type="hidden" name="fid" value="<?php echo $editFeed ? (int) $editFeed['fid'] : 0; ?>">
            <div class="yq-modal-bd">
                <div class="yq-field">
                    <label>站点地址</label>
                    <div class="yq-input-group">
                        <input class="yq-input" type="url" name="site_url" id="yq-site-url" value="<?php echo yq_h($editFeed['site_url'] ?? ''); ?>" placeholder="https://example.com">
                        <button class="yq-btn is-small" type="button" id="yq-discover">自动发现 RSS</button>
                    </div>
                    <span class="yq-tip">填写站点域名即可，插件会尝试读取页面里的 RSS 声明。</span>
                </div>
                <div class="yq-field">
                    <label>RSS 订阅地址 <em>*</em></label>
                    <input class="yq-input" type="url" name="feed_url" id="yq-feed-url" value="<?php echo yq_h($editFeed['feed_url'] ?? ''); ?>" placeholder="https://example.com/feed" required>
                    <span class="yq-tip" id="yq-discover-tip">支持 RSS 2.0 / Atom。</span>
                </div>
                <div class="yq-field-row">
                    <div class="yq-field">
                        <label>名称</label>
                        <input class="yq-input" type="text" name="name" value="<?php echo yq_h($editFeed['name'] ?? ''); ?>" placeholder="留空自动使用域名">
                    </div>
                    <div class="yq-field">
                        <label>头像地址</label>
                        <input class="yq-input" type="text" name="avatar" value="<?php echo yq_h($editFeed['avatar'] ?? ''); ?>" placeholder="选填，留空使用首字头像">
                    </div>
                </div>
                <div class="yq-field">
                    <label>简介</label>
                    <input class="yq-input" type="text" name="intro" value="<?php echo yq_h($editFeed['intro'] ?? ''); ?>" placeholder="选填">
                </div>
                <div class="yq-field-row">
                    <div class="yq-field">
                        <label>状态</label>
                        <select class="yq-input" name="status">
                            <option value="1" <?php echo !$editFeed || (int) $editFeed['status'] === 1 ? 'selected' : ''; ?>>启用</option>
                            <option value="0" <?php echo $editFeed && (int) $editFeed['status'] === 0 ? 'selected' : ''; ?>>停用</option>
                        </select>
                    </div>
                    <div class="yq-field">
                        <label>排序</label>
                        <input class="yq-input" type="number" name="sort" value="<?php echo (int) ($editFeed['sort'] ?? 0); ?>">
                    </div>
                </div>
                <label class="yq-check"><input type="checkbox" name="fetch_now" value="1" <?php echo $editFeed ? '' : 'checked'; ?>> 保存后立即抓取</label>
            </div>
            <div class="yq-modal-ft">
                <button class="yq-btn" type="button" data-close-modal>取消</button>
                <button class="yq-btn is-primary" type="submit"><?php echo $editFeed ? '保存修改' : '添加圈子'; ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 从友链导入 -->
<div class="yq-modal" id="import-modal" hidden>
    <div class="yq-modal-mask" data-close-modal></div>
    <div class="yq-modal-box is-wide">
        <div class="yq-modal-hd">
            <h3>从 FriendLinks 导入</h3>
            <button type="button" class="yq-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="yq-modal-bd">
            <?php if (!$friendTableReady): ?>
                <div class="yq-notice is-warn">没有检测到 FriendLinks 友链表，请先安装并启用 <strong>FriendLinks</strong> 插件。</div>
            <?php elseif (!$friendRows): ?>
                <div class="yq-notice">友链列表还是空的，先去「控制台 → 友链管理」添加友链吧。</div>
            <?php else: ?>
                <p class="yq-modal-desc">勾选需要导入的友链。已填写 RSS 的直接导入；没有 RSS 的会尝试从站点自动发现，并复用友链的头像。</p>
                <div class="yq-import-list">
                    <label class="yq-import-all"><input type="checkbox" id="yq-import-all"> 全选（共 <?php echo count($friendRows); ?> 条友链）</label>
                    <?php foreach ($friendRows as $row):
                        $rss = trim((string) ($row['rssurl'] ?? ''));
                        $imported = $rss !== '' && isset($importedMap[YouQuan_Feed::normalizeUrl($rss)]);
                        $label = trim((string) ($row['SiteTitle'] ?? '')) ?: trim((string) ($row['nickname'] ?? ''));
                    ?>
                        <label class="yq-import-item<?php echo $imported ? ' is-imported' : ''; ?>">
                            <input type="checkbox" name="ids[]" value="<?php echo (int) $row['linkid']; ?>" <?php echo $imported ? 'disabled' : ''; ?>>
                            <span class="yq-import-name"><?php echo yq_h($label ?: $row['websiteurl']); ?></span>
                            <span class="yq-import-rss"><?php echo $rss !== '' ? yq_h(YouQuan_Render::host($rss)) : '无 RSS（自动发现）'; ?></span>
                            <?php if ($imported): ?><span class="yq-badge is-ok">已导入</span><?php endif; ?>
                            <?php if ((int) ($row['rssStatus'] ?? 1) === 0): ?><span class="yq-badge is-off">RSS 已关闭</span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="yq-modal-ft">
            <span class="yq-import-progress" id="yq-import-progress"></span>
            <button class="yq-btn" type="button" data-close-modal>取消</button>
            <button class="yq-btn is-primary" type="button" id="yq-import-submit" <?php echo (!$friendTableReady || !$friendRows) ? 'disabled' : ''; ?>>导入并抓取</button>
        </div>
    </div>
</div>

<div class="yq-toast" id="yq-toast" hidden></div>

<script>
window.YQ_ADMIN = {
    endpoint: <?php echo json_encode(yq_url()); ?>,
    token: <?php echo json_encode(yq_token()); ?>,
    feedsTab: <?php echo json_encode(yq_url(array('tab' => 'feeds'))); ?>,
    postsTab: <?php echo json_encode(yq_url(array('tab' => 'posts'))); ?>,
    settingsTab: <?php echo json_encode(yq_url(array('tab' => 'settings'))); ?>,
    autoOpen: <?php echo $editId ? 'true' : 'false'; ?>,
    flash: null
};
</script>
<script src="<?php echo yq_h($pluginUrl . '/assets/admin.js?v=' . YouQuan_Plugin::VERSION); ?>"></script>

<?php require_once __TYPECHO_ROOT_DIR__ . '/admin/footer.php'; ?>
