<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Feed.php';
require_once __DIR__ . '/Render.php';

/**
 * 有圈 YouQuan —— 把友链的 RSS 聚合成一个「博友圈」页面。
 *
 * 独立页面免短代码渲染并自带导航图标，后台可管理圈友与文章，支持从 FriendLinks 一键导入。
 * 兼容 Typecho 1.1 / 1.2 / 1.3。
 *
 * @package 有圈
 * @author xiguacaizimi
 * @author_email xiguacaizimi@163.com
 * @link https://www.xiguacaizimi.top
 * @version 1.3.0
 * @since 1.2.0
 */
class YouQuan_Plugin implements Typecho_Plugin_Interface
{
    const VERSION = '1.3.0';

    /** @var int 正在由本插件接管渲染的页面 cid（0 表示未接管） */
    private static $ownPageCid = 0;

    /** @var bool 钩子是否已注册，避免重复注册 */
    private static $booted = false;

    /**
     * 启用插件。
     */
    public static function activate()
    {
        try {
            YouQuan_Feed::install();
        } catch (Exception $e) {
            // 建表失败不让启用流程中断（例如账号没有 DDL 权限），进面板可以重试
        } catch (Throwable $e) {
            // PHP 7+ 的非 Exception 错误同理
        }
        // 给 Typecho 的设置页留一份同键名的配置行（否则打开设置页会 500）
        YouQuan_Config::syncTypechoRow();

        // 直接建好承载页面（带导航图标），装完就能用，不需要先写短代码
        try {
            YouQuan_Feed::ensurePage();
        } catch (Exception $e) {
            // 建页失败不影响后台使用，可在面板里重试
        }

        // 后台入口：控制台 → 有圈
        //（1.3 起 panelTable 也改成 JSON，Helper 内部已处理；这里再包一层防止面板注册失败影响启用）
        try {
            Helper::addPanel(1, 'YouQuan/Manage.php', _t('有圈'), _t('管理博友圈订阅与文章'), 'administrator');
        } catch (Exception $e) {
            // 面板注册失败不阻断启用流程
        }

        return _t('有圈已启用，页面与导航图标已自动创建，请进入「控制台 → 有圈」添加圈友或从友链一键导入。');
    }

    /**
     * 停用插件（保留数据）。
     */
    public static function deactivate()
    {
        try {
            Helper::removePanel(1, 'YouQuan/Manage.php');
        } catch (Exception $e) {
            // 面板本就不存在时忽略
        }
        return _t('有圈已停用，订阅与文章数据均已保留。');
    }

