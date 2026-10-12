<?php

/**
 * Plugin Name: WooCommerce 订单商品规格与发货明细预览
 * Description: 在 WooCommerce 订单列表直观展示每个订单的商品缩略图、规格属性、数量、SKU货号及可折叠的一键复制发货地址与买家留言高亮警示，完美兼容 HPOS 与传统订单模式，支持 GitHub 自动静默更新。
 * Version: 1.0.2
 * Author: Wwnine
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 9.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 单点真实源读取版本号。
 */
$plugin_meta = get_file_data(__FILE__, ['Version' => 'Version']);
define('WC_OISP_VERSION', !empty($plugin_meta['Version']) ? trim($plugin_meta['Version']) : '1.0.0');
define('WC_OISP_FILE', __FILE__);
define('WC_OISP_PATH', plugin_dir_path(__FILE__));
define('WC_OISP_URL', plugin_dir_url(__FILE__));

/**
 * 声明 WooCommerce HPOS (High-Performance Order Storage) 兼容性。
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * 静默集成 GitHub 自动更新器（无设置卡片，纯后台运行）。
 */
if (is_admin()) {
    require_once WC_OISP_PATH . 'includes/class-github-updater.php';
    new WP_GitHub_Plugin_Updater(WC_OISP_FILE, WC_OISP_VERSION, 'wuyou2001/wc-order-item-specs-preview');
}

/**
 * 加载核心业务类。
 */
require_once WC_OISP_PATH . 'includes/class-settings.php';
require_once WC_OISP_PATH . 'includes/class-order-column.php';

/**
 * 初始化插件。
 */
add_action('plugins_loaded', function () {
    // 校验 WooCommerce 是否启用
    if (!class_exists('WooCommerce')) {
        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning is-dismissible"><p>' .
                    esc_html__('「WooCommerce 订单商品规格与发货明细预览」依赖于 WooCommerce 插件，请先安装并启用 WooCommerce。', 'wc-order-item-specs-preview') .
                    '</p></div>';
            });
        }
        return;
    }

    // 初始化设置与订单列
    WC_OISP_Settings::init();
    WC_OISP_Order_Column::init();
});

/**
 * 插件列表快速设置链接。
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-order-item-specs-preview')) . '">' . esc_html__('设置', 'wc-order-item-specs-preview') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
