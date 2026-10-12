<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 插件设置管理类。
 */
class WC_OISP_Settings
{
    const OPTION_KEY = 'wc_oisp_settings';

    private static $defaults = [
        'enable_column'         => 'yes',
        'show_thumbnail'        => 'yes',
        'enable_hover_zoom'     => 'yes',
        'items_collapse_limit'  => 3,
        'default_expand_address'=> 'no',
        'highlight_customer_note'=> 'yes',
        'show_sku_and_id'       => 'yes',
        'show_item_price'       => 'yes',
        'address_copy_format'   => 'international', // international, standard_cn, compact
    ];

    /**
     * 初始化挂钩。
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu'], 60);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_order_item_specs', [__CLASS__, 'render_wc_settings_tab']);
        add_action('woocommerce_update_options_order_item_specs', [__CLASS__, 'save_wc_settings_tab']);
    }

    /**
     * 获取所有配置（合并默认值）。
     */
    public static function get_all()
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, self::$defaults);
    }

    /**
     * 获取单个配置项。
     */
    public static function get($key, $default = null)
    {
        $all = self::get_all();
        if (isset($all[$key])) {
            return $all[$key];
        }
        return $default ?? (self::$defaults[$key] ?? null);
    }

    /**
     * 在 WooCommerce 菜单下注册子菜单。
     */
    public static function register_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('订单商品规格预览设置', 'wc-order-item-specs-preview'),
            __('商品规格与发货预览', 'wc-order-item-specs-preview'),
            'manage_woocommerce',
            'wc-order-item-specs-preview',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * 在 WooCommerce 设置标签页追加标签。
     */
    public static function add_settings_tab($tabs)
    {
        $tabs['order_item_specs'] = __('商品规格预览', 'wc-order-item-specs-preview');
        return $tabs;
    }

    /**
     * 渲染 WC 设置标签页。
     */
    public static function render_wc_settings_tab()
    {
        self::render_settings_form(admin_url('admin.php?page=wc-settings&tab=order_item_specs'));
    }

    /**
     * 保存 WC 设置标签页。
     */
    public static function save_wc_settings_tab()
    {
        self::handle_save_post();
    }

    /**
     * 注册原生 WordPress 设置。
     */
    public static function register_settings()
    {
        register_setting(self::OPTION_KEY . '_group', self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);
    }

    /**
     * 数据清洗。
     */
    public static function sanitize_settings($input)
    {
        if (!is_array($input)) {
            return self::$defaults;
        }

        $clean = [];
        $clean['enable_column']          = (!empty($input['enable_column']) && $input['enable_column'] === 'yes') ? 'yes' : 'no';
        $clean['show_thumbnail']         = (!empty($input['show_thumbnail']) && $input['show_thumbnail'] === 'yes') ? 'yes' : 'no';
        $clean['enable_hover_zoom']      = (!empty($input['enable_hover_zoom']) && $input['enable_hover_zoom'] === 'yes') ? 'yes' : 'no';
        $clean['items_collapse_limit']   = max(0, min(20, intval($input['items_collapse_limit'] ?? 3)));
        $clean['default_expand_address'] = (!empty($input['default_expand_address']) && $input['default_expand_address'] === 'yes') ? 'yes' : 'no';
        $clean['highlight_customer_note']= (!empty($input['highlight_customer_note']) && $input['highlight_customer_note'] === 'yes') ? 'yes' : 'no';
        $clean['show_sku_and_id']        = (!empty($input['show_sku_and_id']) && $input['show_sku_and_id'] === 'yes') ? 'yes' : 'no';
        $clean['show_item_price']        = (!empty($input['show_item_price']) && $input['show_item_price'] === 'yes') ? 'yes' : 'no';

        $valid_formats = ['international', 'standard_cn', 'compact'];
        $clean['address_copy_format']    = in_array($input['address_copy_format'] ?? '', $valid_formats, true) ? $input['address_copy_format'] : 'international';

        return $clean;
    }

    /**
     * 处理直接提交保存。
     */
    private static function handle_save_post()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!empty($_POST['wc_oisp_save_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_oisp_save_nonce'])), 'wc_oisp_save_action')) {
            $raw = isset($_POST[self::OPTION_KEY]) && is_array($_POST[self::OPTION_KEY]) ? wp_unslash($_POST[self::OPTION_KEY]) : [];
            $clean = self::sanitize_settings($raw);
            update_option(self::OPTION_KEY, $clean);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('设置已成功保存！', 'wc-order-item-specs-preview') . '</p></div>';
        }
    }

    /**
     * 渲染完整设置页面。
     */
    public static function render_settings_page()
    {
        self::handle_save_post();
        echo '<div class="wrap" style="max-width: 900px;">';
        echo '<h1 style="display:flex; align-items:center; gap:8px;">📦 ' . esc_html__('WooCommerce 订单商品规格与发货明细预览设置', 'wc-order-item-specs-preview') . '</h1>';
        echo '<p style="color: #666; margin-bottom: 20px;">' . esc_html__('在后台订单列表中直观展示商品图片、规格型号、数量、SKU、收件人地址与买家留言警示，极大简化拣货打单发货流程。', 'wc-order-item-specs-preview') . '</p>';
        self::render_settings_form(admin_url('admin.php?page=wc-order-item-specs-preview'));
        echo '</div>';
    }

    /**
     * 渲染表单内部 HTML。
     */
    public static function render_settings_form($action_url)
    {
        $settings = self::get_all();
        ?>
        <form method="post" action="<?php echo esc_url($action_url); ?>">
            <?php wp_nonce_field('wc_oisp_save_action', 'wc_oisp_save_nonce'); ?>

            <div class="card" style="margin-top: 15px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border-radius: 6px; border: 1px solid #e2e8f0; background: #fff;">
                <h2 style="font-size: 16px; margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #edf2f7; color: #1e293b;">
                    <?php esc_html_e('商品明细展示选项', 'wc-order-item-specs-preview'); ?>
                </h2>

                <table class="form-table" role="presentation" style="margin-top: 10px;">
                    <tr>
                        <th scope="row"><?php esc_html_e('启用明细主列', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_column]" value="yes" <?php checked($settings['enable_column'], 'yes'); ?>>
                                <?php esc_html_e('在订单列表显示「商品规格与发货」专属列', 'wc-order-item-specs-preview'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('开启后将在订单列表中以直观的 ERP 风格呈现订单中所有商品。', 'wc-order-item-specs-preview'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('商品缩略图与放大镜', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <label style="display:block; margin-bottom: 8px;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_thumbnail]" value="yes" <?php checked($settings['show_thumbnail'], 'yes'); ?>>
                                <?php esc_html_e('显示商品缩略图（48×48px，优先展示变体属性对应的图片）', 'wc-order-item-specs-preview'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_hover_zoom]" value="yes" <?php checked($settings['enable_hover_zoom'], 'yes'); ?>>
                                <?php esc_html_e('开启鼠标悬停图片弹出大图预览（无需点击进入即可看清细节）', 'wc-order-item-specs-preview'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('商品货号与单价展示', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <label style="display:block; margin-bottom: 8px;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_sku_and_id]" value="yes" <?php checked($settings['show_sku_and_id'], 'yes'); ?>>
                                <?php esc_html_e('显示商品 ID / 规格编号 / SKU 货号及一键复制图标', 'wc-order-item-specs-preview'); ?>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_item_price]" value="yes" <?php checked($settings['show_item_price'], 'yes'); ?>>
                                <?php esc_html_e('显示商品单价与小计金额', 'wc-order-item-specs-preview'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('多商品折叠限制', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <input type="number" min="0" max="20" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[items_collapse_limit]" value="<?php echo esc_attr($settings['items_collapse_limit']); ?>" style="width: 80px;">
                            <span style="margin-left: 8px;"><?php esc_html_e('件商品后自动折叠（设为 0 表示不折叠全部展开，默认 3 件）', 'wc-order-item-specs-preview'); ?></span>
                            <p class="description"><?php esc_html_e('超过设定件数时将在底部显示“展开其余 N 件商品”，防止包含大量商品的订单将表格拉得过长。', 'wc-order-item-specs-preview'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card" style="margin-top: 20px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); border-radius: 6px; border: 1px solid #e2e8f0; background: #fff;">
                <h2 style="font-size: 16px; margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #edf2f7; color: #1e293b;">
                    <?php esc_html_e('发货辅助与打单选项', 'wc-order-item-specs-preview'); ?>
                </h2>

                <table class="form-table" role="presentation" style="margin-top: 10px;">
                    <tr>
                        <th scope="row"><?php esc_html_e('买家留言醒目警示', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[highlight_customer_note]" value="yes" <?php checked($settings['highlight_customer_note'], 'yes'); ?>>
                                <?php esc_html_e('若订单有买家留言，以黄色警示框高亮突出显示（提醒发货人员注意定制或包装要求）', 'wc-order-item-specs-preview'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('发货地址默认状态', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_expand_address]" value="yes" <?php checked($settings['default_expand_address'], 'yes'); ?>>
                                <?php esc_html_e('列表加载时默认展开详细收件地址（不勾选则默认折叠，点击按钮瞬间展开）', 'wc-order-item-specs-preview'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('地址一键复制格式', 'wc-order-item-specs-preview'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address_copy_format]" value="international" <?php checked($settings['address_copy_format'], 'international'); ?>>
                                    <strong><?php esc_html_e('跨境电商国际标准格式', 'wc-order-item-specs-preview'); ?></strong>
                                    <span style="color: #64748b; font-size: 12px; display:block; margin-left: 24px;">Name / Phone / Street Address / City, State Zipcode / Country</span>
                                </label>
                                <label style="display:block; margin-bottom: 8px;">
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address_copy_format]" value="standard_cn" <?php checked($settings['address_copy_format'], 'standard_cn'); ?>>
                                    <strong><?php esc_html_e('国内标准打单发货格式', 'wc-order-item-specs-preview'); ?></strong>
                                    <span style="color: #64748b; font-size: 12px; display:block; margin-left: 24px;">收件人：张三 | 电话：138... | 地址：广东省深圳市... | 邮编：518000</span>
                                </label>
                                <label style="display:block;">
                                    <input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[address_copy_format]" value="compact" <?php checked($settings['address_copy_format'], 'compact'); ?>>
                                    <strong><?php esc_html_e('紧凑单行格式', 'wc-order-item-specs-preview'); ?></strong>
                                    <span style="color: #64748b; font-size: 12px; display:block; margin-left: 24px;">姓名, 电话, 详细地址, 城市/省份, 邮编, 国家</span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large" style="padding: 4px 20px;">
                    <?php esc_html_e('保存设置', 'wc-order-item-specs-preview'); ?>
                </button>
            </p>
        </form>
        <?php
    }
}
