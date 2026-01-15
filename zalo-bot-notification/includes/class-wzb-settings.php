<?php
/**
 * Settings page handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WZB_Settings {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'load_admin_assets'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('parse_request', array(__CLASS__, 'handle_webhook_request')); // Short Webhook URL handler
        
        // AJAX handlers (Keep for backward compatibility)
        add_action('wp_ajax_wzb_test_connection', array(__CLASS__, 'ajax_test_connection'));
        add_action('wp_ajax_wzb_setup_webhook', array(__CLASS__, 'ajax_setup_webhook'));
        add_action('wp_ajax_wzb_regenerate_secret', array(__CLASS__, 'ajax_regenerate_secret'));
        add_action('wp_ajax_wzb_get_chat_id', array(__CLASS__, 'ajax_get_chat_id'));
        add_action('wp_ajax_wzb_clear_debug_log', array(__CLASS__, 'ajax_clear_debug_log'));
        add_action('wp_ajax_wzb_check_webhook_info', array(__CLASS__, 'ajax_check_webhook_info'));
        add_action('wp_ajax_wzb_delete_webhook', array(__CLASS__, 'ajax_delete_webhook'));
        add_action('wp_ajax_wzb_get_sample_order_meta', array(__CLASS__, 'ajax_get_sample_order_meta'));
    }

    public static function add_menu_page() {
        add_menu_page(
            __('Zalo Bot Notification', 'zalo-bot-notification'),
            __('Zalo Bot Notification', 'zalo-bot-notification'),
            'manage_options',
            'zalo-bot-notification',
            array(__CLASS__, 'render_settings_page'),
            'dashicons-format-chat',
            56
        );
    }

    public static function load_admin_assets($hook) {
        // Load assets only on plugin page
        if (strpos($hook, 'zalo-bot-notification') === false) {
            return;
        }
        
        wp_enqueue_style('wzb-admin-css', WZB_PLUGIN_URL . 'assets/css/admin.css', array(), WZB_VERSION);
        wp_enqueue_script('wzb-admin-js', WZB_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), time(), true);
        
        wp_localize_script('wzb-admin-js', 'wzbAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wzb-admin-nonce')
        ));
    }
    
    public static function handle_webhook_request($wp) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['wzb-webhook']) && $_GET['wzb-webhook'] == '1') {
             // Validate token inside handle_request or here if needed, but WZB_Webhook usually handles validation.
             // We just forward the request.
             
             // Ensure this runs only once
             if (!defined('WZB_WEBHOOK_RUNNING')) {
                 define('WZB_WEBHOOK_RUNNING', true);
                 
                 // Include Webhook Handler if not loaded (though spl_autoload should handle it)
                 if (!class_exists('WZB_Webhook')) {
                     require_once WZB_PLUGIN_DIR . 'includes/class-wzb-webhook.php';
                 }
                 
                 WZB_Webhook::handle_request();
                 exit;
             }
        }
    }
    
    public static function register_settings() {
        // We handle saving manually to ensure data integrity (JSON/UTF-8).
        // Disabling standard registration prevents WP from double-sanitizing or interfering.
    }
    
    public static function sanitize_settings($input) {
        // BYPASS: If input is a string (e.g. Base64 from manual update_option), return it as is.
        // This prevents WP from trying to sanitize our encoded string as an array.
        if (is_string($input)) {
            return $input;
        }
        
        $sanitized = array();
        
        $sanitized['bot_token'] = sanitize_text_field($input['bot_token'] ?? '');
        $sanitized['webhook_url'] = esc_url_raw($input['webhook_url'] ?? ''); 
        $sanitized['chat_id'] = sanitize_text_field($input['chat_id'] ?? '');
        $sanitized['secret_token'] = sanitize_text_field($input['secret_token'] ?? '');
        
        if (isset($input['enabled_statuses']) && is_array($input['enabled_statuses'])) {
            $sanitized['enabled_statuses'] = array_map('sanitize_text_field', $input['enabled_statuses']);
        } else {
            $sanitized['enabled_statuses'] = array();
        }

        // Allow some HTML in message template
        $sanitized['message_template'] = wp_kses_post($input['message_template'] ?? '');
        
        if (isset($input['custom_fields']) && is_array($input['custom_fields'])) {
             $sanitized['custom_fields'] = array_map('sanitize_text_field', $input['custom_fields']);
        } else {
             $sanitized['custom_fields'] = array();
        }
        
        $sanitized['enable_debug'] = isset($input['enable_debug']) ? 1 : 0;
        
        return $sanitized;
    }
    
    public static function get_settings_safe() {
        $raw = get_option('wzb_settings');
        
        if (empty($raw)) return array();
        
        // 1. Array (if accidentally saved as array)
        if (is_array($raw)) return $raw;
        
        // 2. Try JSON (Primary Storage)
        if (is_string($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) return $json;
            
            // 3. Fallback to Serialize (Legacy)
            $serialized = maybe_unserialize($raw);
            if (is_array($serialized)) return $serialized;
        }
        
        return array();
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // --- MANUAL SAVE LOGIC (JSON STRING) ---
        if (isset($_POST['wzb_save_settings']) && $_POST['wzb_save_settings'] == '1') {
            
            $log_content = "=== SAVE ATTEMPT (JSON STRING): " . wp_date('Y-m-d H:i:s') . " ===\n";
            
            if (check_admin_referer('wzb_save_settings_action', 'wzb_save_nonce')) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $input = isset($_POST['wzb_settings']) ? wp_unslash($_POST['wzb_settings']) : array();
                $sanitized = self::sanitize_settings($input);
                
                // SAVE AS JSON STRING (Safe for DB, supports Emoji)
                $json_data = wp_json_encode($sanitized);
                
                $updated = update_option('wzb_settings', $json_data);
                
                if ($updated) {
                    $log_content .= "Update Option: SUCCESS\n";
                    add_settings_error('wzb_messages', 'wzb_message', 'Đã lưu cài đặt thành công! 🎉', 'updated');
                } else {
                     $current_db = get_option('wzb_settings');
                     // Compare
                     if ($current_db === $json_data) {
                         $log_content .= "Update Option: NO CHANGE\n";
                         add_settings_error('wzb_messages', 'wzb_message', 'Dữ liệu không thay đổi.', 'notice-info');
                     } else {
                         // Force update
                         delete_option('wzb_settings');
                         add_option('wzb_settings', $json_data);
                         add_settings_error('wzb_messages', 'wzb_message', 'Đã lưu (Force Save)!', 'updated');
                     }
                }
                
            } else {
                add_settings_error('wzb_messages', 'wzb_message', 'Lỗi bảo mật Nonce.', 'error');
            }
            
            file_put_contents(WZB_PLUGIN_DIR . 'wzb-save-debug.log', "\xEF\xBB\xBF" . $log_content, FILE_APPEND);
        }
        // -------------------------
        
        $settings = self::get_settings_safe();
        
        // Defaults
        $bot_token = $settings['bot_token'] ?? '';
        $chat_id = $settings['chat_id'] ?? '';
        $secret_token = $settings['secret_token'] ?? wp_generate_password(32, false);
        $enabled_statuses = $settings['enabled_statuses'] ?? array();
        $message_template = $settings['message_template'] ?? '';
        
        // Webhook URL: Use saved or generate default SHORT URL
        $webhook_url = $settings['webhook_url'] ?? '';
        if (empty($webhook_url)) {
            // New Short URL Format
            $webhook_url = home_url('/?wzb-webhook=1&token=' . $secret_token);
        }

        $order_statuses = wc_get_order_statuses();
        
        // Set default template if empty
        if (empty($message_template)) {
            $message_template = "🛒 === ĐƠN HÀNG MỚI ===\n👤 Người nhận: {customer_name}\n📞 SĐT nhận: {billing_phone}\n📍 Địa chỉ: {full_address}\n━━━━━━━━━━━━━━\n💳 PTTT: {payment_method}\n🚚 PTVC:{shipping_method}\n━━━━━━━━━━━━━━\n💰Tổng tiền: {order_total}\n⏰ Thời gian: {order_datetime}";
        }

        $custom_fields = $settings['custom_fields'] ?? array();
        $enable_debug = $settings['enable_debug'] ?? false;
        
        ?>
        <div class="wrap wzb-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('wzb_messages'); ?>

            <div class="wzb-container">
                <div class="wzb-main-content">
                    <form method="post" action="">
                        <?php wp_nonce_field('wzb_save_settings_action', 'wzb_save_nonce'); ?>
                        <input type="hidden" name="wzb_save_settings" value="1">
                        
                        <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
                            <?php submit_button('💾 Lưu cài đặt', 'primary', 'submit_top', false); ?>
                        </div>
                        
                        <!-- Connection Settings -->
                        <div class="wzb-card">
                            <h2>🔑 Cài đặt kết nối Zalo Bot</h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="bot_token">Bot Token <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" 
                                               id="bot_token" 
                                               name="wzb_settings[bot_token]" 
                                               value="<?php echo esc_attr($bot_token); ?>" 
                                               class="regular-text" 
                                               placeholder="12345689:abc-xyz"
                                               required>
                                        <p class="description">
                                            Token được cung cấp sau khi tạo bot. Xem <a href="https://github.com/Lexombien/zalo-bot-notification" target="_blank">hướng dẫn</a> để biết cách lấy token.
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="chat_id">Chat ID <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <div style="display: flex; gap: 10px;">
                                            <input type="text" 
                                                   id="chat_id" 
                                                   name="wzb_settings[chat_id]" 
                                                   value="<?php echo esc_attr($chat_id); ?>" 
                                                   class="regular-text" 
                                                   placeholder="Ví dụ: 12345678, 87654321"
                                                   required>
                                            <button type="button" class="button" id="find-chat-id">🔎 Tìm Chat ID (Auto)</button>
                                        </div>
                                        <p class="description">
                                            Nhập Chat ID của người nhận thông báo (ngăn cách bằng dấu phẩy nếu nhiều người).
                                            <br><strong>Cách lấy ID:</strong> Bấm <strong>"Xóa Webhook"</strong> -> Chat với Bot -> Bấm <strong>"Tìm Chat ID"</strong>.
                                        </p>
                                    </td>
                                </tr>

                                
                                <!-- Webhook URL (HIDDEN: Not needed for simple notification) -->
                                <!-- Secret Token (HIDDEN) -->
                                
                                <tr>
                                    <th scope="row"></th>
                                    <td>
                                        <button type="button" class="button button-secondary" id="test-connection">🧪 Test kết nối</button>
                                        <span id="test-connection-result" style="margin-left: 10px; font-weight: bold;"></span>
                                    </td>
                                </tr>
                            </table>
                            
                            <div class="wzb-actions">
                                <span class="wzb-status" id="connection-status"></span>
                            </div>
                        </div>
                        
                        <!-- Notification Settings -->
                        <div class="wzb-card">
                            <h2>📢 Cài đặt thông báo</h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label>Trạng thái đơn hàng</label>
                                    </th>
                                    <td>
                                        <fieldset>
                                            <legend class="screen-reader-text">Chọn trạng thái đơn hàng để gửi thông báo</legend>
                                            <?php foreach ($order_statuses as $status_key => $status_label) : 
                                                $status_slug = str_replace('wc-', '', $status_key);
                                            ?>
                                                <label>
                                                    <input type="checkbox" 
                                                           name="wzb_settings[enabled_statuses][]" 
                                                           value="<?php echo esc_attr($status_slug); ?>"
                                                           <?php checked(in_array($status_slug, $enabled_statuses)); ?>>
                                                    <?php echo esc_html($status_label); ?>
                                                </label><br>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description">
                                            Chọn các trạng thái đơn hàng mà bạn muốn nhận thông báo qua Zalo Bot.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Message Template -->
                        <div class="wzb-card">
                            <h2>✉️ Mẫu tin nhắn</h2>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="message_template">Nội dung tin nhắn</label>
                                    </th>
                                    <td>
                                        <textarea id="message_template" 
                                                  name="wzb_settings[message_template]" 
                                                  rows="10" 
                                                  class="large-text code"
                                                  placeholder="Nhập nội dung tin nhắn..."><?php echo esc_textarea($message_template); ?></textarea>
                                        
                                        <div class="wzb-template-vars">
                                            <h4>📝 Biến có sẵn:</h4>
                                            <div class="wzb-vars-grid">
                                                <div class="wzb-var-item">
                                                    <code>{order_number}</code>
                                                    <span>Số đơn hàng</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{order_status}</code>
                                                    <span>Trạng thái đơn hàng</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{order_total}</code>
                                                    <span>Tổng tiền</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{order_datetime}</code>
                                                    <span>Ngày giờ đặt hàng</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{customer_name}</code>
                                                    <span>Tên khách hàng</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{billing_phone}</code>
                                                    <span>Số điện thoại</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{billing_email}</code>
                                                    <span>Email</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{full_address}</code>
                                                    <span>Địa chỉ đầy đủ</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{product_list}</code>
                                                    <span>Danh sách sản phẩm</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{link_edit_order}</code>
                                                    <span>Link sửa đơn hàng</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{payment_method}</code>
                                                    <span>Phương thức thanh toán</span>
                                                </div>
                                                <div class="wzb-var-item">
                                                    <code>{shipping_method}</code>
                                                    <span>Phương thức vận chuyển</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="wzb-custom-fields" style="background:#f9f9f9; padding:15px; border:1px dashed #ccc; margin-top:15px; border-radius:4px;">
                                            <h4 style="margin-top:0;">➕ Thêm biến tùy chỉnh (Custom Fields)</h4>
                                            <p class="description">
                                                Để hiển thị các trường tùy chỉnh (như VAT, Delivery Date...), hãy nhập <strong>Meta Key</strong> vào dưới đây.
                                                <br>Ví dụ: Nhập <code>vat_checked</code> -> Dùng tag <code>{vat_checked}</code> trong mẫu tin nhắn.
                                            </p>
                                            
                                            <div id="custom-fields-container">
                                                <?php 
                                                if (!empty($custom_fields)) {
                                                    foreach ($custom_fields as $field) {
                                                        ?>
                                                        <div class="wzb-custom-field">
                                                            <input type="text" name="wzb_settings[custom_fields][]" value="<?php echo esc_attr($field); ?>" placeholder="meta_key">
                                                            <code class="wzb-token-preview" style="background:#fff; border:1px solid #ddd; padding:2px 5px; margin:0 5px;" title="Copy tag này">{<?php echo esc_html($field); ?>}</code>
                                                            <button type="button" class="button remove-custom-field">❌</button>
                                                        </div>
                                                        <?php
                                                    }
                                                }
                                                ?>
                                            </div>
                                            <button type="button" class="button" id="add-custom-field" style="margin-top: 10px;">➕ Thêm trường mới</button>
                                            <button type="button" class="button button-secondary" id="lookup-order-meta" style="margin-top: 10px; margin-left: 5px;">🔍 Tra cứu Meta (Mới nhất)</button>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <?php submit_button('💾 Lưu cài đặt'); ?>
                    </form>
                    
                    <hr>
                    

                </div>
                
                <!-- Sidebar -->
                <div class="wzb-sidebar">
                    <div class="wzb-card wzb-info-box">
                        <h3>ℹ️ Thông tin</h3>
                        <p><strong>Version:</strong> <?php echo esc_html(WZB_VERSION); ?></p>
                        <p><strong>Status:</strong> <span class="wzb-badge wzb-badge-success">Active</span></p>
                    </div>
                    
                    <div class="wzb-card wzb-help-box">
                         <h3>🚀 Hướng dẫn nhanh</h3>
                         <div style="font-size: 13px;">
                             <p><strong>B1: Tạo Bot</strong><br>
                             Tham khảo <a href="https://bot.zapps.me/docs/create-bot/" target="_blank">Hướng dẫn tạo Bot tại đây</a>.<br>
                             Sau khi tạo xong, bạn sẽ có <strong>Bot Token</strong>. Hãy copy vào phần Cài đặt.</p>
                             
                             <hr style="border: 0; border-top: 1px dashed #eee; margin: 10px 0;">
                             
                             <p><strong>B2: Tìm Chat ID</strong><br>
                             - Nhấn nút <strong>Tìm Chat ID (Auto)</strong>.<br>
                             - Chat với Bot (hoặc chia sẻ Bot cho người khác nhắn tin) để lấy ID.</p>
                             
                             <hr style="border: 0; border-top: 1px dashed #eee; margin: 10px 0;">
                             
                             <p><strong>B3: Hoàn tất</strong><br>
                             Sau khi có Token và ID, hãy <strong>Lưu cài đặt</strong> rồi bấm <strong>Test kết nối</strong> để kiểm tra.</p>
                         </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    

    
    public static function ajax_test_connection() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền thực hiện'));
        }
        
        $bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));
        $chat_ids_str = sanitize_text_field(wp_unslash($_POST['chat_id'] ?? ''));

        // Auto Save Settings if requested
        if (!empty($_POST['save_first'])) {
             $settings = self::get_settings_safe();
             $settings['bot_token'] = $bot_token;
             $settings['chat_id'] = $chat_ids_str;
             update_option('wzb_settings', wp_json_encode($settings));
             
             // Re-get settings to ensure consistency
             $settings = self::get_settings_safe();
        } else {
             $settings = self::get_settings_safe();
             $bot_token = $bot_token ?: ($settings['bot_token'] ?? '');
             $chat_ids_str = $chat_ids_str ?: ($settings['chat_id'] ?? '');
        }

        if (empty($bot_token) || empty($chat_ids_str)) {
            wp_send_json_error(array('message' => 'Vui lòng nhập Bot Token và Chat ID'));
        }
        
        // --- REAL ORDER TEST LOGIC ---
        // Get the latest order
        $latest_orders = wc_get_orders(array(
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'ids',
        ));
        
        $message = '';
        if (empty($latest_orders)) {
             $message = "⚠️ Website chưa có đơn hàng nào, gửi tin test mặc định...\n\n✅ KẾT NỐI THÀNH CÔNG!\nPlugin WooCommerce Zalo Bot đã sẵn sàng.";
        } else {
             $order_id = $latest_orders[0];
             $order = wc_get_order($order_id);
             
             if ($order) {
                 // Use Order Handler logic to build message
                 $template = $settings['message_template'] ?? '';
                 if (empty($template)) {
                     // Default template if empty
                     $template = "🔔 ĐƠN HANG MỚI #{order_number}\n💰 Tổng: {order_total} {currency}\n👤 Khách: {customer_name}";
                 }
                 
                 // Get Replacements from public helper
                 $replacements = WZB_Order_Handler::get_order_replacements($order, $settings);
                 $message = str_replace(array_keys($replacements), array_values($replacements), $template);
                 
                 // --- CLEANUP MESSAGE (Same as Order Handler) ---
                 // 1. Normalize line endings
                 $message = str_replace(array("\r\n", "\r"), "\n", $message);
                 
                 // 2. Explode, Trim each line
                 $lines = explode("\n", $message);
                 $lines = array_map('trim', $lines);
                 
                 // 3. Remove consecutive empty lines (allow max 1)
                 $clean_lines = array();
                 $last_was_empty = false;
                 
                 foreach($lines as $line) {
                     if ($line === '') {
                         if (!$last_was_empty) {
                             $clean_lines[] = '';
                             $last_was_empty = true;
                         }
                     } else {
                         $clean_lines[] = $line;
                         $last_was_empty = false;
                     }
                 }
                 $message = implode("\n", $clean_lines);
                 $message = trim($message);
                 // -----------------------------------------------
                 
                 // Add prefix
                 $message = "🧪 [TEST MODE] Đây là dữ liệu đơn hàng mới nhất:\n\n" . $message;
             }
        }
        
        if (empty($message)) {
             $message = "✅ KẾT NỐI THÀNH CÔNG!\nPlugin WooCommerce Zalo Bot đã sẵn sàng.";
        }
        
        $api = new WZB_API($bot_token);
        
        // Handle multiple IDs
        $chat_ids = array_map('trim', explode(',', $chat_ids_str));
        $success_count = 0;
        $errors = array();
        
        foreach ($chat_ids as $chat_id) {
            if (empty($chat_id)) continue;
            
            $result = $api->send_message($chat_id, $message);
            
            if (isset($result['success']) && $result['success']) {
                $success_count++;
            } else {
                $err_msg = isset($result['message']) ? $result['message'] : 'Unknown error';
                // Try to handle Zalo specific error messages if possible
                $errors[] = "$chat_id: " . $err_msg;
            }
        }
        
        if ($success_count > 0) {
            $msg = "Đã gửi test thành công tới $success_count người (Dữ liệu đơn hàng mới nhất).";
            if (!empty($errors)) {
                $msg .= " (Lỗi: " . implode(', ', $errors) . ")";
            }
            wp_send_json_success(array('message' => $msg));
        } else {
             wp_send_json_error(array('message' => 'Gửi thất bại. Lỗi: ' . implode(', ', $errors)));
        }
    }
    
    public static function ajax_setup_webhook() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền thực hiện'));
        }
        
        $settings = self::get_settings_safe();
        
        // Prefer POST data (user might have changed input without saving)
        $bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? $settings['bot_token'] ?? ''));
        $webhook_url = sanitize_text_field(wp_unslash($_POST['webhook_url'] ?? $settings['webhook_url'] ?? ''));
        
        if (empty($bot_token) || empty($webhook_url)) {
             wp_send_json_error(array('message' => 'Thiếu Token hoặc Webhook URL'));
        }
        
        // Save URL/Token first
        $settings['bot_token'] = $bot_token;
        $settings['webhook_url'] = $webhook_url;
        
        // Ensure secret token is available (if not in settings, generate one?)
        // Or assume it's already generated via regenerate_secret
        $secret_token = $settings['secret_token'] ?? '';
        
        // If empty, user might have cleared it or first run. 
        // But usually it's auto-generated on install.
        
        update_option('wzb_settings', wp_json_encode($settings));
        
        $api = new WZB_API($bot_token);
        
        // Pass secret_token to Zalo API
        $result = $api->set_webhook($webhook_url, $secret_token);
        
        if (is_wp_error($result) || (isset($result['success']) && !$result['success'])) {
             $err = is_wp_error($result) ? $result->get_error_message() : ($result['message'] ?? 'Unknown error');
             wp_send_json_error(array('message' => 'Lỗi Zalo API: ' . $err));
        } else {
             wp_send_json_success(array('message' => 'Đã thiết lập Webhook thành công!'));
        }
    }

    public static function ajax_regenerate_secret() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền thực hiện'));
        }
        
        $new_secret = wp_generate_password(32, false);
        
        $settings = self::get_settings_safe();
        $settings['secret_token'] = $new_secret;
        update_option('wzb_settings', wp_json_encode($settings));
        
        wp_send_json_success(array('secret_token' => $new_secret));
    }

    public static function ajax_get_chat_id() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền thực hiện'));
        }
        
        $bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));
        
        if (empty($bot_token)) {
            wp_send_json_error(array('message' => 'Vui lòng nhập Bot Token trước'));
        }
        
        $api = new WZB_API($bot_token);
        
        // Always delete webhook first to avoid conflict error "You cannot use this API while a webhook is set"
        // This is safe because we only need to get updates temporarily
        $api->delete_webhook();
        
        // Try getting Chat ID
        $result = $api->get_latest_chat_id();
        
        if ($result['success']) {
            wp_send_json_success(array(
                'chat_id' => $result['chat_id'],
                'message' => 'Đã tìm thấy Chat ID! Lưu ý: Webhook đã được tạm tắt để lấy ID, vui lòng bấm "Thiết lập Webhook" lại sau khi lưu.'
            ));
        } else {
            // Detailed error message
            $error_msg = $result['message'];
            if (strpos($error_msg, 'webhook') !== false) {
                 $error_msg = 'Vẫn dính lỗi Webhook. Hãy thử vào App quản lý Bot xóa Webhook thủ công hoặc đợi 1 lát.';
            }
            wp_send_json_error(array('message' => 'Lỗi: ' . $error_msg));
        }
    }

    public static function ajax_check_webhook_info() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
         if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Forbidden'));
         
         $bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));
         if (!$bot_token) wp_send_json_error(array('message' => 'Missing token'));
         
         $api = new WZB_API($bot_token);
         $result = $api->get_webhook_info();
         
         if ($result['success']) {
             wp_send_json_success(array('data' => $result['data']));
         } else {
             wp_send_json_error(array('message' => $result['message']));
         }
    }

    public static function ajax_delete_webhook() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
         if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Forbidden'));
         
         $bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));
         if (!$bot_token) wp_send_json_error(array('message' => 'Missing token'));
         
         $api = new WZB_API($bot_token);
         $result = $api->delete_webhook();
         
         if ($result['success']) {
             wp_send_json_success(array('message' => 'Đã xóa Webhook thành công! Bạn có thể dùng tính năng Tìm Chat ID ngay bây giờ.'));
         } else {
             wp_send_json_error(array('message' => $result['message']));
         }
    }

    public static function ajax_get_sample_order_meta() {
        check_ajax_referer('wzb-admin-nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Forbidden'));

        // Get latest order
        $orders = wc_get_orders(array('limit' => 1, 'orderby' => 'date', 'order' => 'DESC'));
        
        if (empty($orders)) {
            wp_send_json_error(array('message' => 'Không tìm thấy đơn hàng nào để tra cứu.'));
        }
        
        $order = $orders[0];
        $formatted_meta = array();

        // 1. Get Standard Order Data (Billing, Shipping, Status, Total, etc.)
        $order_data = $order->get_data();
        
        foreach ($order_data as $key => $value) {
            // Processing nested arrays like 'billing' and 'shipping'
            if (is_array($value)) {
                foreach ($value as $sub_key => $sub_value) {
                    $combined_key = $key . '_' . $sub_key; // e.g., billing_first_name
                    $formatted_meta[] = array(
                        'key' => $combined_key,
                        'value' => (is_string($sub_value) || is_numeric($sub_value)) ? $sub_value : json_encode($sub_value, JSON_UNESCAPED_UNICODE)
                    );
                }
            } else {
                // Skip large objects or arrays (like meta_data which we handle separately)
                if ($key === 'meta_data' || $key === 'line_items' || $key === 'tax_lines' || $key === 'shipping_lines' || $key === 'fee_lines' || $key === 'coupon_lines') {
                    continue;
                }
                
                $formatted_meta[] = array(
                    'key' => $key,
                    'value' => (is_string($value) || is_numeric($value)) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)
                );
            }
        }
        
        // 2. Get Custom Meta Data
        $meta_data = $order->get_meta_data();
        
        foreach ($meta_data as $meta) {
            $data = $meta->get_data();
            $value = $data['value'];
            
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            
            $formatted_meta[] = array(
                'key' => $data['key'],
                'value' => substr((string)$value, 0, 100) . (strlen((string)$value) > 100 ? '...' : '')
            );
        }
        
        // 3. Add Handy Helpers (Payment Title, Formatted Total)
        $formatted_meta[] = array('key' => 'payment_method_title', 'value' => $order->get_payment_method_title());
        $formatted_meta[] = array('key' => 'formatted_order_total', 'value' => wp_strip_all_tags($order->get_formatted_order_total()));

        wp_send_json_success(array(
            'order_number' => $order->get_order_number(),
            'meta' => $formatted_meta
        ));
    }
}
