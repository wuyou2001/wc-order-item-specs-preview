<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 订单列表自定义明细列核心引擎。
 * 兼容 HPOS (High-Performance Order Storage) 与传统 CPT (shop_order)。
 */
class WC_OISP_Order_Column
{
    const COLUMN_KEY = 'wc_order_item_specs';

    public static function init()
    {
        // 传统 CPT 模式订单列表列注册
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'register_column'], 20);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_cpt_column'], 20, 2);

        // HPOS 模式订单列表列注册
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'register_column'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_hpos_column'], 20, 2);

        // 加载管理端样式与脚本
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * 判断当前是否处于 WooCommerce 订单列表页面。
     */
    public static function is_order_list_screen()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }

        // 传统 CPT
        if ($screen->id === 'edit-shop_order' || ($screen->base === 'edit' && $screen->post_type === 'shop_order')) {
            return true;
        }

        // HPOS
        if ($screen->id === 'woocommerce_page_wc-orders') {
            return true;
        }

        return false;
    }

    /**
     * 加载专属 CSS 与 JS 脚本。
     */
    public static function enqueue_assets($hook)
    {
        if (!self::is_order_list_screen()) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $version = defined('WC_OISP_VERSION') ? WC_OISP_VERSION : '1.0.0';

        wp_enqueue_style(
            'wc-oisp-admin-style',
            $plugin_url . 'assets/css/admin-order-preview.css',
            [],
            $version
        );

        wp_enqueue_script(
            'wc-oisp-admin-script',
            $plugin_url . 'assets/js/admin-order-preview.js',
            ['jquery'],
            $version,
            true
        );

        wp_localize_script('wc-oisp-admin-script', 'wcOispI18n', [
            'copied'         => __('已复制到剪贴板', 'wc-order-item-specs-preview'),
            'copyFailed'     => __('复制失败，请手动选择复制', 'wc-order-item-specs-preview'),
            'expandAddress'  => __('展开 ▼', 'wc-order-item-specs-preview'),
            'collapseAddress'=> __('收起 ▲', 'wc-order-item-specs-preview'),
            'expandItems'    => __('展开其余 %d 件商品 ▼', 'wc-order-item-specs-preview'),
            'collapseItems'  => __('收起多余商品 ▲', 'wc-order-item-specs-preview'),
        ]);
    }

    /**
     * 注册/插入自定义列。
     */
    public static function register_column($columns)
    {
        if (WC_OISP_Settings::get('enable_column') !== 'yes') {
            return $columns;
        }

        $new_columns = [];
        $inserted = false;

        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            // 优先插入在 order_number 之后
            if ($key === 'order_number') {
                $new_columns[self::COLUMN_KEY] = __('商品规格与发货明细', 'wc-order-item-specs-preview');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new_columns[self::COLUMN_KEY] = __('商品规格与发货明细', 'wc-order-item-specs-preview');
        }

        return $new_columns;
    }

    /**
     * 渲染传统 CPT 模式列。
     */
    public static function render_cpt_column($column, $post_id)
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $order = wc_get_order($post_id);
        if ($order) {
            self::render_order_cell($order);
        }
    }

    /**
     * 渲染 HPOS 模式列。
     */
    public static function render_hpos_column($column, $order_or_id)
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $order = is_numeric($order_or_id) ? wc_get_order($order_or_id) : $order_or_id;
        if ($order instanceof WC_Order) {
            self::render_order_cell($order);
        }
    }

    /**
     * 核心输出单元格内容。
     */
    public static function render_order_cell(WC_Order $order)
    {
        $settings = WC_OISP_Settings::get_all();
        $items = $order->get_items('line_item');
        $item_count = count($items);

        if ($item_count === 0) {
            echo '<span class="wc-oisp-empty">' . esc_html__('无商品明细', 'wc-order-item-specs-preview') . '</span>';
            return;
        }

        $collapse_limit = intval($settings['items_collapse_limit']);
        $need_collapse = ($collapse_limit > 0 && $item_count > $collapse_limit);
        $currency = $order->get_currency();

        echo '<div class="wc-oisp-container" data-order-id="' . esc_attr($order->get_id()) . '">';

        // 1. 商品列表区
        echo '<div class="wc-oisp-items-list">';
        $index = 0;
        foreach ($items as $item_id => $item) {
            $index++;
            $is_hidden = ($need_collapse && $index > $collapse_limit);
            self::render_single_item($item, $order, $settings, $currency, $is_hidden);
        }
        echo '</div>';

        // 多商品折叠切换按钮
        if ($need_collapse) {
            $remaining = $item_count - $collapse_limit;
            echo '<button type="button" class="wc-oisp-toggle-items-btn" data-remaining="' . esc_attr($remaining) . '">';
            /* translators: %d: number of remaining items */
            echo sprintf(esc_html__('展开其余 %d 件商品 ▼', 'wc-order-item-specs-preview'), $remaining);
            echo '</button>';
        }

        // 2. 买家留言高亮警示框
        $customer_note = trim($order->get_customer_note());
        if ($settings['highlight_customer_note'] === 'yes' && !empty($customer_note)) {
            echo '<div class="wc-oisp-customer-note">';
            echo '<span class="wc-oisp-note-tag">⚠️ ' . esc_html__('买家留言', 'wc-order-item-specs-preview') . '</span>';
            echo '<span class="wc-oisp-note-content">' . esc_html($customer_note) . '</span>';
            echo '</div>';
        }

        // 3. 发货与收件地址信息展开卡片
        self::render_shipping_section($order, $settings);

        echo '</div>'; // .wc-oisp-container
    }

    /**
     * 渲染单件商品明细行（按 ERP 视觉层级优化排版）。
     */
    private static function render_single_item(WC_Order_Item_Product $item, WC_Order $order, array $settings, $currency, $is_hidden = false)
    {
        $product = $item->get_product();
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $quantity = $item->get_quantity();
        $item_total = $order->get_item_subtotal($item, true);

        // 获取商品缩略图与大图
        $thumb_url = '';
        $full_url = '';
        if ($settings['show_thumbnail'] === 'yes') {
            $img_id = 0;
            if ($product) {
                $img_id = $product->get_image_id();
            }
            if ($img_id) {
                $thumb_url = wp_get_attachment_image_url($img_id, 'thumbnail');
                $full_url  = wp_get_attachment_image_url($img_id, 'large') ?: $thumb_url;
            }
            if (empty($thumb_url)) {
                $thumb_url = wc_placeholder_img_src('thumbnail');
                $full_url  = $thumb_url;
            }
        }

        // 获取规格属性
        $specs = self::get_item_specifications($item, $product);

        // 获取 SKU
        $sku = $product ? $product->get_sku() : '';

        $row_class = 'wc-oisp-item-row';
        if ($is_hidden) {
            $row_class .= ' wc-oisp-item-collapsed';
        }

        echo '<div class="' . esc_attr($row_class) . '">';

        // 1. 左侧缩略图
        if ($settings['show_thumbnail'] === 'yes' && !empty($thumb_url)) {
            echo '<div class="wc-oisp-thumb-box" data-zoom-src="' . esc_url($full_url) . '">';
            echo '<img src="' . esc_url($thumb_url) . '" alt="' . esc_attr($item->get_name()) . '" class="wc-oisp-thumb" loading="lazy" />';
            echo '</div>';
        }

        // 2. 右侧信息主体
        echo '<div class="wc-oisp-item-meta">';

        // === 第 1 行：商品标题 + 规格属性标签 ===
        echo '<div class="wc-oisp-line wc-oisp-line-1">';
        $edit_url = $product_id ? admin_url('post.php?post=' . $product_id . '&action=edit') : '';
        if ($edit_url && current_user_can('edit_products')) {
            echo '<a href="' . esc_url($edit_url) . '" class="wc-oisp-title" target="_blank" title="' . esc_attr($item->get_name()) . '">' . esc_html($item->get_name()) . '</a>';
        } else {
            echo '<span class="wc-oisp-title" title="' . esc_attr($item->get_name()) . '">' . esc_html($item->get_name()) . '</span>';
        }

        if (!empty($specs)) {
            echo '<span class="wc-oisp-specs-badge">';
            echo '<span class="wc-oisp-specs-lbl">' . esc_html__('规格:', 'wc-order-item-specs-preview') . '</span> ';
            $spec_parts = [];
            foreach ($specs as $spec_name => $spec_val) {
                $spec_parts[] = esc_html($spec_name) . ': ' . esc_html($spec_val);
            }
            echo esc_html(implode(' | ', $spec_parts));
            echo '</span>';
        }
        echo '</div>'; // .wc-oisp-line-1

        // === 第 2 行：ID、规格编号、SKU、单价与数量高亮 ===
        echo '<div class="wc-oisp-line wc-oisp-line-2">';
        echo '<div class="wc-oisp-line-2-left">';

        if ($settings['show_sku_and_id'] === 'yes') {
            if ($product_id) {
                echo '<span class="wc-oisp-meta-chip wc-oisp-copyable" data-copy="' . esc_attr($product_id) . '" title="' . esc_attr__('点击复制商品ID', 'wc-order-item-specs-preview') . '">';
                echo '<span class="wc-oisp-chip-lbl">' . esc_html__('ID:', 'wc-order-item-specs-preview') . '</span>' . esc_html($product_id);
                echo ' <span class="wc-oisp-copy-btn-icon" aria-hidden="true">❐</span>';
                echo '</span>';
            }
            if ($variation_id) {
                echo '<span class="wc-oisp-meta-chip wc-oisp-copyable" data-copy="' . esc_attr($variation_id) . '" title="' . esc_attr__('点击复制规格编号', 'wc-order-item-specs-preview') . '">';
                echo '<span class="wc-oisp-chip-lbl">' . esc_html__('规格号:', 'wc-order-item-specs-preview') . '</span>' . esc_html($variation_id);
                echo ' <span class="wc-oisp-copy-btn-icon" aria-hidden="true">❐</span>';
                echo '</span>';
            }
            if (!empty($sku)) {
                echo '<span class="wc-oisp-meta-chip wc-oisp-sku-chip wc-oisp-copyable" data-copy="' . esc_attr($sku) . '" title="' . esc_attr__('点击复制SKU', 'wc-order-item-specs-preview') . '">';
                echo '<span class="wc-oisp-chip-lbl">SKU:</span>' . esc_html($sku);
                echo ' <span class="wc-oisp-copy-btn-icon" aria-hidden="true">❐</span>';
                echo '</span>';
            }
        }

        if ($settings['show_item_price'] === 'yes') {
            echo '<span class="wc-oisp-meta-chip wc-oisp-price-chip">';
            echo '<span class="wc-oisp-chip-lbl">' . esc_html__('单价:', 'wc-order-item-specs-preview') . '</span>' . wc_price($item_total, ['currency' => $currency]);
            echo '</span>';
        }

        echo '</div>'; // .wc-oisp-line-2-left

        // 数量高亮 * 1
        echo '<div class="wc-oisp-line-2-right">';
        echo '<span class="wc-oisp-qty-bold" title="' . esc_attr__('购买数量', 'wc-order-item-specs-preview') . '">* ' . esc_html($quantity) . '</span>';
        echo '</div>';

        echo '</div>'; // .wc-oisp-line-2
        echo '</div>'; // .wc-oisp-item-meta
        echo '</div>'; // .wc-oisp-item-row
    }

    /**
     * 提取订单项规格属性（过滤内部私有元数据）。
     */
    private static function get_item_specifications(WC_Order_Item_Product $item, $product)
    {
        $specs = [];

        // 1. 优先提取变体真实规格属性（如 Color, Size 等）
        if ($product && $product instanceof WC_Product_Variation) {
            $attributes = $product->get_variation_attributes();
            if (!empty($attributes)) {
                foreach ($attributes as $attr_name => $attr_value) {
                    if ($attr_value !== '') {
                        $clean_name = str_replace(['attribute_pa_', 'attribute_'], '', $attr_name);
                        $taxonomy = 'pa_' . $clean_name;
                        $label = wc_attribute_label($taxonomy, $product);
                        if (taxonomy_exists($taxonomy)) {
                            $term = get_term_by('slug', $attr_value, $taxonomy);
                            $val = $term ? $term->name : $attr_value;
                        } else {
                            $val = $attr_value;
                        }
                        $specs[$label] = $val;
                    }
                }
            }
        }

        // 2. 提取公共订单项自定义元数据（严格过滤以 _ 开头的私有系统元数据及第三方插件内部键名）
        $formatted_meta = $item->get_formatted_meta_data('_');
        if (!empty($formatted_meta)) {
            foreach ($formatted_meta as $meta) {
                $raw_key = $meta->key ?? '';
                $display_key = $meta->display_key ?? '';

                // 严格忽略任何下划线开头的私有元数据，以及已知的第三方插件内部键名（如 _wdp_cart_item_key）
                if (empty($raw_key) || strpos($raw_key, '_') === 0 || strpos($display_key, '_') === 0) {
                    continue;
                }
                if (preg_match('/^(wdp_|yw_|wc_|tm_|composite_)/i', $raw_key)) {
                    continue;
                }

                $key = wp_strip_all_tags($display_key);
                $value = wp_strip_all_tags($meta->display_value);
                if (strpos($key, 'pa_') === 0) {
                    $key = wc_attribute_label($key);
                }
                if (!empty($key) && !empty($value) && !isset($specs[$key])) {
                    $specs[$key] = $value;
                }
            }
        }

        return $specs;
    }

    /**
     * 渲染发货与收件地址信息折叠/展开区域。
     */
    private static function render_shipping_section(WC_Order $order, array $settings)
    {
        $is_default_expanded = ($settings['default_expand_address'] === 'yes');

        // 收件人信息（优先配送地址，兜底账单地址）
        $name = trim($order->get_formatted_shipping_full_name());
        if (empty($name)) {
            $name = trim($order->get_formatted_billing_full_name());
        }
        if (empty($name)) {
            $name = __('未提供收件人', 'wc-order-item-specs-preview');
        }

        $phone = $order->get_billing_phone();
        $shipping_address_1 = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $shipping_address_2 = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $city               = $order->get_shipping_city() ?: $order->get_billing_city();
        $state              = $order->get_shipping_state() ?: $order->get_billing_state();
        $postcode           = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $country_code       = $order->get_shipping_country() ?: $order->get_billing_country();
        $countries          = WC()->countries ? WC()->countries->get_countries() : [];
        $country_name       = isset($countries[$country_code]) ? $countries[$country_code] : $country_code;

        $shipping_method = $order->get_shipping_method();

        // 构建纯文本用于一键复制
        $format = $settings['address_copy_format'];
        $copy_text = self::build_copy_address_text($format, [
            'name'       => $name,
            'phone'      => $phone,
            'address_1'  => $shipping_address_1,
            'address_2'  => $shipping_address_2,
            'city'       => $city,
            'state'      => $state,
            'postcode'   => $postcode,
            'country'    => $country_name,
            'order_id'   => $order->get_order_number(),
        ]);

        echo '<div class="wc-oisp-shipping-wrapper">';
        echo '<div class="wc-oisp-shipping-bar">';
        echo '<div class="wc-oisp-shipping-toggle-group">';
        echo '<span class="wc-oisp-label">' . esc_html__('地址信息:', 'wc-order-item-specs-preview') . '</span> ';
        echo '<button type="button" class="wc-oisp-toggle-address-btn" aria-expanded="' . ($is_default_expanded ? 'true' : 'false') . '">';
        echo '<span class="wc-oisp-address-label">' . ($is_default_expanded ? esc_html__('收起 ▲', 'wc-order-item-specs-preview') : esc_html__('展开 ▼', 'wc-order-item-specs-preview')) . '</span>';
        echo '</button>';
        echo '</div>'; // .wc-oisp-shipping-toggle-group

        echo '<button type="button" class="wc-oisp-copy-address-btn" data-copy="' . esc_attr($copy_text) . '" title="' . esc_attr__('一键复制发货地址', 'wc-order-item-specs-preview') . '">';
        echo '<span class="wc-oisp-copy-btn-icon" aria-hidden="true">❐</span> ' . esc_html__('复制地址', 'wc-order-item-specs-preview');
        echo '</button>';
        echo '</div>'; // .wc-oisp-shipping-bar

        $drawer_class = 'wc-oisp-address-drawer';
        if (!$is_default_expanded) {
            $drawer_class .= ' wc-oisp-drawer-collapsed';
        }

        echo '<div class="' . esc_attr($drawer_class) . '">';
        echo '<div class="wc-oisp-address-card">';

        echo '<div class="wc-oisp-addr-row">';
        echo '<span class="wc-oisp-addr-item"><span class="wc-oisp-addr-lbl">' . esc_html__('收件人:', 'wc-order-item-specs-preview') . '</span> <strong class="wc-oisp-addr-val">' . esc_html($name) . '</strong></span>';
        if (!empty($phone)) {
            echo '<span class="wc-oisp-addr-item"><span class="wc-oisp-addr-lbl">' . esc_html__('电话:', 'wc-order-item-specs-preview') . '</span> <a href="tel:' . esc_attr($phone) . '" class="wc-oisp-phone-link">' . esc_html($phone) . '</a></span>';
        }
        if (!empty($shipping_method)) {
            echo '<span class="wc-oisp-addr-item wc-oisp-shipping-badge"><span class="wc-oisp-addr-lbl">' . esc_html__('配送方式:', 'wc-order-item-specs-preview') . '</span> <span class="wc-oisp-addr-val">' . esc_html($shipping_method) . '</span></span>';
        }
        echo '</div>';

        echo '<div class="wc-oisp-addr-row wc-oisp-full-address">';
        echo '<span class="wc-oisp-addr-lbl">' . esc_html__('详细地址:', 'wc-order-item-specs-preview') . '</span> ';
        $addr_parts = array_filter([$shipping_address_1, $shipping_address_2, $city, $state, $postcode, $country_name]);
        echo '<span class="wc-oisp-addr-val">' . esc_html(implode(', ', $addr_parts)) . '</span>';
        echo '</div>';

        echo '</div>'; // .wc-oisp-address-card
        echo '</div>'; // .wc-oisp-address-drawer
        echo '</div>'; // .wc-oisp-shipping-wrapper
    }

    /**
     * 生成格式化打单文本。
     */
    private static function build_copy_address_text($format, array $data)
    {
        $full_addr = trim($data['address_1'] . ' ' . $data['address_2']);

        if ($format === 'standard_cn') {
            $lines = [
                '收件人：' . $data['name'],
                '电话：' . ($data['phone'] ?: '无'),
                '地址：' . trim($data['country'] . ' ' . $data['state'] . ' ' . $data['city'] . ' ' . $full_addr),
                '邮编：' . ($data['postcode'] ?: '无'),
            ];
            return implode("\n", $lines);
        }

        if ($format === 'compact') {
            return sprintf(
                '%s, %s, %s, %s, %s, %s, %s',
                $data['name'],
                $data['phone'] ?: 'N/A',
                $full_addr,
                $data['city'],
                $data['state'],
                $data['postcode'],
                $data['country']
            );
        }

        // 默认 international
        $lines = [];
        $lines[] = $data['name'];
        if (!empty($data['phone'])) {
            $lines[] = $data['phone'];
        }
        if (!empty($full_addr)) {
            $lines[] = $full_addr;
        }
        $city_state_zip = trim($data['city'] . ', ' . $data['state'] . ' ' . $data['postcode']);
        if (!empty($city_state_zip)) {
            $lines[] = $city_state_zip;
        }
        if (!empty($data['country'])) {
            $lines[] = $data['country'];
        }

        return implode("\n", $lines);
    }
}
