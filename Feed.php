<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 有圈数据层：圈友（订阅源）、文章、RSS 抓取与解析、页面创建。
 *
 * 只依赖 Typecho 自带的 DB / Helper / Common，不引入任何第三方库，
 * RSS 与 Atom 使用 SimpleXML 解析，HTTP 优先 cURL、其次 stream。
 *
 * @package YouQuan
 */
class YouQuan_Feed
{
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 YouQuan/1.2 (+https://www.xiguacaizimi.top)';

    /** 单次抓取最大响应体积 */
    const MAX_BODY = 3145728;

    /* ---------------------------------------------------------------------
     * 表与安装
     * ------------------------------------------------------------------ */

    public static function feedsTable()
    {
        $db = Typecho_Db::get();
        return $db->getPrefix() . 'youquan_feeds';
    }

    public static function postsTable()
    {
        $db = Typecho_Db::get();
        return $db->getPrefix() . 'youquan_posts';
    }

    /**
     * 建表（幂等，可重复执行）。
     * @return void
     */
    public static function install()
    {
        $db = Typecho_Db::get();
        $feeds = self::feedsTable();
        $posts = self::postsTable();

        $db->query("CREATE TABLE IF NOT EXISTS `{$feeds}` (
            `fid` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `friend_link_id` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源友链ID',
            `name` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '圈子名称',
            `site_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '站点地址',
            `feed_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'RSS地址',
            `avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像',
            `intro` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '简介',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
            `sort` INT(10) NOT NULL DEFAULT 0 COMMENT '排序',
            `last_fetch` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近抓取时间',
            `last_status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'ok/error/pending',
            `last_error` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '最近错误',
            `etag` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'HTTP ETag',
            `last_modified` VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'HTTP Last-Modified',
            `created` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `updated` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`fid`),
            KEY `idx_status` (`status`),
            KEY `idx_feed_url` (`feed_url`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;", Typecho_Db::WRITE);

        $db->query("CREATE TABLE IF NOT EXISTS `{$posts}` (
            `pid` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `fid` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `guid` CHAR(32) NOT NULL DEFAULT '' COMMENT '条目去重哈希',
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `link` VARCHAR(500) NOT NULL DEFAULT '',
            `summary` TEXT DEFAULT NULL,
            `cover` VARCHAR(500) NOT NULL DEFAULT '',
            `author` VARCHAR(120) NOT NULL DEFAULT '',
            `published` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '原文发布时间',
            `created` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '抓取时间',
            `hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1隐藏',
            PRIMARY KEY (`pid`),
            UNIQUE KEY `uk_fid_guid` (`fid`,`guid`),
            KEY `idx_published` (`published`),
            KEY `idx_fid` (`fid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;", Typecho_Db::WRITE);

        YouQuan_Config::install();
    }

    /**
     * 上传目录（用于刷新锁文件）。
     * @return string
     */
    public static function cacheDir()
    {
        $dir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/youquan';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /* ---------------------------------------------------------------------
     * 圈子（订阅源）
     * ------------------------------------------------------------------ */

    public static function getFeeds($args = array())
    {
        $db = Typecho_Db::get();
        $query = $db->select()->from(self::feedsTable())
            ->order('sort', Typecho_Db::SORT_ASC)
            ->order('fid', Typecho_Db::SORT_ASC);

        if (isset($args['status']) && $args['status'] !== '' && $args['status'] !== null) {
            $query->where('status = ?', (int) $args['status']);
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $args['keyword'] . '%';
            $query->where('(name LIKE ? OR site_url LIKE ? OR feed_url LIKE ?)', $like, $like, $like);
        }
        if (!empty($args['page'])) {
            $query->page(max(1, (int) $args['page']), max(1, (int) ($args['per_page'] ?? 20)));
        }

        return $db->fetchAll($query) ?: array();
    }

    public static function countFeeds($args = array())
    {
        $db = Typecho_Db::get();
        $query = $db->select('COUNT(fid) AS num')->from(self::feedsTable());
        if (isset($args['status']) && $args['status'] !== '' && $args['status'] !== null) {
            $query->where('status = ?', (int) $args['status']);
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $args['keyword'] . '%';
            $query->where('(name LIKE ? OR site_url LIKE ? OR feed_url LIKE ?)', $like, $like, $like);
        }
        $row = $db->fetchRow($query);
        return (int) ($row['num'] ?? 0);
    }

    public static function getActiveFeeds()
    {
        $db = Typecho_Db::get();
        return $db->fetchAll(
            $db->select()->from(self::feedsTable())
                ->where('status = ?', 1)
                ->order('sort', Typecho_Db::SORT_ASC)
                ->order('fid', Typecho_Db::SORT_ASC)
        ) ?: array();
    }

    public static function getFeed($fid)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow($db->select()->from(self::feedsTable())->where('fid = ?', (int) $fid));
    }

    public static function getFeedByUrl($feedUrl)
    {
        $db = Typecho_Db::get();
        $feedUrl = (string) $feedUrl;

        $row = $db->fetchRow(
            $db->select()->from(self::feedsTable())->where('feed_url = ?', $feedUrl)->limit(1)
        );
        if ($row) return $row;

        // 精确匹配不到时，用归一化后的键再比一次（兼容 http/https、结尾斜杠等差异）
        $key = self::feedKey($feedUrl);
        if ($key === '') return null;

        $rows = $db->fetchAll($db->select('fid', 'feed_url')->from(self::feedsTable()));
        foreach ((array) $rows as $item) {
            if (self::feedKey($item['feed_url']) === $key) {
                return $db->fetchRow(
                    $db->select()->from(self::feedsTable())->where('fid = ?', (int) $item['fid'])
                );
            }
        }

        return null;
    }

    public static function getFeedByLinkId($linkId)
    {
        $db = Typecho_Db::get();
        return $db->fetchRow(
            $db->select()->from(self::feedsTable())->where('friend_link_id = ?', (int) $linkId)->limit(1)
        );
    }

    /**
     * 新增或更新圈子。
     * @param array $data
     * @param int $fid
     * @return int 圈子 ID
     */
    public static function saveFeed($data, $fid = 0)
    {
        $db = Typecho_Db::get();
        $table = self::feedsTable();
        $now = time();

        $row = array(
            'name'     => self::cut(trim((string) ($data['name'] ?? '')), 120),
            'site_url' => self::cut(self::normalizeUrl($data['site_url'] ?? ''), 255),
            'feed_url' => self::cut(self::normalizeUrl($data['feed_url'] ?? ''), 255),
            'avatar'   => self::cut(trim((string) ($data['avatar'] ?? '')), 255),
            'intro'    => self::cut(trim((string) ($data['intro'] ?? '')), 255),
            'status'   => (int) ($data['status'] ?? 1) === 0 ? 0 : 1,
            'sort'     => (int) ($data['sort'] ?? 0),
            'updated'  => $now,
        );
        if (isset($data['friend_link_id'])) {
            $row['friend_link_id'] = (int) $data['friend_link_id'];
        }

        $fid = (int) $fid;
        if ($fid > 0) {
            $db->query($db->update($table)->rows($row)->where('fid = ?', $fid));
            return $fid;
        }

        // 新增前再查一次：同一 RSS 的不同写法只保留一条，防止重复抓取
        $exists = self::getFeedByUrl($row['feed_url']);
        if ($exists) {
            $keep = array('updated' => $now);
            if ($row['avatar'] !== '' && (string) $exists['avatar'] === '') $keep['avatar'] = $row['avatar'];
            if ($row['site_url'] !== '' && (string) $exists['site_url'] === '') $keep['site_url'] = $row['site_url'];
            if ($row['friend_link_id'] ?? 0) $keep['friend_link_id'] = (int) $row['friend_link_id'];
            self::updateFeed((int) $exists['fid'], $keep);
            return (int) $exists['fid'];
        }

        $row['created'] = $now;
        $row['last_status'] = 'pending';
        $db->query($db->insert($table)->rows($row));

        $new = self::getFeedByUrl($row['feed_url']);
        return $new ? (int) $new['fid'] : 0;
    }

    public static function updateFeed($fid, array $data)
    {
        if (!$fid || !$data) return;
        $db = Typecho_Db::get();
        $db->query($db->update(self::feedsTable())->rows($data)->where('fid = ?', (int) $fid));
    }

    /**
     * 删除圈子，同时清掉它的文章。
     */
    public static function deleteFeed($fid)
    {
        $fid = (int) $fid;
        if ($fid <= 0) return;
        $db = Typecho_Db::get();
        $db->query($db->delete(self::postsTable())->where('fid = ?', $fid));
        $db->query($db->delete(self::feedsTable())->where('fid = ?', $fid));
    }

    /**
     * 每个圈子的文章数：array(fid => count)
     * @return array
     */
    public static function countsByFeed()
    {
        $db = Typecho_Db::get();
        $table = self::postsTable();
        $rows = $db->fetchAll("SELECT `fid`, COUNT(`pid`) AS num FROM `{$table}` GROUP BY `fid`");
        $map = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $map[(int) $row['fid']] = (int) $row['num'];
            }
        }
        return $map;
    }

    /* ---------------------------------------------------------------------
     * 文章
     * ------------------------------------------------------------------ */

    /**
     * 查询文章。
     * 参数：fid / fids / keyword / hidden / order / page / per_page / include_feeds
     * @return array
     */
    public static function getPosts($args = array())
    {
        $db = Typecho_Db::get();
        $query = $db->select()->from(self::postsTable());

        if (isset($args['fids'])) {
            $fids = array_map('intval', (array) $args['fids']);
            if (!$fids) return array();
            $query->where('fid IN ?', $fids);
        } elseif (!empty($args['fid'])) {
            $query->where('fid = ?', (int) $args['fid']);
        }

        if (isset($args['hidden']) && $args['hidden'] !== '' && $args['hidden'] !== null) {
            $query->where('hidden = ?', (int) $args['hidden']);
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $args['keyword'] . '%';
            $query->where('(title LIKE ? OR summary LIKE ?)', $like, $like);
        }

        $order = ($args['order'] ?? 'published') === 'created' ? 'created' : 'published';
        $query->order($order, Typecho_Db::SORT_DESC)->order('pid', Typecho_Db::SORT_DESC);

        if (!empty($args['page'])) {
            $query->page(max(1, (int) $args['page']), max(1, (int) ($args['per_page'] ?? 12)));
        } elseif (!empty($args['limit'])) {
            $query->limit((int) $args['limit']);
        }

        $rows = $db->fetchAll($query) ?: array();
        if (!empty($args['include_feeds']) && $rows) {
            $rows = self::attachFeeds($rows);
        }
        return $rows;
    }

    public static function countPosts($args = array())
    {
        $db = Typecho_Db::get();
        $query = $db->select('COUNT(pid) AS num')->from(self::postsTable());

        if (isset($args['fids'])) {
            $fids = array_map('intval', (array) $args['fids']);
            if (!$fids) return 0;
            $query->where('fid IN ?', $fids);
        } elseif (!empty($args['fid'])) {
            $query->where('fid = ?', (int) $args['fid']);
        }
        if (isset($args['hidden']) && $args['hidden'] !== '' && $args['hidden'] !== null) {
            $query->where('hidden = ?', (int) $args['hidden']);
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $args['keyword'] . '%';
            $query->where('(title LIKE ? OR summary LIKE ?)', $like, $like);
        }

        $row = $db->fetchRow($query);
        return (int) ($row['num'] ?? 0);
    }

    /**
     * 给文章附加圈子信息（name / site_url / avatar）。
     */
    public static function attachFeeds(array $rows)
    {
        $fids = array();
        foreach ($rows as $row) {
            $fids[(int) $row['fid']] = true;
        }
        if (!$fids) return $rows;

        $db = Typecho_Db::get();
        $feeds = $db->fetchAll(
            $db->select()->from(self::feedsTable())->where('fid IN ?', array_keys($fids))
        ) ?: array();

        $map = array();
        foreach ($feeds as $feed) {
            $map[(int) $feed['fid']] = $feed;
        }
        foreach ($rows as &$row) {
            $feed = $map[(int) $row['fid']] ?? array();
            $row['feed_name'] = $feed['name'] ?? '';
            $row['feed_site'] = $feed['site_url'] ?? '';
            $row['feed_avatar'] = $feed['avatar'] ?? '';
            $row['feed_status'] = (int) ($feed['status'] ?? 1);
        }
        unset($row);
        return $rows;
    }

    public static function deletePosts(array $pids)
    {
        $pids = array_filter(array_map('intval', $pids));
        if (!$pids) return 0;
        $db = Typecho_Db::get();
        $db->query($db->delete(self::postsTable())->where('pid IN ?', $pids));
        return count($pids);
    }

    public static function setPostsHidden(array $pids, $hidden)
    {
        $pids = array_filter(array_map('intval', $pids));
        if (!$pids) return 0;
        $db = Typecho_Db::get();
        $db->query($db->update(self::postsTable())->rows(array('hidden' => $hidden ? 1 : 0))->where('pid IN ?', $pids));
        return count($pids);
    }

    public static function clearPosts($fid = 0)
    {
        $db = Typecho_Db::get();
        $query = $db->delete(self::postsTable());
        if ($fid) {
            $query->where('fid = ?', (int) $fid);
        }
        $db->query($query);
    }

    /**
     * 找出指向同一篇文章的重复记录（同一个源被重复导入时会这样）。
     * @return array link => array(pid, ...)
     */
    public static function duplicateGroups()
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll(
            $db->select('pid', 'fid', 'link')->from(self::postsTable())->order('pid', Typecho_Db::SORT_ASC)
        );

        $map = array();
        foreach ((array) $rows as $row) {
            $key = strtolower(rtrim(trim((string) $row['link']), '/'));
            if ($key === '') continue;
            $map[$key][] = (int) $row['pid'];
        }

        $duplicates = array();
        foreach ($map as $key => $pids) {
            if (count($pids) > 1) $duplicates[$key] = $pids;
        }
        return $duplicates;
    }

