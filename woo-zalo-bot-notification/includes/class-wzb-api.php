<?php
/**
 * Zalo Bot API Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WZB_API {
    
    private $bot_token;
    private $api_base_url = 'https://bot-api.zaloplatforms.com/bot';
    
    public function __construct($bot_token = '') {
        if (empty($bot_token)) {
            $settings = get_option('wzb_settings', array());
            $bot_token = $settings['bot_token'] ?? '';
        }
        $this->bot_token = $bot_token;
    }
    
    /**
     * Send message to Zalo Bot
     */
    public function send_message($chat_id, $text) {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/sendMessage';
        
        $body = array(
            'chat_id' => $chat_id,
            'text' => $text
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Send photo to Zalo Bot
     */
    public function send_photo($chat_id, $photo_url, $caption = '') {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/sendPhoto';
        
        $body = array(
            'chat_id' => $chat_id,
            'photo' => $photo_url
        );
        
        if (!empty($caption)) {
            $body['caption'] = $caption;
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Set webhook URL
     */
    public function set_webhook($webhook_url, $secret_token = '') {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/setWebhook';
        
        $body = array(
            'url' => $webhook_url
        );
        
        if (!empty($secret_token)) {
            $body['secret_token'] = $secret_token;
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Delete webhook
     */
    public function delete_webhook() {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/deleteWebhook';
        
        $response = wp_remote_post($url, array(
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Get webhook info
     */
    public function get_webhook_info() {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/getWebhookInfo';
        
        $response = wp_remote_get($url, array(
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Get bot info
     */
    public function get_me() {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/getMe';
        
        $response = wp_remote_get($url, array(
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Get updates (for polling mode)
     */
    public function get_updates($offset = 0, $limit = 100) {
        if (empty($this->bot_token)) {
            return array(
                'success' => false,
                'message' => 'Bot Token không được để trống'
            );
        }
        
        $url = $this->api_base_url . $this->bot_token . '/getUpdates';
        
        $params = array(
            'offset' => $offset,
            'limit' => $limit
        );
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30
        ));
        
        return $this->parse_response($response);
    }
    
    /**
     * Get chat ID from latest updates
     */
    public function get_latest_chat_id() {
        // First, check webhook status. If webhook is set, getUpdates verification will fail.
        // We might need to delete webhook temporarily or warn user.
        // Usually, getUpdates only works if Webhook is NOT set.
        
        $result = $this->get_updates();
        
        if (!$result['success']) {
            return $result;
        }
        
        $updates = $result['data'];
        
        if (empty($updates)) {
            return array(
                'success' => false,
                'message' => 'Không tìm thấy tin nhắn nào. Hãy nhắn tin cho Bot trên Zalo trước (ví dụ: "Hello") sau đó thử lại.'
            );
        }
        
        // Handle case where result is a single object (User's case)
        if (isset($updates['message'])) {
            $last_update = $updates;
        } 
        // Handle case where result is a list of updates (Standard case)
        else if (is_array($updates)) {
            $last_update = end($updates);
        } else {
             return array(
                'success' => false,
                'message' => 'Cấu trúc dữ liệu không xác định.'
            );
        }
        
        if (isset($last_update['message']['chat']['id'])) {
            return array(
                'success' => true,
                'chat_id' => $last_update['message']['chat']['id'],
                'data' => $last_update
            );
        } else if (isset($last_update['my_chat_member']['chat']['id'])) {
             // Handle case where user just joined/blocked bot
             return array(
                'success' => true,
                'chat_id' => $last_update['my_chat_member']['chat']['id']
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Không tìm thấy thông tin Chat ID trong dữ liệu trả về.'
        );
    }

    /**
     * Parse API response
     */
    private function parse_response($response) {
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['ok']) && $data['ok'] === true) {
            return array(
                'success' => true,
                'data' => $data['result'] ?? array(),
                'message' => 'Success'
            );
        } else {
            return array(
                'success' => false,
                'message' => $data['description'] ?? 'Unknown error',
                'error_code' => $data['error_code'] ?? 0
            );
        }
    }
    
    /**
     * Log API call for debugging
     */
    private function log($message, $data = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WZB API] ' . $message . ' ' . print_r($data, true));
        }
    }
}
