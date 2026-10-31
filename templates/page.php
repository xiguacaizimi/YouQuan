<?php
/**
 * 有圈前台页面模板。
 *
 * 由 Widget\Archive 的 beforeRender 钩子调用（见 YouQuan_Render::page）。
 * 外层壳子完全复用 ilogs 主题自己的模板片段，
 * 中间只放博友圈，所以左栏 / 导航 / 右栏 / 页脚都和主题其它页面一致。
 *
 * 每个片段都先判断文件是否存在，主题缺哪个就跳过哪个，不会致命报错。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 主题片段存在才引入。
 *
 * @param string $file 相对主题目录的路径，如 template-parts/header.php
 */
$yqNeed = function ($file) use ($archive, $themeDir) {
    if (is_file($themeDir . $file)) {
        $archive->need($file);
    }
};

$layoutMode = isset($archive->options->layoutMode) ? (string) $archive->options->layoutMode : 'two';
$middleClass = 'col-12 col-md-12 col-lg-' . ($layoutMode === 'one' ? '12' : ($layoutMode === 'three' ? '8' : '9'));
?>
<?php $yqNeed('template-parts/header.php'); ?>
<?php if ($layoutMode !== 'one'): ?>
    <?php $yqNeed('template-parts/left.php'); ?>
<?php endif; ?>
<div class="<?php echo $middleClass; ?>" id="middle">
    <?php $yqNeed('template-parts/nav-menu.php'); ?>

    <div class="yq-page-shell" id="youquan-page">
        <?php echo YouQuan_Render::feed(array(), false); ?>
    </div>
</div>
<?php if ($layoutMode !== 'one'): ?>
    <?php $yqNeed('template-parts/right.php'); ?>
<?php endif; ?>
<?php $yqNeed('template-parts/footer-copyright.php'); ?>
<?php $yqNeed('template-parts/footer.php'); ?>