    /**
     * 清理重复文章，每条只保留最早抓到的那个。
     * @return int 删除条数
     */
    public static function pruneDuplicates()
    {
        $groups = self::duplicateGroups();
        if (!$groups) return 0;

        $db = Typecho_Db::get();
        $removed = 0;
        foreach ($groups as $pids) {
            array_shift($pids);
            foreach ($pids as $pid) {
                $db->query($db->delete(self::postsTable())->where('pid = ?', (int) $pid));
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * 给缺少头像的圈友补一次站点图标（复用 FriendLinks 的 favicon 缓存）。
     *
     * @param int $limit 单次最多处理多少个
     * @return int 补上的个数
     */
    public static function backfillAvatars($limit = 20)
    {
        if (!class_exists('FriendLinks_Plugin') || !method_exists('FriendLinks_Plugin', 'getWebsiteIcon')) {
            return 0;
        }

        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select()->from(self::feedsTable())->order('fid', Typecho_Db::SORT_ASC));

        $done = 0;
        foreach ((array) $rows as $row) {
            if ($done >= $limit) break;
            if (trim((string) $row['avatar']) !== '') continue;
            if (trim((string) $row['site_url']) === '') continue;

            try {
                $icon = FriendLinks_Plugin::getWebsiteIcon($row['site_url'], true);
            } catch (Exception $e) {
                $icon = '';
            }

            if ($icon) {
                self::updateFeed((int) $row['fid'], array('avatar' => self::cut($icon, 255)));
                $done++;
            }
        }
        return $done;
    }

    /* ---------------------------------------------------------------------
     * 页面
     * ------------------------------------------------------------------ */

    public static function getPage($cid)
    {
        $cid = (int) $cid;
        if ($cid <= 0) return null;
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'status', 'type')
                ->from('table.contents')
                ->where('cid = ?', $cid)
                ->where('type = ?', 'page')
        );
        return $row ?: null;
    }

