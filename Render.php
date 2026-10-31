<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 有圈前台渲染。
 *
 * 输出结构与 ilogs 主题的文章列表保持一致：左侧圆形来源头像 + 标题 / 摘要 /
 * 「站点 · 时间」元信息 + 右侧缩略图，视觉上完全融入主题内容区。
 *
 * @package YouQuan
 */
class YouQuan_Render
{
    /**
     * 渲染博友圈。
     *
     * 可用参数：
     *  per_page / layout(list|grid) / show_head / show_filter / title / fid / order / limit
     *
     * @param array $args
     * @param bool $echo
     * @return string
     */
    public static function feed($args = array(), $echo = true)
    {
        $config = YouQuan_Config::all();
        $args = array_merge(array(
            'per_page'    => null,
            'layout'      => null,
            'show_head'   => null,
            'show_filter' => null,
            'title'       => null,
            'fid'         => 0,
            'order'       => null,
        ), (array) $args);

        $layout = in_array($args['layout'], array('list', 'grid'), true)
            ? $args['layout']
            : ($config['layout'] === 'grid' ? 'grid' : 'list');
        $perPage = (int) ($args['per_page'] ?: $config['per_page']);
        $perPage = max(1, min(50, $perPage));
        $showHead = $args['show_head'] === null ? (bool) $config['show_head'] : (bool) $args['show_head'];
        $showFilter = $args['show_filter'] === null ? (bool) $config['show_filter'] : (bool) $args['show_filter'];
        $title = $args['title'] !== null && $args['title'] !== '' ? $args['title'] : $config['title'];
        $order = in_array($args['order'], array('published', 'created'), true) ? $args['order'] : $config['order'];

        $page = isset($_GET['yq_page']) ? max(1, (int) $_GET['yq_page']) : 1;
        $src = isset($_GET['yq_src']) ? (int) $_GET['yq_src'] : (int) $args['fid'];
        if ($args['fid']) $src = (int) $args['fid'];

        // 前台访问时后台异步刷新过期订阅，不阻塞当前请求
        if ($config['auto_refresh']) {
            YouQuan_Feed::deferAutoRefresh();
        }

        $feeds = YouQuan_Feed::getActiveFeeds();
        $html = self::css();

        if (!$feeds) {
            $html .= self::emptyState('还没有圈友，请先到后台「有圈」添加订阅。', $title, $showHead);
            if ($echo) { echo $html; }
            return $html;
        }

        $counts = YouQuan_Feed::countsByFeed();
        $allow = array();
        foreach ($feeds as $feed) {
            $allow[] = (int) $feed['fid'];
        }
        if ($src && !in_array($src, $allow, true)) {
            $src = 0;
        }

        $totalPosts = 0;
        foreach ($counts as $fid => $num) {
            if (in_array((int) $fid, $allow, true)) $totalPosts += (int) $num;
        }

        $query = array(
            'fids'    => $src ? array($src) : $allow,
            'hidden'  => 0,
            'order'   => $order === 'created' ? 'created' : 'published',
            'page'    => $page,
            'per_page' => $perPage,
        );
        $total = YouQuan_Feed::countPosts($query);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $query['page'] = $page;
        }
        $query['include_feeds'] = true;
        $posts = $total > 0 ? YouQuan_Feed::getPosts($query) : array();

        $html .= '<div class="yq-wrap yq-layout-' . $layout . '" id="youquan">';

        if ($showHead) {
            $html .= self::head($title, $config['subtitle'], count($feeds), $totalPosts);
        }

        if ($showFilter && count($feeds) > 1) {
            $html .= self::chips($feeds, $counts, $src);
        }

        if (!$posts) {
            $html .= '<div class="yq-empty">'
                . '<p class="yq-empty-title">暂时还没有文章</p>'
                . '<p class="yq-empty-desc">圈子已建立，等待抓取第一篇文章；也可以到后台手动点击「抓取」。</p>'
                . '</div>';
        } else {
            $html .= $layout === 'grid' ? '<div class="yq-grid">' : '<div class="yq-list">';
            foreach ($posts as $post) {
                $html .= self::item($post, $config);
            }
            $html .= '</div>';
            if ($pages > 1) {
                $html .= self::pager($page, $pages, $src);
            }
        }

        $html .= '</div>';

