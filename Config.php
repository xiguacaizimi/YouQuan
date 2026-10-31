<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 有圈配置读写。
 *
 * 配置统一存放在独立 KV 表 youquan_options 中，后台管理页可直接保存，
 * 不依赖 Typecho 插件配置的序列化结构，升级/迁移都不会丢配置。
 *
 * @package YouQuan
 */
class YouQuan_Config
{
    /** @var array|null 进程内配置缓存 */
    private static $data = null;

    /**
     * 全局默认配置。
     * @return array
     */
    public static function defaults()
    {
        return array(
            // 安装标记
            'db_version'        => '',
            // 页面
            'page_cid'          => 0,
            'page_slug'         => 'youquan',
            'title'             => '博友圈',
            'subtitle'          => '',
            'hide_page_title'   => 1,
            // 展示
            'per_page'          => 12,
            'layout'            => 'list',
            'summary_len'       => 110,
            'show_avatar'       => 1,
            'show_source'       => 1,
            'show_time'         => 1,
            'show_cover'        => 1,
            'show_filter'       => 1,
            'show_head'         => 1,
            'time_format'       => 'relative',
            'order'             => 'published',
            'target_blank'      => 1,
            // 抓取
            'auto_refresh'      => 1,
            'refresh_interval'  => 30,
            'fetch_timeout'     => 8,
            'keep_per_feed'     => 20,
            'exclude_keywords'  => '',
        );
    }

    /**
     * 配置表名。
     * @return string
     */
    public static function table()
    {
        $db = Typecho_Db::get();
        return $db->getPrefix() . 'youquan_options';
    }

    /**
     * 建表（幂等）。
     * @return void
     */
    public static function install()
    {
        $db = Typecho_Db::get();
        $table = self::table();
        $db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `name` VARCHAR(64) NOT NULL COMMENT '配置键',
            `value` TEXT DEFAULT NULL COMMENT '配置值',
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;", Typecho_Db::WRITE);
    }

    /**
     * 读取全部配置（默认值 + 数据库值）。
     * @return array
     */
    public static function all()
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $data = self::defaults();
        try {
            $db = Typecho_Db::get();
            $rows = $db->fetchAll($db->select()->from(self::table()));
            foreach ($rows as $row) {
                if (array_key_exists($row['name'], $data)) {
                    $data[$row['name']] = $row['value'];
                }
            }
        } catch (Exception $e) {
            // 表尚未建立时使用默认值，避免前台白屏。
        }