    public static function getPageBySlug($slug)
    {
        $db = Typecho_Db::get();
        $row = $db->fetchRow(
            $db->select('cid', 'title', 'slug', 'status', 'type')
                ->from('table.contents')
                ->where('slug = ?', (string) $slug)
                ->where('type = ?', 'page')
                ->limit(1)
        );
        return $row ?: null;
    }

    public static function pages()
    {
        $db = Typecho_Db::get();
        return $db->fetchAll(
            $db->select('cid', 'title', 'slug')
                ->from('table.contents')
                ->where('type = ?', 'page')
                ->where('status = ?', 'publish')
                ->order('cid', Typecho_Db::SORT_DESC)
        ) ?: array();
    }

    /**
     * 页面永久链接。
     * @param array $page
     * @return string
     */
    public static function pageUrl($page)
    {
        $options = Helper::options();
        if (class_exists('Typecho_Router')) {
            try {
                $url = Typecho_Router::url(
                    'page',
                    array('cid' => (int) $page['cid'], 'slug' => $page['slug']),
                    $options->index
                );
                if ($url && $url !== '#') return $url;
            } catch (Exception $e) {
                // 路由结构被主题改坏时走兜底
            }
        }
        return Typecho_Common::url($page['slug'] . '.html', $options->index);
    }

    /**
     * 创建「有圈」页面（已存在则直接返回）。
     *
     * 页面本身不需要写短代码：插件在 Widget\Archive 的 beforeRender 阶段接管渲染。
     * 同时写入 ilogs 主题认的两个自定义字段：
     *  - showPage：让页面出现在左侧导航
     *  - iconPage：导航里显示的图标（原始 SVG）
     *
     * @return array|null
     */
    public static function createPage($title = '博友圈', $slug = 'youquan')
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') $slug = 'youquan';

