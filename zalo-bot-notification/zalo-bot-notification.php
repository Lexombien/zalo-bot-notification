<?php
/**
 * Plugin Name: Zalo Bot Notification
 * Description: Gửi thông báo đơn hàng WooCommerce về Zalo Bot khi trạng thái đơn hàng thay đổi
 * Version: 1.0.0
 * Author: Lexombien
 * Author URI: https://github.com/Lexombien/zalo-bot-notification
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zalo-bot-notification
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WZB_VERSION', '1.0.0');
define('WZB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WZB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WZB_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WZB_';
    $base_len = strlen($prefix);
    
    if (strncmp($prefix, $class, $base_len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $base_len);
    $file = WZB_PLUGIN_DIR . 'includes/class-wzb-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
function wzb_init() {
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        add_action('admin_notices', 'wzb_woocommerce_missing_notice');
        return;
    }
    
    // Declare HPOS compatibility (High-Performance Order Storage)
    add_action('before_woocommerce_init', function() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WZB_PLUGIN_BASENAME, true);
        }
    });

    // Initialize classes
    WZB_Settings::init();
    WZB_Order_Handler::init();
    WZB_Webhook::init(); // Initialize webhook handler
}
add_action('plugins_loaded', 'wzb_init');

function wzb_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('Zalo Bot Notification yêu cầu WooCommerce phải được cài đặt và kích hoạt.', 'zalo-bot-notification'); ?></p>
    </div>
    <?php
}

// Add Settings link to plugin list
add_filter('plugin_action_links_' . WZB_PLUGIN_BASENAME, 'wzb_add_action_links');
function wzb_add_action_links($links) {
    $settings_link = '<a href="admin.php?page=wzb-settings" style="font-weight:bold;">⚙️ Cài đặt</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add Version Info link to plugin row meta
add_filter('plugin_row_meta', 'wzb_add_row_meta_links', 10, 2);
function wzb_add_row_meta_links($links, $file) {
    if ($file === WZB_PLUGIN_BASENAME) {
        $links[] = '<a href="https://github.com/Lexombien/zalo-bot-notification/releases" target="_blank">📜 Lịch sử phiên bản</a>';
    }
    return $links;
}

// Activation hook
register_activation_hook(__FILE__, 'wzb_activate');
function wzb_activate() {
    $default_options = array(
        'bot_token' => '',
        'chat_id' => '',
        'webhook_url' => '',
        'secret_token' => wp_generate_password(32, false),
        'enabled_statuses' => array('processing', 'completed', 'cancelled', 'failed'),
        'message_template' => "🛒 === ĐƠN HÀNG MỚI ===\n👤 Người nhận: {customer_name}\n📞 SĐT nhận: {billing_phone}\n📍 Địa chỉ: {full_address}\n━━━━━━━━━━━━━━\n💳 PTTT: {payment_method}\n🚚 PTVC: {shipping_method}\n━━━━━━━━━━━━━━\n💰Tổng tiền: {order_total}\n⏰ Thời gian: {order_datetime}"
    );
    
    $existing = get_option('wzb_settings');
    if (!$existing) {
        update_option('wzb_settings', $default_options);
    }
}
// Custom Plugin Row Meta
add_filter('plugin_row_meta', function($links, $file) {
    if (strpos($file, basename(__FILE__)) !== false) {
        $new_links = array(
            'history' => '<a href="https://github.com/Lexombien/zalo-bot-notification" target="_blank">Lịch sử phiên bản</a>'
        );
        return $new_links;
    }
    return $links;
}, 10, 2);
