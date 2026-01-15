<?php
/**
 * Webhook Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class WZB_Webhook {
    
    public static function init() {
        // No init needed for query string approach as it's handled in Settings::init -> parse_request
        // But we keep this for structure consistency
    }
    
    public static function get_webhook_url() {
        if (class_exists('WZB_Settings')) {
            $settings = WZB_Settings::get_settings_safe();
        } else {
             $settings = get_option('wzb_settings');
             // Quick decode fallback if class not loaded
             if (is_string($settings)) {
                 $json = json_decode($settings, true);
                 if (is_array($json)) {
                     $settings = $json;
                 } else {
                     $settings = maybe_unserialize($settings);
                 }
             }
        }
        
        $secret_token = $settings['secret_token'] ?? '';
        return home_url('/?wzb-webhook=1&token=' . $secret_token);
    }
    
    /**
     * Handle incoming webhook request from Zalo
     */
    public static function handle_request() {
        // 1. Verify Secret Token from URL
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token_input = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        
        if (class_exists('WZB_Settings')) {
            $settings = WZB_Settings::get_settings_safe();
        } else {
             $settings = get_option('wzb_settings');
             // Fallback for older data if string
             if (is_string($settings)) {
                 $json = json_decode($settings, true);
                 if (is_array($json)) $settings = $json;
                 else $settings = maybe_unserialize($settings);
             }
        }

        $stored_token = $settings['secret_token'] ?? '';
        
        if (empty($stored_token) || $token_input !== $stored_token) {
            wp_send_json_error(array('message' => 'Invalid Token'), 403);
            exit;
        }
        
        // 2. Get JSON Payload
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (empty($data)) {
            wp_send_json_error(array('message' => 'Empty Payload'), 400);
            exit;
        }

        // 3. Log payload if debug enabled
        if (!empty($settings['enable_debug'])) {
            $log_entry = wp_date('Y-m-d H:i:s') . " Payload: " . wp_json_encode($data) . "\n";
            file_put_contents(WZB_PLUGIN_DIR . 'debug.log', $log_entry, FILE_APPEND);
        }
        
        // 4. Handle Content
        // Check for User ID (User sends message to OA)
        // Check event name (user_send_text, user_send_image...)
        $event_name = $data['event_name'] ?? '';
        $sender_id = $data['sender']['id'] ?? '';
        $message_text = $data['message']['text'] ?? '';
        
        // Setup Temporary ID cache for "Find Chat ID" feature
        if ($sender_id && ($event_name == 'user_send_text' || isset($data['message']))) {
            set_transient('wzb_latest_chat_id', $sender_id, 3600); // Save for 1 hour
            
            // Auto Reply (Optional)
            // self::send_auto_reply($sender_id, "Bot đã nhận được ID: " . $sender_id);
        }

        // 5. Response 200 OK
        wp_send_json_success(array('message' => 'Received'));
        exit;
    }
}
