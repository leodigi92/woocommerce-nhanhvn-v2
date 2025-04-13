<?php
class NhanhVN_Webhook
{
  private static $instance = null;

  public static function instance()
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function __construct()
  {
    add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
  }

  public function register_webhook_endpoint()
  {
    register_rest_route('nhanh/v1', '/webhook', array(
      'methods' => 'POST',
      'callback' => array($this, 'handle_webhook'),
      'permission_callback' => array($this, 'verify_webhook')
    ));
  }

  public function verify_webhook($request)
  {
    $query_token = $request->get_param('token');
    $body_data = $request->get_json_params();
    $body_token = $body_data['webhooksVerifyToken'] ?? null;
    $saved_token = get_option('nhanhvn_webhook_token');
    $referer = $request->get_header('referer');
    if ($referer && strpos($referer, 'nhanh.vn') === false) {
      return new WP_Error('invalid_source', 'Nguồn không hợp lệ', array('status' => 403));
    }
    if ((!$query_token && !$body_token) || ($query_token !== $saved_token && $body_token !== $saved_token)) {
      error_log('Nhanh.vn Webhook Error: Token mismatch - Query: ' . $query_token . ', Body: ' . $body_token . ', Saved: ' . $saved_token);
      return new WP_Error('invalid_token', 'Token không hợp lệ', array('status' => 403));
    }
    return true;
  }

  public function handle_webhook($request)
  {
    $raw_body = $request->get_body();
    error_log('Nhanh.vn Webhook Raw Body: ' . $raw_body);

    $data = $request->get_json_params();
    error_log('Nhanh.vn Webhook JSON Parsed: ' . print_r($data, true));

    if (!$data && !empty($raw_body)) {
      $data = json_decode($raw_body, true);
      error_log('Nhanh.vn Webhook Manual JSON Parse: ' . print_r($data, true));
    }

    if (!$data) {
      $data = $request->get_params();
      error_log('Nhanh.vn Webhook Fallback Params: ' . print_r($data, true));
    }

    if (!$data || !isset($data['event'])) {
      $error = new WP_Error('invalid_data', 'Dữ liệu không hợp lệ hoặc thiếu trường event');
      error_log('Nhanh.vn Webhook Error: ' . $error->get_error_message() . ' - Raw: ' . $raw_body);
      $this->log_webhook('unknown', $error);
      return $error;
    }

    error_log('Nhanh.vn Webhook Data: ' . print_r($data, true));
    $this->log_webhook($data['event'], $data);

    switch ($data['event']) {
      case 'productAdd':
      case 'productUpdate':
        $this->handle_product_update($data['data']);
        break;
      case 'productDelete':
        $this->handle_product_delete($data['data']);
        break;
      case 'inventoryChange':
        $this->handle_inventory_update($data['data']);
        break;
      case 'orderAdd':
      case 'orderUpdate':
        $this->handle_order_status($data['data']);
        break;
      case 'orderDelete':
        $this->handle_order_delete($data['data']);
        break;
      case 'paymentReceived':
        $this->handle_payment_info($data['data']);
        break;
      case 'webhooksEnabled':
        // Xác nhận webhook đã bật
        break;
      default:
        $error = new WP_Error('unknown_event', 'Sự kiện không được hỗ trợ: ' . $data['event']);
        $this->log_webhook($data['event'], $error);
        return $error;
    }

    return new WP_REST_Response(array('status' => 'success', 'message' => 'Webhook processed'), 200);
  }

  private function handle_product_update($data)
  {
    $product_sync = NhanhVN_Product::instance();
    $product_sync->update_or_create_product($data);
  }

  private function handle_product_delete($data)
  {
    $product_id = wc_get_product_id_by_sku($data['code']);
    if ($product_id) {
      wp_delete_post($product_id, true);
    }
  }

  private function handle_inventory_update($data)
  {
    $inventory = NhanhVN_Inventory::instance();
    $inventory->update_product_stock($data);
  }

  private function handle_order_status($data)
  {
    $order_id = get_post_meta_by_value('_nhanhvn_order_id', $data['id']);
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order) {
        $new_status = $this->map_nhanh_status_to_wc($data['status']);
        $order->update_status($new_status);
      }
    }
  }

  private function handle_order_delete($data)
  {
    $order_id = get_post_meta_by_value('_nhanhvn_order_id', $data['id']);
    if ($order_id) {
      wp_delete_post($order_id, true);
    }
  }

  private function handle_payment_info($data)
  {
    $order_id = get_post_meta_by_value('_nhanhvn_order_id', $data['orderId']);
    if ($order_id) {
      $order = wc_get_order($order_id);
      if ($order) {
        $order->update_meta_data('_nhanhvn_payment_status', $data['status']);
        $order->save();
      }
    }
  }

  private function map_nhanh_status_to_wc($nhanh_status)
  {
    $status_map = array(
      'New' => 'pending',
      'Confirmed' => 'processing',
      'Shipping' => 'processing',
      'Delivered' => 'completed',
      'Canceled' => 'cancelled'
    );
    return isset($status_map[$nhanh_status]) ? $status_map[$nhanh_status] : 'pending';
  }

  private function log_webhook($event, $data)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nhanhvn_sync_log';

    $status = 'success';
    $message = 'Webhook processed successfully';

    if (is_wp_error($data)) {
      $status = 'error';
      $message = 'API Response Error: ' . $data->get_error_message();
    }

    $wpdb->insert(
      $table_name,
      array(
        'type' => 'webhook_' . $event,
        'status' => $status,
        'message' => $message,
        'data' => is_array($data) || is_object($data) ? json_encode($data) : $data,
        'created_at' => current_time('mysql')
      ),
      array('%s', '%s', '%s', '%s', '%s')
    );
  }
}

function get_post_meta_by_value($meta_key, $meta_value)
{
  global $wpdb;
  $post_id = $wpdb->get_var($wpdb->prepare(
    "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
    $meta_key,
    $meta_value
  ));
  return $post_id;
}
