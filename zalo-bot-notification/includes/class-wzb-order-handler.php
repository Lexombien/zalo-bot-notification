<?php
/**
 * Order Handler Class
 * Handles order status changes and sends notifications
 */

if (!defined('ABSPATH')) {
    exit;
}

class WZB_Order_Handler {
    
    public static function init() {
        // Hook into order status changes
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'handle_order_status_change'), 10, 4);
        
        // Hook for new orders (processing)
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'handle_new_order'), 10, 3);
    }
    
    /**
     * Handle new order creation
     */
    public static function handle_new_order($order_id, $posted_data, $order) {
        // Only trigger if 'processing' is enabled in settings (since new orders usually go to processing)
        // However, status change hook usually fires too, so we might not need this to avoid duplicates.
        // Let's rely on status_changed hook mostly, but if status doesn't change (e.g. pending -> pending), this might be needed.
        // For standard flows, status_changed covers it. Let's keep it simple and use status_changed.
    }
    
    /**
     * Handle order status change
     */
    public static function handle_order_status_change($order_id, $from_status, $to_status, $order) {
        if (class_exists('WZB_Settings')) {
            $settings = WZB_Settings::get_settings_safe();
        } else {
             $settings = get_option('wzb_settings');
             if (is_string($settings)) {
                 $json = json_decode($settings, true);
                 if (is_array($json)) $settings = $json;
                 else $settings = maybe_unserialize($settings);
             }
        }
        
        $enabled_statuses = $settings['enabled_statuses'] ?? array();
        
        // Check if the new status is enabled for notification
        if (!in_array($to_status, $enabled_statuses)) {
            return;
        }
        
        self::send_order_notification($order_id, $order, $settings);
    }
    
    /**
     * Send notification for an order
     */
    public static function send_order_notification($order_id, $order, $settings) {
        $bot_token = $settings['bot_token'] ?? '';
        $chat_ids_str = $settings['chat_id'] ?? '';
        $message_template = $settings['message_template'] ?? '';
        
        if (empty($bot_token) || empty($chat_ids_str) || empty($message_template)) {
            return;
        }
        
        // Prepare replacement data
        $replacements = self::get_order_replacements($order, $settings);
        
        // Process message template
        $message = str_replace(array_keys($replacements), array_values($replacements), $message_template);
        
        // CLeanup message content
        // 1. Normalize line endings to \n
        $message = str_replace(array("\r\n", "\r"), "\n", $message);
        
        // 2. Explode, Trim each line, and Re-assemble
        $lines = explode("\n", $message);
        $lines = array_map('trim', $lines);
        
        // 3. Remove consecutive empty lines (keep max 1 empty line)
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
        
        // Initialize API
        $api = new WZB_API($bot_token);
        
        // Handle multiple Chat IDs (separated by comma)
        $chat_ids = array_map('trim', explode(',', $chat_ids_str));
        $success_count = 0;
        
        foreach ($chat_ids as $chat_id) {
            if (empty($chat_id)) continue;
            
            // Send message
            $result = $api->send_message($chat_id, $message);
            
            if ($result['success']) {
                $success_count++;
            } else {
                // Silent fail or optional: WZB_API might have logged this internally if debug enabled used differently
                // For now just continue (could store error for admin view later)
            }
        }
        
        // Log result
        if ($success_count > 0) {
            $order->add_order_note("Zalo Bot: Đã gửi thông báo thành công tới $success_count người.");
        } else {
            $order->add_order_note('Zalo Bot: Gửi thông báo thất bại.');
        }
    }
    
    /**
     * Get all replacement variables for the message
     */
    public static function get_order_replacements($order, $settings) {
        $data = array();
        
        // Basic Order Info
        $data['{order_number}'] = $order->get_order_number();
        $data['{order_id}'] = $order->get_id();
        $data['{order_status}'] = wc_get_order_status_name($order->get_status());
        // Clean HTML tags from price
        $data['{order_total}'] = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8');
        $data['{currency}'] = $order->get_currency();
        $data['{payment_method}'] = $order->get_payment_method_title();
        $data['{shipping_method}'] = $order->get_shipping_method();
        
        // Date & Time
        $order_date = $order->get_date_created();
        $data['{order_date}'] = $order_date ? $order_date->date('d/m/Y') : '';
        $data['{order_time}'] = $order_date ? $order_date->date('H:i') : '';
        $data['{order_datetime}'] = $order_date ? $order_date->date('d/m/Y H:i') : '';
        
        // Customer Info
        $data['{customer_name}'] = $order->get_formatted_billing_full_name();
        $data['{billing_first_name}'] = $order->get_billing_first_name();
        $data['{billing_last_name}'] = $order->get_billing_last_name();
        $data['{billing_email}'] = $order->get_billing_email();
        $data['{billing_phone}'] = $order->get_billing_phone();
        $data['{customer_note}'] = $order->get_customer_note();
        
        // Address
        $data['{billing_address}'] = $order->get_formatted_billing_address();
        $data['{shipping_address}'] = $order->get_formatted_shipping_address();
        
        // Full address (custom format often requested in VN)
        // Full address - Use WooCommerce formatted address to handle custom country/state names correctly
        $raw_address = $order->get_formatted_shipping_address();
        if (empty($raw_address)) {
            $raw_address = $order->get_formatted_billing_address();
        }
        // Clean address: replace break lines with comma, then strip tags
        $data['{full_address}'] = wp_strip_all_tags(str_replace(array('<br>', '<br/>', '<br />'), ', ', $raw_address));
        
        // Links
        $data['{link_edit_order}'] = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $data['{link_view_order}'] = $order->get_view_order_url();
        
        // Product List
        $data['{product_list}'] = self::get_formatted_product_list($order);
        
        // Custom Fields Logic - Improved to handle both Meta and Standard Props
        $custom_fields = $settings['custom_fields'] ?? array();
        if (!empty($custom_fields)) {
            // Cache order data to search for standard props if meta is missing
            $order_data = $order->get_data();

            foreach ($custom_fields as $field_key) {
                $value = '';

                // Priority 1: Check direct Meta (most common for custom fields)
                $meta_val = $order->get_meta($field_key);
                if ($meta_val !== '' && $meta_val !== false) {
                    $value = $meta_val;
                } 
                // Priority 2: Check standard getter methods (e.g. total -> get_total())
                elseif (is_callable(array($order, 'get_' . $field_key))) {
                    $method = 'get_' . $field_key;
                    $value = $order->$method();
                }
                // Priority 3: Check in data array (e.g. status, currency)
                elseif (isset($order_data[$field_key])) {
                    $value = $order_data[$field_key];
                }
                // Priority 4: Check nested data (e.g. billing_first_name)
                else {
                    // Try to split key (e.g. billing_address_1 -> billing -> address_1)
                     $parts = explode('_', $field_key, 2);
                     if (count($parts) == 2 && isset($order_data[$parts[0]]) && is_array($order_data[$parts[0]]) && isset($order_data[$parts[0]][$parts[1]])) {
                         $value = $order_data[$parts[0]][$parts[1]];
                     }
                }

                // Format Array/Object
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                
                // Allow value to be 0 or '0' but not empty string/null/false if strict check needed
                // But generally:
                $data['{' . $field_key . '}'] = $value;
            }
        }
        
        // Final cleanup: Trim all values
        $data = array_map(function($val) {
            return is_string($val) ? trim($val) : $val;
        }, $data);
        
        return $data;
    }
    
    /**
     * Format product list as string
     */
    private static function get_formatted_product_list($order) {
        $items = $order->get_items();
        $product_lines = array();
        
        foreach ($items as $item) {
            $product = $item->get_product();
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            // Clean HTML from item total
            $total = html_entity_decode(wp_strip_all_tags(wc_price($item->get_total(), array('currency' => $order->get_currency()))), ENT_QUOTES, 'UTF-8');
            
            // Get SKU if available
            $sku = $product ? $product->get_sku() : '';
            $sku_str = $sku ? " (SKU: $sku)" : '';
            
            // Get attributes if variable product
            $meta_data = $item->get_formatted_meta_data();
            $meta_str = '';
            if ($meta_data) {
                $meta_parts = array();
                foreach ($meta_data as $meta) {
                    $meta_parts[] = $meta->display_key . ': ' . wp_strip_all_tags($meta->display_value);
                }
                if (!empty($meta_parts)) {
                    $meta_str = ' [' . implode(', ', $meta_parts) . ']';
                }
            }
            
            $product_lines[] = "🔹 $quantity x $product_name$sku_str$meta_str - $total";
        }
        
        return implode("\n", $product_lines);
    }
}