    /**
     * 插件设置页。
     *
     * 这里放最常改的几项，保存后写入 youquan_options（与后台面板共用同一份数据）。
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $kv = YouQuan_Config::all();
        $options = Helper::options();
        $panelUrl = Typecho_Common::url('extending.php?panel=YouQuan%2FManage.php', $options->adminUrl);
        $frontUrl = self::shortLink();

        $stats = array('feeds' => 0, 'posts' => 0);
        try {
            $stats['feeds'] = count(YouQuan_Feed::getActiveFeeds());
            $stats['posts'] = YouQuan_Feed::countPosts(array('hidden' => 0));
        } catch (Exception $e) {
            // 数据表还没建好时忽略
        }

        $intro = '<a href="' . htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8') . '">进入有圈管理面板</a>'
            . ' &nbsp;·&nbsp; <a href="' . htmlspecialchars($frontUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">查看前台</a>'
            . ' &nbsp;·&nbsp; 当前 <strong>' . (int) $stats['feeds'] . '</strong> 位圈友，'
            . '<strong>' . (int) $stats['posts'] . '</strong> 篇文章。'
            . '圈友与文章的增删、导入、抓取都在管理面板里。';

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'title',
            null,
            $kv['title'],
            _t('页面大标题'),
            $intro
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'subtitle',
            null,
            $kv['subtitle'],
            _t('标题下的说明文字'),
            _t('留空则不显示。例如「圈友们的最近更新」。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'page_slug',
            null,
            $kv['page_slug'],
            _t('短链接别名'),
            _t('访问 /%s 会跳到有圈页面。只允许字母、数字、下划线和连字符。', $kv['page_slug'])
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'layout',
            array('list' => _t('列表流'), 'grid' => _t('卡片流')),
            $kv['layout'] === 'grid' ? 'grid' : 'list',
            _t('前台布局'),
            _t('列表流与主题文章列表一致；卡片流适合圈友较多时浏览。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'per_page',
            null,
            (string) $kv['per_page'],
            _t('每页文章数'),
            _t('建议 8 – 20。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'auto_refresh',
            array('1' => _t('开启'), '0' => _t('关闭')),
            $kv['auto_refresh'] ? '1' : '0',
            _t('自动刷新'),
            _t('访客打开有圈时，在响应结束后顺带抓取过期订阅，不拖慢页面。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'refresh_interval',
            null,
            (string) $kv['refresh_interval'],
            _t('刷新间隔（分钟）'),
            _t('只对开启自动刷新的源生效。')
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'keep_per_feed',
            null,
            (string) $kv['keep_per_feed'],
            _t('每个圈友保留条数'),
            _t('超出后自动裁剪更早的文章，用来控制数据库体积。')
        ));
    }

    /**
     * 保存插件设置：写入独立的 youquan_options 表。
     *
     * 定义了 configHandle 后 Typecho 不再把设置序列化进 options.plugins，
     * 后台面板与这里改的是同一份数据，不会出现两处不一致。
     *
     * @param array $settings
     * @param bool $isInit
     * @return void
     */
    public static function configHandle(array $settings, $isInit = false)
    {
        $keys = array('title', 'subtitle', 'page_slug', 'per_page', 'layout', 'auto_refresh', 'refresh_interval', 'keep_per_feed');
        $pairs = array();

        foreach ($keys as $key) {
            if (!array_key_exists($key, $settings)) continue;
            $value = $settings[$key];

            if (in_array($key, array('per_page', 'refresh_interval', 'keep_per_feed'), true)) {
                $value = (int) $value;
                if ($value < 1) continue;
                if ($key === 'per_page' && $value > 50) $value = 50;
                if ($key === 'refresh_interval' && $value < 5) $value = 5;
                if ($key === 'keep_per_feed' && $value > 200) $value = 200;
            }

            if ($key === 'layout') {
                $value = $value === 'grid' ? 'grid' : 'list';
            }
            if ($key === 'auto_refresh') {
                $value = ($value === '1' || $value === 1 || $value === true) ? 1 : 0;
            }
            if ($key === 'page_slug') {
                $value = YouQuan_Feed::sanitizeSlug($value);
                if ($value === '') continue;
            }

            $pairs[$key] = $value;
        }

        if ($pairs) {
            YouQuan_Config::setMany($pairs);
        }

        // 让 Typecho 设置页下次打开时读到的是最新值
        YouQuan_Config::syncTypechoRow();
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 注册运行期钩子。
     *
     * 放在插件文件加载阶段注册，这样直接覆盖升级插件文件也能立即生效，
     * 不需要重新停用/启用。
     */
    public static function boot()
    {
        if (self::$booted) return;
        self::$booted = true;

        // 短代码：没有走接管渲染时（非 ilogs 主题）依然可以用 [youquan] 嵌入
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('YouQuan_Plugin', 'parseContent');

        // 独立页面接管：有圈页面不写短代码也能直接显示（对齐图库插件的做法）
        // 两种写法在 Typecho 内部会归一化成同一个 handle，这里保留双写以兼容 1.1
        Typecho_Plugin::factory('Widget\Archive')->beforeRender = array('YouQuan_Plugin', 'beforeRender');
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('YouQuan_Plugin', 'beforeRender');

        // 短链接：/youquan → 有圈页面
        Typecho_Plugin::factory('index.php')->begin = array('YouQuan_Plugin', 'route');
    }

    /**
     * 检查钩子是否已经写进 options.plugins。
     *
     * Typecho 的插件钩子只在「启用那一刻」持久化，运行时并不会加载插件文件；
     * 所以如果升级文件后没有重新启用，新钩子就不会生效 —— 这里用来诊断。
     *
     * @return bool
     */
    public static function hooksRegistered()
    {
        try {
            $export = Typecho_Plugin::export();
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }

        if (!isset($export['handles']) || !is_array($export['handles'])) return false;

        $need = array('Widget_Archive:beforeRender', 'Widget_Abstract_Contents:contentEx');
        foreach ($need as $key) {
            if (empty($export['handles'][$key])) return false;
        }

        return true;
    }

    /**
     * 重新把钩子写入 options.plugins（不动数据、不需要停用再启用）。
     *
     * 用于「升级插件文件后钩子没生效」的情况：Typecho 只在启用时记录钩子，
     * 覆盖升级不会自动补上新增的 beforeRender。
     *
     * @return bool
     */
    public static function reregisterHooks()
    {
        try {
            // 插件文件已被加载过（调用本方法即意味着已加载），钩子此时已注册在内存里
            self::boot();

            if (!method_exists('Typecho_Plugin', 'export')) return false;

            $export = Typecho_Plugin::export();
            if (!isset($export['handles']) || !is_array($export['handles'])) $export['handles'] = array();
            if (!isset($export['activated']) || !is_array($export['activated'])) $export['activated'] = array();

            // 精确写出本插件的三个钩子，不把别的插件的临时句柄带进来
            $ours = array(
                'Widget_Archive:beforeRender' => array(array('YouQuan_Plugin', 'beforeRender')),
                'Widget_Abstract_Contents:contentEx' => array(array('YouQuan_Plugin', 'parseContent')),
                'index.php:begin' => array(array('YouQuan_Plugin', 'route')),
            );

            foreach ($ours as $component => $callbacks) {
                if (!isset($export['handles'][$component]) || !is_array($export['handles'][$component])) {
                    $export['handles'][$component] = array();
                }
                $export['handles'][$component]['0'] = $callbacks[0];
                ksort($export['handles'][$component], SORT_NUMERIC);
            }

            $export['activated']['YouQuan'] = array('handles' => $ours);

            $db = Typecho_Db::get();
            $name = 'plugins';
            $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', $name));

            $encoding = YouQuan_Config::optionsEncoding();
            if ($row) {
                $stored = trim((string) $row['value']);
                if ($stored !== '' && ($stored[0] === '{' || $stored[0] === '[')) $encoding = 'json';
            }

            $value = YouQuan_Config::encodeOptions($export, $encoding);
            if ($row) {
                $db->query($db->update('table.options')->rows(array('value' => $value))->where('name = ?', $name));
            } else {
                $db->query($db->insert('table.options')->rows(array('name' => $name, 'user' => 0, 'value' => $value)));
            }

            return true;
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 独立页面渲染接管。
     *
     * 命中有圈页面时用主题自己的页头 / 左栏 / 导航 / 页脚包一层，
     * 中间输出博友圈，然后 exit —— 这样页面上既不用写短代码，
     * 也不会出现主题的空正文区域。
     *
     * @param mixed $archive
     * @return void
     */
    public static function beforeRender($archive)
    {
        if (!is_object($archive)) return;

        if (!self::isPageArchive($archive)) return;

        $page = self::resolvePage();
        if (!$page) return;

        // 1.3 给核心类加了类型声明，未初始化的类型化属性直接读取会抛 Error，
        // 用 isset() 判断既安全又不会触发 "accessed before initialization"。
        if (!isset($archive->cid)) return;
        if ((int) $archive->cid !== (int) $page['cid']) return;

        // 标记「这一页由本插件自己输出」。主题的 header.php / 摘要函数往往会在
        // 我们输出正文之前先读一次 $this->content，若不加这个标记就会触发
        // contentEx 再渲染一遍：既白白渲染一次，也会让 CSS 文本被当成摘要塞进
        // <meta name="description">，同时把样式标记用掉导致正文没有样式。
        self::$ownPageCid = (int) $page['cid'];

        // 主题不支持这套模板片段时返回 false，交回主题正常渲染（短代码兜底）
        if (!YouQuan_Render::page($archive)) {
            self::$ownPageCid = 0;
            return;
        }

        exit;
    }

    /* ---------------------------------------------------------------------
     * 短代码
     * ------------------------------------------------------------------ */

    /**
     * 内容过滤器：把 [youquan ...] 替换成博友圈列表。
     *
     * @param string $content
     * @param mixed $archive
     * @return string
     */
    public static function parseContent($content, $archive = null)
    {
        if (!is_string($content) || strpos($content, '[youquan') === false) {
            return $content;
        }

        // 这一页正由 templates/page.php 自己输出正文。
        // 注意：Typecho 的 contentEx 在 Archive::execute() 阶段就会执行（早于 beforeRender），
        // 所以不能只靠 $ownPageCid 判断，必须直接认「这是不是有圈承载页」。
        if (self::isHostArchive($archive)) {
            return '';
        }

        // Markdown 会把独占一行的短代码包成 <p><code>[youquan]</code></p>，
        // 先剥掉这层包裹，避免卡片被塞进 code/p 里导致排版错乱。
        $pattern = '\[youquan\b[^\]]*\]';
        $content = preg_replace('/<p>\s*<code>\s*(' . $pattern . ')\s*<\/code>\s*<\/p>/i', '$1', $content);
        $content = preg_replace('/<code>\s*(' . $pattern . ')\s*<\/code>/i', '$1', $content);
        $content = preg_replace('/<p>\s*(' . $pattern . ')\s*<\/p>/i', '$1', $content);

        $matched = false;
        $content = preg_replace_callback('/' . $pattern . '/i', function ($match) use (&$matched) {
            $matched = true;
            return YouQuan_Render::feed(self::parseShortcodeArgs($match[0]), false);
        }, $content);

        return $content;
    }

    /**
     * 判断归档是不是「页面」。
     *
     * 不能只看 is('page')：Typecho 的 Archive::is() 比的是 archiveType，
     * 而 archiveType 要到 render 阶段才解析成 'page'，在 execute 阶段仍是 'index'。
     * 所以优先看内容的 type 字段。
     *
     * @param mixed $archive
     * @return bool
     */
    private static function isPageArchive($archive)
    {
        if (!is_object($archive)) return false;

        if (isset($archive->type) && (string) $archive->type === 'page') return true;

        if (method_exists($archive, 'is')) {
            try {
                if ($archive->is('page')) return true;
            } catch (Exception $e) {
                return false;
            } catch (Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * 判断当前归档是不是「有圈承载页」。
     *
     * @param mixed $archive
     * @return bool
     */
    private static function isHostArchive($archive)
    {
        if (!self::isPageArchive($archive)) return false;

        if (!isset($archive->cid)) return false;
        $cid = (int) $archive->cid;
        if ($cid <= 0) return false;

        $matched = false;

        $bound = (int) YouQuan_Config::int('page_cid', 0);
        if ($bound > 0 && $cid === $bound) {
            $matched = true;
        } else {
            $slug = trim((string) YouQuan_Config::get('page_slug', 'youquan'), '/');
            if ($slug !== '' && isset($archive->slug) && (string) $archive->slug === $slug) {
                $matched = true;
            }
        }

        if (!$matched) return false;

        // 只有确定会接管渲染时才清空正文，主题不支持时保留短代码兜底，页面不会变空
        return YouQuan_Render::themeDirFor($archive) !== '';
    }

    /**
     * 解析短代码属性： [youquan layout="grid" per_page="6" title="博友圈" filter="0" head="1"]
     *
     * @param string $tag
     * @return array
     */
    private static function parseShortcodeArgs($tag)
    {
        $args = array();
        if (preg_match_all('/([a-zA-Z_]+)\s*=\s*["\']([^"\']*)["\']/', $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $item) {
                $args[strtolower($item[1])] = trim($item[2]);
            }
        }

        $map = array(
            'per_page'    => 'per_page',
            'limit'       => 'per_page',
            'layout'      => 'layout',
            'title'       => 'title',
            'filter'      => 'show_filter',
            'head'        => 'show_head',
            'src'         => 'fid',
            'order'       => 'order',
        );

        $result = array();
        foreach ($map as $key => $target) {
            if (!isset($args[$key]) || $args[$key] === '') continue;
            $value = $args[$key];
            if (in_array($target, array('show_filter', 'show_head'), true)) {
                $value = ($value === '0' || strtolower($value) === 'false' || strtolower($value) === 'no') ? false : true;
            } elseif ($target === 'per_page' || $target === 'fid') {
                $value = (int) $value;
            }
            $result[$target] = $value;
        }
        return $result;
    }

    /* ---------------------------------------------------------------------
     * 短链接路由
     * ------------------------------------------------------------------ */

    /**
     * 拦截 /{page_slug} 并把访客送到有圈页面。
     */
    public static function route()
    {
        $slug = trim((string) YouQuan_Config::get('page_slug', 'youquan'), '/');
        if ($slug === '') return;

        $path = self::relativePath();
        if ($path === null) return;
        if (strcasecmp($path, '/' . $slug) !== 0) return;

        $page = self::resolvePage();

        if ($page) {
            self::redirect(self::pageTarget($page));
        }

        // 页面还没建：管理员访问时自动创建，其他访客给出提示
        if (self::isAdmin()) {
            $page = YouQuan_Feed::ensurePage(YouQuan_Config::get('title', '博友圈'), $slug);
            if ($page) {
                self::redirect(self::pageTarget($page));
            }
        }

        self::noticePage();
    }

    /**
     * 解析当前请求相对于站点根的路径（如 /youquan）。
     * @return string|null
     */
    private static function relativePath()
    {
        if (empty($_SERVER['REQUEST_URI'])) return null;

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!$path) return null;
        $path = rawurldecode($path);

        try {
            $options = Helper::options();
            $sitePath = rtrim((string) parse_url($options->siteUrl, PHP_URL_PATH), '/');
        } catch (Exception $e) {
            $sitePath = '';
        }

        if ($sitePath !== '' && stripos($path, $sitePath) === 0) {
            $path = substr($path, strlen($sitePath));
        }
        $path = preg_replace('#^/index\.php#i', '', $path);
        $path = '/' . trim($path, '/');

        return $path;
    }

    /**
     * 取已绑定的页面，未绑定则按 slug 查找。
     * @return array|null
     */
    public static function resolvePage()
    {
        $cid = YouQuan_Config::int('page_cid', 0);
        if ($cid > 0) {
            $page = YouQuan_Feed::getPage($cid);
            if ($page) return $page;
        }

        $slug = trim((string) YouQuan_Config::get('page_slug', 'youquan'), '/');
        if ($slug === '') return null;

        $page = YouQuan_Feed::getPageBySlug($slug);
        if ($page) {
            YouQuan_Config::set('page_cid', (int) $page['cid']);
        }
        return $page ?: null;
    }

    /**
     * 短链接地址（含站点根）。
     * @return string
     */
    public static function shortLink()
    {
        $slug = trim((string) YouQuan_Config::get('page_slug', 'youquan'), '/');
        if ($slug === '') $slug = 'youquan';
        // 站点开了 rewrite 时 /youquan，否则 /index.php/youquan
        if (Helper::options()->rewrite && Helper::options()->rewrite === '1') {
            return Typecho_Common::url($slug, Helper::options()->siteUrl);
        }
        return Typecho_Common::url('index.php/' . $slug, Helper::options()->siteUrl);
    }

    /**
     * 页面地址。
     * @return string
     */
    public static function pageTarget($page)
    {
        $target = YouQuan_Feed::pageUrl($page);
        if (!empty($_SERVER['REQUEST_URI'])) {
            $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            if ($query) {
                $target .= (strpos($target, '?') === false ? '?' : '&') . $query;
            }
        }
        return $target;
    }

    private static function redirect($url)
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
        } else {
            echo '<script>location.replace(' . json_encode($url) . ');</script>';
        }
        exit;
    }

    /**
     * 页面不存在时的提示页。
     */
    private static function noticePage()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8', true, 404);
        }
        $adminUrl = Typecho_Common::url('extending.php?panel=YouQuan%2FManage.php', Helper::options()->adminUrl);
        $siteName = htmlspecialchars(Helper::options()->title, ENT_QUOTES, 'UTF-8');
        $loginUrl = Typecho_Common::url('login.php', Helper::options()->adminUrl);
        $isLogin = self::isAdmin();

        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>博友圈 - ' . $siteName . '</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f2f1f6;font-family:"Microsoft JhengHei",-apple-system,"PingFang SC",sans-serif;color:#212529}'
            . '.box{max-width:420px;margin:24px;padding:32px;background:#fff;border-radius:12px;'
            . 'box-shadow:0 6px 24px rgba(31,45,61,.08);text-align:center}'
            . 'h1{font-size:18px;font-weight:600;margin:0 0 10px}p{font-size:13.5px;line-height:1.8;color:#6b7280;margin:0 0 18px}'
            . 'a{display:inline-block;padding:8px 18px;border-radius:8px;background:#0d6efd;color:#fff;'
            . 'font-size:13px;text-decoration:none}a:hover{background:#0b5ed7}</style></head><body><div class="box">'
            . '<h1>博友圈页面还没有创建</h1>'
            . '<p>短链接已经生效，但对应的有圈页面不存在。请到后台「控制台 → 有圈 → 设置」点击「创建有圈页面」。</p>'
            . '<a href="' . htmlspecialchars($isLogin ? $adminUrl : $loginUrl, ENT_QUOTES, 'UTF-8') . '">'
            . ($isLogin ? '前往有圈后台' : '登录后台') . '</a>'
            . '</div></body></html>';
        exit;
    }

    /**
     * 当前访客是否管理员。
     * @return bool
     */
    public static function isAdmin()
    {
        try {
            $user = Typecho_Widget::widget('Widget_User');
            return $user && $user->hasLogin() && $user->pass('administrator', true);
        } catch (Exception $e) {
            return false;
        }
    }
}

// 载入即注册钩子（覆盖升级后无需重新启用插件）
if (class_exists('Typecho_Plugin')) {
    YouQuan_Plugin::boot();
}

/*
 * 主题模板标签：在主题模板里写 youquan() 即可直接输出博友圈，
 * 也可以传参：youquan(array('layout' => 'grid'));
 */
if (!function_exists('youquan')) {
    /**
     * 在主题模板中直接输出博友圈。
     * @param array $args
     * @return void
     */
    function youquan($args = array())
    {
        echo YouQuan_Render::feed($args, false);
    }
}

if (!function_exists('youquan_url')) {
    /**
     * 有圈短链接。
     * @return string
     */
    function youquan_url()
    {
        return YouQuan_Plugin::shortLink();
    }
}