        $existing = self::getPageBySlug($slug);
        if ($existing) {
            self::ensurePageFields((int) $existing['cid']);
            return $existing;
        }

        $db = Typecho_Db::get();
        // Typecho 查询页面时用 created < 请求时间（严格小于），
        // 与请求同一秒创建会被过滤掉导致 404，这里把创建时间往前挪几秒。
        $now = time() - 5;
        $uid = 0;
        try {
            $user = Typecho_Widget::widget('Widget_User');
            if ($user && $user->hasLogin()) $uid = (int) $user->uid;
        } catch (Exception $e) {
            $uid = 0;
        }
        if ($uid <= 0) {
            $first = $db->fetchRow($db->select('uid')->from('table.users')->order('uid', Typecho_Db::SORT_ASC)->limit(1));
            $uid = (int) ($first['uid'] ?? 1);
        }

        $db->query($db->insert('table.contents')->rows(array(
            'title'        => $title !== '' ? $title : '博友圈',
            'slug'         => $slug,
            'created'      => $now,
            'modified'     => $now,
            'text'         => "<!--markdown-->\n\n[youquan]\n",
            'order'        => 0,
            'authorId'     => $uid,
            'template'     => '',
            'type'         => 'page',
            'status'       => 'publish',
            'password'     => null,
            'commentsNum'  => 0,
            'allowComment' => '0',
            'allowPing'    => '0',
            'allowFeed'    => '1',
            'parent'       => 0,
        )));