        if ($echo) {
            echo $html;
        }
        return $html;
    }

    /**
     * 页面接管渲染：用主题自己的页头 / 左栏 / 导航 / 页脚包裹博友圈。
     *
     * 结构与 ilogs 的 post.php 保持一致，因此左栏、导航、右栏、页脚都是原生的。
     *
     * @param object $archive Widget\Archive
     * @return bool 主题不支持时返回 false，交回主题正常渲染
     */
    public static function page($archive)
    {
        if (!is_object($archive)) return false;

        $themeDir = '';
        if (method_exists($archive, 'getThemeDir')) {
            $themeDir = (string) $archive->getThemeDir();
        }
        if ($themeDir === '' && isset($archive->themeDir)) {
            $themeDir = (string) $archive->themeDir;
        }
        if ($themeDir === '') return false;

        $themeDir = rtrim($themeDir, '/\\') . '/';
        foreach (array('template-parts/header.php', 'template-parts/footer.php') as $file) {
            if (!is_file($themeDir . $file)) return false;
        }

        $template = __DIR__ . '/templates/page.php';
        if (!is_file($template)) return false;

        $render = function () use ($template, $archive, $themeDir) {
            require $template;
        };
        $render->call($archive);

        return true;
    }

    /**
     * 「博友圈」标题区。
     *
     * 排版对齐 ilogs 相册页的 .album-page-heading：
     * 1.75rem / 300 的标题、13px 副标题、右侧 12px 统计、底部 1px 分隔线。
     * 页头不带图标（图标只用在左栏导航，由 iconPage 字段提供）。
     */
    public static function head($title, $subtitle = '', $feedCount = 0, $postCount = 0)
    {
        // 用 div + role=heading：主题会给 #post-content 里的 h1/h2/h3 加高亮底色
        $level = YouQuan_Config::bool('hide_page_title') ? 1 : 2;
        $html = '<div class="yq-head">'
            . '<div class="yq-head-main">'
            . '<div class="yq-head-title" role="heading" aria-level="' . $level . '">'
            . '<span class="yq-head-text">' . self::e($title) . '</span>'
            . '</div>';
        if (trim((string) $subtitle) !== '') {
            $html .= '<p class="yq-sub">' . self::e($subtitle) . '</p>';
        }
        $html .= '</div>';
        if ($feedCount > 0) {
            $html .= '<span class="yq-head-meta">' . (int) $feedCount . ' 位圈友 · ' . (int) $postCount . ' 篇文章</span>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Tabler users 图标（24×24，stroke 2）。
     *
     * 页头已经不再使用它；保留为公共方法，方便主题模板自行取用。
     */
    public static function icon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>'
            . '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/>'
            . '<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>'
            . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
            . '<path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>'
            . '</svg>';
    }

    private static function chips(array $feeds, array $counts, $current)
    {
        $total = 0;
        foreach ($counts as $num) $total += (int) $num;

        $html = '<div class="yq-chips">';
        $html .= '<a class="yq-chip' . ($current ? '' : ' is-active') . '" href="' . self::e(self::pageUrl(array())) . '">全部<em>' . $total . '</em></a>';

        // 按文章数排序；没有文章的圈友不占位置（避免一排全是 0）
        usort($feeds, function ($a, $b) use ($counts) {
            $ca = (int) ($counts[(int) $a['fid']] ?? 0);
            $cb = (int) ($counts[(int) $b['fid']] ?? 0);
            if ($ca === $cb) return (int) $a['fid'] - (int) $b['fid'];
            return $cb - $ca;
        });

        $visible = array();
        foreach ($feeds as $feed) {
            $fid = (int) $feed['fid'];
            $num = (int) ($counts[$fid] ?? 0);
            if ($num <= 0 && (int) $current !== $fid) continue;
            $visible[] = array('feed' => $feed, 'fid' => $fid, 'num' => $num);
        }

        // 最多 8 个，当前选中的必须保留
        $limit = 8;
        if (count($visible) > $limit) {
            $sliced = array_slice($visible, 0, $limit);
            if ((int) $current > 0) {
                $hit = false;
                foreach ($sliced as $item) {
                    if ($item['fid'] === (int) $current) { $hit = true; break; }
                }
                if (!$hit) {
                    foreach ($visible as $item) {
                        if ($item['fid'] === (int) $current) {
                            $sliced[$limit - 1] = $item;
                            break;
                        }
                    }
                }
            }
            $visible = $sliced;
        }

        foreach ($visible as $item) {
            $feed = $item['feed'];
            $name = $feed['name'] !== '' ? $feed['name'] : self::host($feed['site_url']);
            $html .= '<a class="yq-chip' . ((int) $current === $item['fid'] ? ' is-active' : '') . '" href="'
                . self::e(self::pageUrl(array('yq_src' => $item['fid']))) . '">'
                . self::e($name) . '<em>' . (int) $item['num'] . '</em></a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 单条文章。
     */
    private static function item(array $post, array $config)
    {
        $link = $post['link'];
        $title = $post['title'] !== '' ? $post['title'] : '（无标题）';
        $name = $post['feed_name'] !== '' ? $post['feed_name'] : self::host($post['feed_site']);
        $site = $post['feed_site'] !== '' ? $post['feed_site'] : $link;
        $blank = $config['target_blank'] ? ' target="_blank" rel="noopener noreferrer nofollow"' : ' rel="nofollow"';

        $html = '<div class="yq-item">';

        if ($config['show_avatar']) {
            $html .= '<a class="yq-avatar" href="' . self::e($site) . '" title="' . self::e($name) . '"' . $blank . '>'
                . self::avatar($post) . '</a>';
        }

        $html .= '<div class="yq-main">';
        $html .= '<div class="yq-title" role="heading" aria-level="3"><a href="' . self::e($link) . '"' . $blank . '>' . self::e($title) . '</a></div>';

        $summary = self::excerpt($post['summary'], $title);
        if ($summary !== '') {
            $html .= '<p class="yq-excerpt">' . self::e($summary) . '</p>';
        }

        $meta = array();
        if ($config['show_source']) {
            $meta[] = '<a class="yq-source" href="' . self::e($site) . '"' . $blank . '>' . self::e($name) . '</a>';
        }
        if ($config['show_time'] && (int) $post['published'] > 0) {
            $meta[] = '<time datetime="' . date('c', (int) $post['published']) . '">'
                . self::e(self::formatTime((int) $post['published'], $config['time_format'])) . '</time>';
        }
        if ($meta) {
            $html .= '<div class="yq-meta">' . implode('<span class="yq-dot">·</span>', $meta) . '</div>';
        }
        $html .= '</div>';

        if ($config['show_cover'] && trim((string) $post['cover']) !== '') {
            $html .= '<a class="yq-cover" href="' . self::e($link) . '"' . $blank . ' aria-hidden="true" tabindex="-1">'
                . '<img src="' . self::e($post['cover']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.removeChild(this)"></a>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * 摘要：与标题完全重复时不显示，以标题开头时去掉重复部分。
     */
    public static function excerpt($summary, $title)
    {
        $summary = trim(preg_replace('/\s+/u', ' ', (string) $summary));
        if ($summary === '') return '';

        $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
        if ($title !== '') {
            $plain = preg_replace('/[^\p{L}\p{N}]+/u', '', $summary);
            $plainTitle = preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
            if ($plain !== '' && $plain === $plainTitle) return '';

            if (mb_strpos($summary, $title, 0, 'UTF-8') === 0) {
                $summary = trim(mb_substr($summary, mb_strlen($title, 'UTF-8'), null, 'UTF-8'));
                $summary = ltrim($summary, " \t\n\r\0\x0B·—-….,，。:：、");
            }
        }

        return $summary;
    }

    /**
     * 头像：有图用图，没有图（或图片加载失败）时露出首字色块。
     */
    public static function avatar(array $post)
    {
        $name = $post['feed_name'] !== '' ? $post['feed_name'] : self::host($post['feed_site']);
        $letter = mb_substr(trim((string) $name), 0, 1, 'UTF-8');
        if ($letter === '') $letter = '·';

        $hash = crc32((string) $name);
        $hue = $hash % 360;

        $html = '<span class="yq-av" style="--yq-h:' . $hue . '">'
            . '<span class="yq-av-letter">' . self::e($letter) . '</span>';

        $avatar = trim((string) $post['feed_avatar']);
        if ($avatar === '') {
            // 没抓到头像时退回站点 favicon，加载失败会自然落回首字
            $site = trim((string) $post['feed_site']);
            $host = $site !== '' ? parse_url($site, PHP_URL_HOST) : '';
            if ($host) {
                $scheme = parse_url($site, PHP_URL_SCHEME) ?: 'https';
                $avatar = $scheme . '://' . $host . '/favicon.ico';
            }
        }

        if ($avatar !== '') {
            if (!preg_match('#^https?://#i', $avatar) && strpos($avatar, '//') !== 0) {
                $avatar = Typecho_Common::url(ltrim($avatar, '/'), Helper::options()->siteUrl);
            }
            $html .= '<img class="yq-av-img" src="' . self::e($avatar) . '" alt="" loading="lazy"'
                . ' referrerpolicy="no-referrer" onerror="this.remove()">';
        }

        $html .= '</span>';
        return $html;
    }

    private static function pager($page, $pages, $src)
    {
        $params = array();
        if ($src) $params['yq_src'] = $src;

        $html = '<nav class="yq-pager" aria-label="分页">';
        if ($page > 1) {
            $prev = $params;
            $prev['yq_page'] = $page - 1;
            $html .= '<a class="yq-page" href="' . self::e(self::pageUrl($prev)) . '">上一页</a>';
        }

        $start = max(1, $page - 2);
        $end = min($pages, $start + 4);
        $start = max(1, $end - 4);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) {
                $html .= '<span class="yq-page is-current" aria-current="page">' . $i . '</span>';
            } else {
                $link = $params;
                if ($i > 1) $link['yq_page'] = $i;
                $html .= '<a class="yq-page" href="' . self::e(self::pageUrl($link)) . '">' . $i . '</a>';
            }
        }

        if ($page < $pages) {
            $next = $params;
            $next['yq_page'] = $page + 1;
            $html .= '<a class="yq-page" href="' . self::e(self::pageUrl($next)) . '">下一页</a>';
        }
        $html .= '</nav>';
        return $html;
    }

    private static function emptyState($message, $title, $showHead)
    {
        $html = '<div class="yq-wrap yq-layout-list" id="youquan">';
        if ($showHead) {
            $html .= self::head($title, '', 0, 0);
        }
        $html .= '<div class="yq-empty"><p class="yq-empty-title">' . self::e($message) . '</p></div></div>';
        return $html;
    }

    /**
     * 当前页面地址 + 查询参数。
     */
    private static function pageUrl(array $params)
    {
        $path = '/';
        if (isset($_SERVER['REQUEST_URI'])) {
            $path = strtok($_SERVER['REQUEST_URI'], '?');
            if ($path === false || $path === '') $path = '/';
        }
        $params = array_filter($params, function ($value) {
            return $value !== '' && $value !== null;
        });
        $query = $params ? '?' . http_build_query($params) : '';
        return $path . $query . '#youquan';
    }

    public static function formatTime($timestamp, $format = 'relative')
    {
        if ($timestamp <= 0) return '';
        if ($format === 'date') {
            return date('Y-m-d', $timestamp);
        }
        $diff = time() - $timestamp;
        if ($diff < 0) $diff = 0;
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
        if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
        if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
        if ($diff < 31536000) return floor($diff / 2592000) . ' 个月前';
        return floor($diff / 31536000) . ' 年前';
    }

    public static function host($url)
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        return $host ? preg_replace('/^www\./i', '', $host) : (string) $url;
    }

    /**
     * 样式：从 assets/front.css 读入并内联，避免主题缓存/路径问题。
     *
     * 这里刻意不做「一个请求只输出一次」的缓存：主题的 header.php 常常会先读一次
     * $this->content 来生成摘要 / description，那一次渲染的输出会被丢弃，
     * 若在此处打了标记，真正输出到页面的那一份就拿不到样式了。
     * 重复输出 <style> 对浏览器无副作用，稳定性优先。
     */
    public static function css()
    {
        $file = __DIR__ . '/assets/front.css';
        $css = is_file($file) ? (string) file_get_contents($file) : '';
        if ($css === '') return '';

        $extra = '';
        if (YouQuan_Config::bool('hide_page_title')) {
            // 用自有标题替换主题页面标题，避免出现两个「博友圈」
            $extra = '#post-article #articleTitle,#post-article #articleTitle+p.card-text{display:none!important}'
                . '#post-article{padding-top:0!important}'
                . '.yq-wrap .yq-head{margin-top:0}';
        }

        return '<style id="youquan-style">' . $css . $extra . '</style>';
    }

    /**
     * 兼容旧调用点。样式现在每次都会输出，这里不再需要重置什么。
     * @return void
     */
    public static function resetStyles()
    {
    }

    public static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