        self::$data = $data;
        return self::$data;
    }

    /**
     * 读取单个配置。
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $all = self::all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }
        if ($default !== null) {
            return $default;
        }
        $defaults = self::defaults();
        return array_key_exists($key, $defaults) ? $defaults[$key] : null;
    }

    /**
     * 读取整数配置。
     * @param string $key
     * @param int $default
     * @return int
     */
    public static function int($key, $default = 0)
    {
        $value = self::get($key, $default);
        return (int) $value;
    }

    /**
     * 读取布尔配置。
     * @param string $key
     * @return bool
     */
    public static function bool($key)
    {
        $value = self::get($key, '0');
        return $value === '1' || $value === 1 || $value === true || $value === 'on';
    }

    /**
     * 写入单个配置。
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set($key, $value)
    {
        self::setMany(array($key => $value));
    }

    /**
     * 批量写入配置。
     * @param array $pairs
     * @return void
     */
    public static function setMany(array $pairs)
    {
        $db = Typecho_Db::get();
        $table = self::table();

        foreach ($pairs as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = (string) $value;

            $exists = $db->fetchRow($db->select('name')->from($table)->where('name = ?', $key));
            if ($exists) {
                $db->query($db->update($table)->rows(array('value' => $value))->where('name = ?', $key));
            } else {
                $db->query($db->insert($table)->rows(array('name' => $key, 'value' => $value)));
            }
        }

        self::$data = null;
    }

    /**
     * 清除进程缓存（测试或外部写入后使用）。
     * @return void
     */
    public static function flush()
    {
        self::$data = null;
    }

    /* ---------------------------------------------------------------------
     * 与 Typecho 插件设置页的协同
     * ------------------------------------------------------------------ */

    /**
     * 交给 Typecho 插件设置页的键值对。
     *
     * 键名必须与 Plugin::config() 里的输入项一一对应 —— 因为定义了 configHandle，
     * Typecho 不会再帮我们写 options.plugins，但它渲染设置页时仍会读 plugin:YouQuan
     * 并逐个 getInput(键名)，键名对不上会直接 500。
     *
     * @return array
     */
    public static function typechoSettings()
    {
        return array(
            'title'            => (string) self::get('title', '博友圈'),
            'subtitle'         => (string) self::get('subtitle', ''),
            'page_slug'        => (string) self::get('page_slug', 'youquan'),
            'layout'           => self::get('layout', 'list') === 'grid' ? 'grid' : 'list',
            'per_page'         => (string) self::int('per_page', 12),
            'auto_refresh'     => self::bool('auto_refresh') ? '1' : '0',
            'refresh_interval' => (string) self::int('refresh_interval', 30),
            'keep_per_feed'    => (string) self::int('keep_per_feed', 20),
        );
    }

    /**
     * Typecho 当前的 options 表用哪种格式存数组。
     *
     * Typecho 1.3 起把 plugin:xxx、theme:xxx、plugins 等字段从 PHP serialize() 改成 JSON，
     * 直接读写数据库的代码必须跟着改（官方 1.3 迁移文档明确要求）。
     *
     * @return string 'json' | 'php'
     */
    public static function optionsEncoding()
    {
        $version = '';
        if (class_exists('\Typecho\Common') && defined('\Typecho\Common::VERSION')) {
            $version = (string) constant('\Typecho\Common::VERSION');
        } elseif (class_exists('Typecho_Common') && defined('Typecho_Common::VERSION')) {
            $version = (string) constant('Typecho_Common::VERSION');
        }

        return ($version !== '' && version_compare($version, '1.3.0', '>=')) ? 'json' : 'php';
    }

    /**
     * 把配置数组编码成 options 表需要的格式。
     *
     * @param array  $settings
     * @param string $encoding json | php，留空则按当前 Typecho 版本判断
     * @return string
     */
    public static function encodeOptions(array $settings, $encoding = '')
    {
        if ($encoding === '') $encoding = self::optionsEncoding();

        if ($encoding === 'json') {
            $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // json_encode 失败时退回 serialize，别把整行写成空
            return $json === false ? serialize($settings) : $json;
        }

        return serialize($settings);
    }

    /**
     * 把当前配置整体写入 Typecho 的 options 表（name = plugin:YouQuan）。
     *
     * 整体覆盖而不是合并：从旧版本升级上来时，那行里可能还留着早已删除的
     * installedVersion 之类的键，留着会让设置页在 getInput() 时取到 null 而 500。
     *
     * @return void
     */
    public static function syncTypechoRow()
    {
        try {
            $db = Typecho_Db::get();
            $name = 'plugin:YouQuan';

            $exists = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', $name));

            // 已有行优先沿用它的格式（1.3 升级程序会把旧行转成 JSON），否则按版本判断
            $encoding = self::optionsEncoding();
            if ($exists) {
                $stored = trim((string) $exists['value']);
                if ($stored !== '' && ($stored[0] === '{' || $stored[0] === '[')) {
                    $encoding = 'json';
                }
            }

            $value = self::encodeOptions(self::typechoSettings(), $encoding);

            if ($exists) {
                $db->query($db->update('table.options')->rows(array('value' => $value))->where('name = ?', $name));
            } else {
                $db->query($db->insert('table.options')->rows(array('name' => $name, 'value' => $value, 'user' => 0)));
            }
        } catch (Exception $e) {
            // 写不进去也不影响插件本身，只是 Typecho 设置页会提示配置未找到
        }
    }
}