        $page = self::getPageBySlug($slug);
        if ($page) {
            self::ensurePageFields((int) $page['cid']);
            YouQuan_Config::set('page_cid', (int) $page['cid']);
            YouQuan_Config::set('page_slug', $slug);
        }
        return $page;
    }

    /**
     * 找到（或创建）承载有圈的页面，并补齐主题需要的自定义字段。
     *
     * @param string $title
     * @param string $slug
     * @return array|null
     */
    public static function ensurePage($title = '', $slug = '')
    {
        $title = $title !== '' ? $title : YouQuan_Config::get('title', '博友圈');
        $slug = $slug !== '' ? $slug : YouQuan_Config::get('page_slug', 'youquan');

        // 1. 先按标记字段找（改了别名也能认出来）
        $page = null;
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow(
                $db->select('c.cid', 'c.title', 'c.slug', 'c.status', 'c.type')
                    ->from('table.contents AS c')
                    ->join('table.fields AS f', 'c.cid = f.cid', Typecho_Db::LEFT_JOIN)
                    ->where('f.name = ?', 'youquanHost')
                    ->where('c.type = ?', 'page')
                    ->limit(1)
            );
            if ($row) $page = $row;
        } catch (Exception $e) {
            $page = null;
        }

        // 2. 再按绑定 cid / 别名找
        if (!$page) {
            $page = self::getPage((int) YouQuan_Config::int('page_cid', 0));
        }
        if (!$page) {
            $page = self::getPageBySlug(self::sanitizeSlug($slug));
        }

        if ($page) {
            self::ensurePageFields((int) $page['cid']);
            if ((int) YouQuan_Config::int('page_cid', 0) !== (int) $page['cid']) {
                YouQuan_Config::set('page_cid', (int) $page['cid']);
            }
            return $page;
        }

        return self::createPage($title, $slug);
    }

    /**
     * 读取页面的自定义字段。
     * @param int $cid
     * @return array
     */
    public static function pageFields($cid)
    {
        $cid = (int) $cid;
        if ($cid <= 0) return array();

        $result = array();
        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll(
                $db->select('name', 'str_value')->from('table.fields')->where('cid = ?', $cid)
            );
            foreach ((array) $rows as $row) {
                $result[$row['name']] = $row['str_value'];
            }
        } catch (Exception $e) {
            // fields 表不存在时忽略
        }
        return $result;
    }

    /**
     * 写入单个自定义字段（存在则更新）。
     * @param int $cid
     * @param string $name
     * @param string $value
     * @return void
     */
    public static function setPageField($cid, $name, $value)
    {
        $cid = (int) $cid;
        if ($cid <= 0) return;

        try {
            $db = Typecho_Db::get();
            $exists = $db->fetchRow(
                $db->select('name')->from('table.fields')
                    ->where('cid = ?', $cid)->where('name = ?', $name)->limit(1)
            );

            if ($exists) {
                $db->query($db->update('table.fields')
                    ->rows(array('str_value' => (string) $value, 'type' => 'str'))
                    ->where('cid = ?', $cid)->where('name = ?', $name));
                return;
            }

            $db->query($db->insert('table.fields')->rows(array(
                'cid'         => $cid,
                'name'        => $name,
                'type'        => 'str',
                'str_value'   => (string) $value,
                'int_value'   => 0,
                'float_value' => 0,
            )));
        } catch (Exception $e) {
            // 字段已存在或表结构不支持时忽略
        }
    }

    /**
     * 补齐页面字段：导航显示 + 图标。
     *
     * 已经存在的 showPage / iconPage 不覆盖，避免冲掉用户在主题里改过的设置。
     *
     * @param int $cid
     * @return void
     */
    public static function ensurePageFields($cid)
    {
        $cid = (int) $cid;
        if ($cid <= 0) return;

        $fields = self::pageFields($cid);

        if (!isset($fields['youquanHost'])) {
            self::setPageField($cid, 'youquanHost', '1');
        }
        if (!isset($fields['showPage']) || $fields['showPage'] === '') {
            self::setPageField($cid, 'showPage', '1');
        }
        if (!isset($fields['iconPage']) || trim((string) $fields['iconPage']) === '') {
            self::setPageField($cid, 'iconPage', self::navIcon());
        }
    }

    /**
     * 左侧导航用的 Tabler users 图标。
     * @return string
     */
    public static function navIcon()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>'
            . '<path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/>'
            . '<path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>'
            . '<path d="M16 3.13a4 4 0 0 1 0 7.75"/>'
            . '<path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>'
            . '</svg>';
    }

    public static function sanitizeSlug($slug)
    {
        $slug = trim((string) $slug, " \t\n\r\0\x0B/");
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /* ---------------------------------------------------------------------
     * 抓取
     * ------------------------------------------------------------------ */

    /**
     * 抓取单个圈子。
     * @param int $fid
     * @return array ok / added / message / status
     */
    public static function fetch($fid)
    {
        $feed = self::getFeed($fid);
        if (!$feed) {
            return array('ok' => false, 'added' => 0, 'message' => '圈子不存在');
        }

        $feedUrl = self::normalizeUrl($feed['feed_url']);
        if (!preg_match('#^https?://#i', $feedUrl)) {
            self::updateFeed($fid, array('last_fetch' => time(), 'last_status' => 'error', 'last_error' => 'RSS 地址无效'));
            return array('ok' => false, 'added' => 0, 'message' => 'RSS 地址无效');
        }

        $response = self::request($feedUrl, $feed);
        if ($response['error'] !== '') {
            self::updateFeed($fid, array(
                'last_fetch'  => time(),
                'last_status' => 'error',
                'last_error'  => self::cut($response['error'], 255),
            ));
            return array('ok' => false, 'added' => 0, 'message' => $response['error']);
        }

        if ((int) $response['status'] === 304) {
            self::updateFeed($fid, array('last_fetch' => time(), 'last_status' => 'ok', 'last_error' => ''));
            return array('ok' => true, 'added' => 0, 'message' => '源没有更新（304）');
        }

        if ((int) $response['status'] < 200 || (int) $response['status'] >= 400) {
            $message = '响应异常：HTTP ' . (int) $response['status'];
            self::updateFeed($fid, array('last_fetch' => time(), 'last_status' => 'error', 'last_error' => $message));
            return array('ok' => false, 'added' => 0, 'message' => $message);
        }

        $parsed = self::parse($response['body']);
        if (!$parsed['ok']) {
            self::updateFeed($fid, array(
                'last_fetch'  => time(),
                'last_status' => 'error',
                'last_error'  => self::cut($parsed['message'], 255),
            ));
            return array('ok' => false, 'added' => 0, 'message' => $parsed['message']);
        }

        $update = array(
            'last_fetch'  => time(),
            'last_status' => 'ok',
            'last_error'  => '',
            'updated'     => time(),
        );
        if (!empty($response['etag'])) $update['etag'] = $response['etag'];
        if (!empty($response['modified'])) $update['last_modified'] = $response['modified'];
        if (trim((string) $feed['name']) === '' && $parsed['title'] !== '') {
            $update['name'] = self::cut($parsed['title'], 120);
        }
        if (trim((string) $feed['site_url']) === '' && $parsed['siteUrl'] !== '') {
            $update['site_url'] = self::cut($parsed['siteUrl'], 255);
        }
        self::updateFeed($fid, $update);

        $added = self::storeItems($feed, $parsed['items']);
        self::prune($fid);

        return array(
            'ok'      => true,
            'added'   => $added,
            'total'   => count($parsed['items']),
            'message' => $added > 0 ? ('抓取成功，新增 ' . $added . ' 篇') : '抓取成功，没有新文章',
        );
    }

    /**
     * 写入文章（按 fid + guid 去重）。
     * @param array $feed
     * @param array $items
     * @return int 新增条数
     */
    private static function storeItems(array $feed, array $items)
    {
        if (!$items) return 0;

        $db = Typecho_Db::get();
        $table = self::postsTable();
        $fid = (int) $feed['fid'];

        $existing = $db->fetchAll($db->select('guid')->from($table)->where('fid = ?', $fid)) ?: array();
        $seen = array();
        foreach ($existing as $row) {
            $seen[$row['guid']] = true;
        }

        $keywords = self::excludeKeywords();
        $added = 0;
        $now = time();

        foreach ($items as $item) {
            $title = self::cut($item['title'], 255);
            $link = self::cut($item['link'], 500);
            if ($title === '' || $link === '') continue;
            if (!preg_match('#^https?://#i', $link)) continue;

            $guid = md5($item['guid'] !== '' ? $item['guid'] : ($link . '|' . $title));
            if (isset($seen[$guid])) continue;

            if ($keywords) {
                $skip = false;
                foreach ($keywords as $word) {
                    if ($word !== '' && mb_stripos($title, $word) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;
            }

            $seen[$guid] = true;
            try {
                $db->query($db->insert($table)->rows(array(
                    'fid'       => $fid,
                    'guid'      => $guid,
                    'title'     => $title,
                    'link'      => $link,
                    'summary'   => (string) $item['summary'],
                    'cover'     => self::cut($item['cover'], 500),
                    'author'    => self::cut($item['author'], 120),
                    'published' => (int) $item['published'],
                    'created'   => $now,
                    'hidden'    => 0,
                )));
                $added++;
            } catch (Exception $e) {
                // 唯一索引冲突（并发抓取）时忽略
            }
        }

        return $added;
    }

    /**
     * 按「每个圈子保留条数」清理旧文章。
     */
    private static function prune($fid)
    {
        $keep = max(5, min(200, YouQuan_Config::int('keep_per_feed', 20)));
        $db = Typecho_Db::get();
        $table = self::postsTable();

        $rows = $db->fetchAll(
            $db->select('pid')->from($table)
                ->where('fid = ?', (int) $fid)
                ->order('published', Typecho_Db::SORT_DESC)
                ->order('pid', Typecho_Db::SORT_DESC)
                ->limit(500)
        ) ?: array();

        if (count($rows) <= $keep) return;
        $drop = array();
        for ($i = $keep; $i < count($rows); $i++) {
            $drop[] = (int) $rows[$i]['pid'];
        }
        if ($drop) {
            self::deletePosts($drop);
        }
    }

    private static function excludeKeywords()
    {
        $raw = trim((string) YouQuan_Config::get('exclude_keywords', ''));
        if ($raw === '') return array();
        $parts = preg_split('/[,，\s]+/u', $raw);
        return array_values(array_filter(array_map('trim', $parts), function ($word) {
            return $word !== '';
        }));
    }

    /**
     * 抓取所有过期的圈子（前台访问时触发）。
     * @param int $limit
     * @return int 实际抓取数量
     */
    public static function refreshStale($limit = 2)
    {
        if (!YouQuan_Config::bool('auto_refresh')) return 0;

        $interval = max(5, YouQuan_Config::int('refresh_interval', 30)) * 60;
        $dir = self::cacheDir();
        $lock = $dir . '/refresh.lock';

        // 每分钟最多触发一次，避免访客流量放大抓取压力
        if (is_file($lock) && (time() - (int) @filemtime($lock)) < 60) {
            return 0;
        }
        @touch($lock);

        $feeds = self::getActiveFeeds();
        $done = 0;
        foreach ($feeds as $feed) {
            if ($done >= $limit) break;
            if ((int) $feed['last_fetch'] > time() - $interval) continue;
            self::fetch($feed['fid']);
            $done++;
        }
        return $done;
    }

    /**
     * 在响应结束后异步刷新，不拖慢前台。
     */
    public static function deferAutoRefresh()
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;

        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(40);
            YouQuan_Feed::refreshStale(2);
        });
    }

    /* ---------------------------------------------------------------------
     * HTTP
     * ------------------------------------------------------------------ */

    /**
     * 抓取远程内容。
     * @param string $url
     * @param array|null $feed 已有订阅（用于条件请求）
     * @return array status / body / etag / modified / error
     */
    public static function request($url, $feed = null)
    {
        $timeout = max(3, min(30, YouQuan_Config::int('fetch_timeout', 8)));
        $result = array('status' => 0, 'body' => '', 'etag' => '', 'modified' => '', 'error' => '', 'type' => '');

        $headers = array(
            'User-Agent: ' . self::USER_AGENT,
            'Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, text/html;q=0.7, */*;q=0.5',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.6',
            'Cache-Control: no-cache',
        );
        if (is_array($feed)) {
            if (!empty($feed['etag'])) $headers[] = 'If-None-Match: ' . $feed['etag'];
            if (!empty($feed['last_modified'])) $headers[] = 'If-Modified-Since: ' . $feed['last_modified'];
        }

        if (function_exists('curl_init')) {
            $result = self::requestByCurl($url, $headers, $timeout, true);
            if ($result['error'] !== '' && in_array($result['errno'] ?? 0, array(51, 58, 60, 77), true)) {
                // 部分主机缺少 CA 包，仅对证书链错误做一次不校验重试
                $retry = self::requestByCurl($url, $headers, $timeout, false);
                if ($retry['error'] === '') $result = $retry;
            }
            unset($result['errno']);
            return $result;
        }

        return self::requestByStream($url, $headers, $timeout);
    }

    private static function requestByCurl($url, array $headers, $timeout, $verify)
    {
        $result = array('status' => 0, 'body' => '', 'etag' => '', 'modified' => '', 'error' => '', 'type' => '', 'errno' => 0, 'tooLarge' => false);
        $body = '';

        $ch = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => (bool) $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$result) {
                $length = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                    $result['status'] = (int) $m[1];
                } elseif (stripos($line, 'etag:') === 0) {
                    $result['etag'] = trim(substr($line, 5));
                } elseif (stripos($line, 'last-modified:') === 0) {
                    $result['modified'] = trim(substr($line, 14));
                } elseif (stripos($line, 'content-type:') === 0) {
                    $result['type'] = trim(substr($line, 13));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$body, &$result) {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                    $result['tooLarge'] = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        );
        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $result['errno'] = (int) curl_errno($ch);
        $error = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($ok === false) {
            if ($result['tooLarge']) {
                $result['error'] = '响应内容超过 ' . round(self::MAX_BODY / 1048576, 1) . 'MB，已中止';
            } else {
                $result['error'] = $error !== '' ? ('请求失败：' . $error) : '请求失败';
            }
            return $result;
        }
        if ($result['tooLarge']) {
            $result['error'] = '响应内容超过 ' . round(self::MAX_BODY / 1048576, 1) . 'MB，已中止';
            return $result;
        }

        $result['body'] = $body;
        return $result;
    }

    private static function requestByStream($url, array $headers, $timeout)
    {
        $result = array('status' => 0, 'body' => '', 'etag' => '', 'modified' => '', 'error' => '', 'type' => '');

        $context = stream_context_create(array(
            'http' => array(
                'method'          => 'GET',
                'header'          => implode("\r\n", $headers),
                'timeout'         => $timeout,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ),
            'ssl' => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ),
        ));

        $body = @file_get_contents($url, false, $context, 0, self::MAX_BODY);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                    $result['status'] = (int) $m[1];
                } elseif (stripos($line, 'etag:') === 0) {
                    $result['etag'] = trim(substr($line, 5));
                } elseif (stripos($line, 'last-modified:') === 0) {
                    $result['modified'] = trim(substr($line, 14));
                }
            }
        }

        if ($body === false) {
            $error = error_get_last();
            $result['error'] = '请求失败：' . (isset($error['message']) ? $error['message'] : '无法连接目标站点');
            return $result;
        }
        if (strlen($body) >= self::MAX_BODY) {
            $result['error'] = '响应内容过大，已中止';
            return $result;
        }

        $result['body'] = $body;
        return $result;
    }

    /* ---------------------------------------------------------------------
     * 解析
     * ------------------------------------------------------------------ */

    /**
     * 解析 RSS 2.0 / Atom / RDF。
     * @param string $body
     * @return array ok / title / siteUrl / items / message
     */
    public static function parse($body)
    {
        $result = array('ok' => false, 'title' => '', 'siteUrl' => '', 'items' => array(), 'message' => '');
        $body = self::prepareXml($body);
        if ($body === '') {
            $result['message'] = '响应内容为空';
            return $result;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml) {
            $result['message'] = '无法解析 XML，可能不是有效的 RSS/Atom 地址';
            return $result;
        }

        $root = strtolower($xml->getName());
        $nodes = array();

        if ($root === 'feed') {
            $result['title'] = self::cleanText((string) $xml->title);
            $result['siteUrl'] = self::extractAtomLink($xml, 'alternate');
            if (isset($xml->entry)) {
                foreach ($xml->entry as $entry) {
                    $nodes[] = self::parseAtomEntry($entry);
                }
            }
        } elseif ($root === 'rss' || $root === 'rdf') {
            $channel = isset($xml->channel) ? $xml->channel : $xml;
            $result['title'] = self::cleanText((string) $channel->title);
            $siteUrl = trim((string) $channel->link);
            if ($siteUrl !== '') $result['siteUrl'] = $siteUrl;
            // RSS 1.0（RDF）的 item 与 channel 平级
            $source = $root === 'rdf' ? $xml : $channel;
            if (isset($source->item)) {
                foreach ($source->item as $item) {
                    $nodes[] = self::parseRssItem($item);
                }
            }
        } else {
            $result['message'] = '不是有效的 RSS/Atom 文档（根节点为 ' . $root . '）';
            return $result;
        }

        $items = array();
        foreach ($nodes as $item) {
            if (!$item || $item['link'] === '' || $item['title'] === '') continue;
            $items[] = $item;
        }

        $result['ok'] = true;
        $result['items'] = $items;
        if (!$items) {
            $result['message'] = '源可以访问，但没有解析到文章';
        }
        return $result;
    }

    private static function parseRssItem($item)
    {
        $title = self::cleanText((string) $item->title);
        $link = self::extractItemLink($item);

        $content = '';
        $contentNs = $item->children('http://purl.org/rss/1.0/modules/content/');
        if ($contentNs && isset($contentNs->encoded)) {
            $content = (string) $contentNs->encoded;
        }
        if (trim($content) === '' && isset($item->description)) {
            $content = (string) $item->description;
        }

        $author = '';
        $dc = $item->children('http://purl.org/dc/elements/1.1/');
        if ($dc && isset($dc->creator)) {
            $author = trim((string) $dc->creator);
        }
        if ($author === '' && isset($item->author)) {
            $author = trim((string) $item->author);
        }

        $date = '';
        foreach (array('pubDate', 'published', 'updated', 'date') as $key) {
            if (isset($item->{$key})) {
                $date = trim((string) $item->{$key});
                if ($date !== '') break;
            }
        }
        if ($date === '' && $dc && isset($dc->date)) {
            $date = trim((string) $dc->date);
        }

        $guid = '';
        if (isset($item->guid)) {
            $guid = trim((string) $item->guid);
        }
        if ($guid === '' && isset($item->id)) {
            $guid = trim((string) $item->id);
        }

        return array(
            'title'     => $title,
            'link'      => $link,
            'guid'      => $guid !== '' ? $guid : $link,
            'summary'   => self::makeSummary($content),
            'cover'     => self::extractCover($item, $content),
            'author'    => $author,
            'published' => self::toTimestamp($date),
        );
    }

    private static function parseAtomEntry($entry)
    {
        $title = self::cleanText((string) $entry->title);
        $link = self::extractItemLink($entry);

        $content = '';
        if (isset($entry->content)) {
            $content = (string) $entry->content;
        }
        if (trim($content) === '' && isset($entry->summary)) {
            $content = (string) $entry->summary;
        }

        $author = '';
        if (isset($entry->author->name)) {
            $author = trim((string) $entry->author->name);
        }

        $date = '';
        foreach (array('published', 'updated') as $key) {
            if (isset($entry->{$key})) {
                $date = trim((string) $entry->{$key});
                if ($date !== '') break;
            }
        }

        $guid = isset($entry->id) ? trim((string) $entry->id) : '';

        return array(
            'title'     => $title,
            'link'      => $link,
            'guid'      => $guid !== '' ? $guid : $link,
            'summary'   => self::makeSummary($content),
            'cover'     => self::extractCover($entry, $content),
            'author'    => $author,
            'published' => self::toTimestamp($date),
        );
    }

    private static function extractItemLink($item)
    {
        if (!isset($item->link)) return '';

        // RSS：<link>https://...</link>
        $plain = trim((string) $item->link);
        if ($plain !== '' && preg_match('#^https?://#i', $plain)) {
            return $plain;
        }

        // Atom：<link rel="alternate" href="https://..."/>
        $fallback = '';
        foreach ($item->link as $node) {
            $href = trim((string) $node['href']);
            if ($href === '') continue;
            $rel = strtolower(trim((string) $node['rel']));
            if ($rel === '' || $rel === 'alternate') return $href;
            if ($fallback === '') $fallback = $href;
        }
        if ($fallback !== '') return $fallback;

        if (isset($item->id)) {
            $id = trim((string) $item->id);
            if (preg_match('#^https?://#i', $id)) return $id;
        }
        return '';
    }

    private static function extractAtomLink($feed, $rel = 'alternate')
    {
        if (!isset($feed->link)) return '';
        foreach ($feed->link as $node) {
            $href = trim((string) $node['href']);
            if ($href === '') continue;
            $nodeRel = strtolower(trim((string) $node['rel']));
            if ($nodeRel === '' || $nodeRel === $rel) return $href;
        }
        return '';
    }

    private static function extractCover($item, $html)
    {
        $media = $item->children('http://search.yahoo.com/mrss/');
        if ($media) {
            if (isset($media->thumbnail)) {
                foreach ($media->thumbnail as $node) {
                    $url = trim((string) $node['url']);
                    if ($url !== '') return $url;
                }
            }
            if (isset($media->content)) {
                foreach ($media->content as $node) {
                    $url = trim((string) $node['url']);
                    if ($url === '') continue;
                    $type = strtolower((string) $node['type']);
                    $medium = strtolower((string) $node['medium']);
                    if ($type !== '' && strpos($type, 'image') !== 0 && $medium !== 'image') continue;
                    return $url;
                }
            }
        }

        if (isset($item->enclosure)) {
            $url = trim((string) $item->enclosure['url']);
            $type = strtolower((string) $item->enclosure['type']);
            if ($url !== '' && ($type === '' || strpos($type, 'image') === 0)) return $url;
        }

        if ($html !== '' && preg_match('/<img[^>]+(?:data-src|data-original|src)\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $url = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            if (preg_match('#^https?://#i', $url)) return $url;
        }
        return '';
    }

    /**
     * 生成纯文本摘要。
     */
    public static function makeSummary($html, $length = null)
    {
        $length = $length === null ? max(40, YouQuan_Config::int('summary_len', 110)) : (int) $length;
        $text = (string) $html;
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\[(?:caption|embed|gallery|video|audio|code)[^\]]*\][^[]*\[\/\w+\]/iu', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') return '';
        if (mb_strlen($text, 'UTF-8') > $length) {
            $text = mb_substr($text, 0, $length, 'UTF-8') . '…';
        }
        return $text;
    }

    public static function cleanText($text)
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private static function toTimestamp($date)
    {
        $date = trim((string) $date);
        if ($date === '') return 0;
        $ts = strtotime($date);
        if ($ts === false || $ts < 0) return 0;
        return (int) $ts;
    }

    /**
     * XML 预处理：BOM、编码转换、非法字符清理。
     */
    private static function prepareXml($body)
    {
        $body = (string) $body;
        if (trim($body) === '') return '';

        // 去掉 UTF-8 BOM
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }

        // 非 UTF-8（常见 GBK/GB2312）转换为 UTF-8
        if (!mb_check_encoding($body, 'UTF-8')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', 'GB18030');
            if (is_string($converted) && $converted !== '') {
                $body = $converted;
            }
        }

        // 清理 XML 中不允许的控制字符
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body);

        return trim($body);
    }

    /* ---------------------------------------------------------------------
     * 自动发现 RSS
     * ------------------------------------------------------------------ */

    /**
     * 从站点地址发现 RSS 地址。
     * @param string $siteUrl
     * @return array 候选地址列表
     */
    public static function discover($siteUrl)
    {
        $site = self::normalizeUrl($siteUrl);
        if (!preg_match('#^https?://#i', $site)) return array();

        $found = array();
        $response = self::request($site, null);
        if ($response['error'] === '' && $response['status'] >= 200 && $response['status'] < 400) {
            $html = $response['body'];
            if (preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
                foreach ($matches[0] as $tag) {
                    if (!preg_match('/\brel\s*=\s*["\']?([^"\'>\s]+)/i', $tag, $rel)) continue;
                    if (stripos($rel[1], 'alternate') === false) continue;
                    if (!preg_match('/\btype\s*=\s*["\']?([^"\'>\s]+)/i', $tag, $type)) continue;
                    if (!preg_match('#(rss|atom|xml)#i', $type[1])) continue;
                    if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) continue;
                    $found[] = self::resolveUrl($site, html_entity_decode($href[1], ENT_QUOTES, 'UTF-8'));
                }
            }
            $found = array_values(array_unique(array_filter($found)));
            if ($found) return $found;
        }

        foreach (array('/feed', '/rss.xml', '/atom.xml', '/feed.xml', '/index.php/feed') as $path) {
            $candidate = rtrim($site, '/') . $path;
            $test = self::request($candidate, null);
            if ($test['error'] !== '' || $test['status'] < 200 || $test['status'] >= 400) continue;
            $parsed = self::parse($test['body']);
            if ($parsed['ok'] && count($parsed['items'])) {
                return array($candidate);
            }
        }

        return array();
    }

    /**
     * 相对地址转绝对地址。
     */
    public static function resolveUrl($base, $relative)
    {
        $relative = trim((string) $relative);
        if ($relative === '') return '';
        if (preg_match('#^https?://#i', $relative)) return $relative;
        if (strpos($relative, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $relative;
        }

        $parts = parse_url($base);
        if (!$parts || empty($parts['host'])) return $relative;

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (strpos($relative, '/') === 0) {
            return $origin . $relative;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $origin . $dir . $relative;
    }

    /**
     * 规范化 URL（补协议、去空白）。
     */
    public static function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') return '';
        $url = str_replace(array(' ', "\t", "\n", "\r"), '', $url);
        if (stripos($url, 'feed://') === 0) {
            $url = 'https://' . substr($url, 7);
        }
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && strpos($url, 'mailto:') !== 0) {
            $url = 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * 订阅地址去重键。
     *
     * 同一份 RSS 常被写成 http/https、带不带 www、结尾有没有斜杠、
     * 带不带 index.php 等不同形态，这里归一成统一键用于判重，
     * 避免同一个源被导入两次导致文章重复出现。
     *
     * @param string $url
     * @return string
     */
    public static function feedKey($url)
    {
        $url = self::normalizeUrl($url);
        if ($url === '') return '';

        $parts = @parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $path = preg_replace('#/index\.php#i', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');
        if ($path === '') $path = '/';
        $query = isset($parts['query']) ? '?' . strtolower($parts['query']) : '';

        return $host . $path . $query;
    }

    /**
     * 按字符数截断（UTF-8 安全）。
     */
    public static function cut($text, $length)
    {
        $text = (string) $text;
        if (mb_strlen($text, 'UTF-8') <= $length) return $text;
        return mb_substr($text, 0, $length, 'UTF-8');
    }
}
