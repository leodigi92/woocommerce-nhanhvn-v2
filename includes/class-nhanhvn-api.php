<?php
/**
 * The API-specific functionality of the plugin.
 *
 * @link       https://leodigi.com
 * @since      1.0.0
 *
 * @package    Nhanhvn
 * @subpackage Nhanhvn/api
 */

/**
 * The API-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Nhanhvn
 * @subpackage Nhanhvn/api
 * @author     Leo Digi <leodigi92@gmail.com>
 */
class Nhanhvn_Api
{

  /**
   * The ID of this plugin.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $plugin_name    The ID of this plugin.
   */
  private $plugin_name;

  /**
   * The version of this plugin.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $version    The current version of this plugin.
   */
  private $version;

  /**
   * The API URL.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $api_url    The API URL.
   */
  private $api_url = 'https://open.nhanh.vn';

  /**
   * The API version.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $api_version    The API version.
   */
  private $api_version = '2.0';

  /**
   * The API app ID.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $api_app_id    The API app ID.
   */
  private $api_app_id;

  /**
   * The API business ID.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $api_business_id    The API business ID.
   */
  private $api_business_id;

  /**
   * The API access token.
   *
   * @since    1.0.0
   * @access   private
   * @var      string    $api_access_token    The API access token.
   */
  private $api_access_token;

  /**
   * Singleton instance.
   *
   * @since    1.0.0
   * @access   private
   * @var      Nhanhvn_Api    $instance    The singleton instance.
   */
  private static $instance = null;

  /**
   * Get singleton instance.
   *
   * @since    1.0.0
   * @return   Nhanhvn_Api    The singleton instance.
   */
  public static function instance()
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Initialize the class and set its properties.
   *
   * @since    1.0.0
   * @param      string    $plugin_name       The name of this plugin.
   * @param      string    $version    The version of this plugin.
   */
  public function __construct($plugin_name = '', $version = '')
  {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
    $this->api_app_id = get_option('nhanhvn_app_id', '');
    $this->api_business_id = get_option('nhanhvn_business_id', '');
    $this->api_access_token = get_option('nhanhvn_access_token', '');
  }

  /**
   * Send a request to the API.
   *
   * @since    1.0.0
   * @param    string    $endpoint    The API endpoint.
   * @param    array     $data        The data to send.
   * @return   array                  The API response.
   */
  public function send_request($endpoint, $data = [])
  {
    // Chuẩn bị params
    $params = [
      'version' => $this->api_version,
      'appId' => $this->api_app_id,
      'businessId' => $this->api_business_id,
      'accessToken' => $this->api_access_token,
      'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
    ];

    // Log request để debug
    error_log('Nhanh.vn API Request to ' . $endpoint . ': ' . print_r($params, true));

    // Thực hiện request với wp_remote_post
    $response = wp_remote_post($this->api_url . $endpoint, [
      'method' => 'POST',
      'timeout' => 45,
      'redirection' => 5,
      'httpversion' => '1.0',
      'blocking' => true,
      'headers' => ['Content-Type' => 'multipart/form-data'],
      'body' => $params
    ]);

    // Xử lý response
    if (is_wp_error($response)) {
      error_log('Nhanh.vn API Error: ' . $response->get_error_message());
      return ['error' => $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    error_log('Nhanh.vn API Response: ' . $body);

    return json_decode($body, true);
  }

  /**
   * Get the login URL for Nhanh.vn OAuth.
   *
   * @since    1.0.0
   * @return   string    The login URL.
   */
  public function get_login_url()
  {
    $redirect_url = admin_url('admin.php?page=nhanhvn-settings&tab=api&action=oauth_callback');
    return 'https://nhanh.vn/oauth?version=' . $this->api_version . '&appId=' . $this->api_app_id . '&returnLink=' . urlencode($redirect_url);
  }

  /**
   * Handle the OAuth callback from Nhanh.vn.
   *
   * @since    1.0.0
   * @param    string    $access_code    The access code.
   * @return   bool                      Whether the authentication was successful.
   */
  public function handle_oauth_callback($access_code)
  {
    if (empty($access_code)) {
      return false;
    }

    $secret_key = get_option('nhanhvn_secret_key', '');

    $params = [
      'version' => $this->api_version,
      'appId' => $this->api_app_id,
      'accessCode' => $access_code,
      'secretKey' => $secret_key
    ];

    error_log('Nhanh.vn Auth Request: ' . print_r($params, true));

    $response = wp_remote_post($this->api_url . '/api/oauth/access_token', [
      'method' => 'POST',
      'timeout' => 45,
      'redirection' => 5,
      'httpversion' => '1.0',
      'blocking' => true,
      'body' => $params
    ]);

    if (is_wp_error($response)) {
      error_log('Nhanh.vn Auth Error: ' . $response->get_error_message());
      return false;
    }

    $body = wp_remote_retrieve_body($response);
    error_log('Nhanh.vn Auth Response: ' . $body);

    $data = json_decode($body, true);

    if (isset($data['code']) && $data['code'] == 1) {
      // Lưu thông tin token
      update_option('nhanhvn_access_token', $data['accessToken']);
      update_option('nhanhvn_business_id', $data['businessId']);
      update_option('nhanhvn_token_expired', $data['expiredDateTime']);
      update_option('nhanhvn_permissions', json_encode($data['permissions']));

      if (isset($data['depotIds'])) {
        update_option('nhanhvn_depot_ids', json_encode($data['depotIds']));
      }

      return true;
    }

    return false;
  }

  /**
   * Check if the access token is expired.
   *
   * @since    1.0.0
   * @return   bool    Whether the token is expired.
   */
  public function is_token_expired()
  {
    $expired_date_time = get_option('nhanhvn_token_expired');
    if (!$expired_date_time)
      return true;

    return strtotime($expired_date_time) < time();
  }

  /**
   * Get products from Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int      $page     The page number.
   * @param    int      $limit    The number of products per page.
   * @return   array              The products.
   */
  public function get_products($page = 1, $limit = 20)
  {
    // Lấy thời gian đồng bộ cuối cùng
    $last_sync = get_option('nhanhvn_last_product_sync', 0);

    // Chuẩn bị dữ liệu
    $data = [
      'page' => $page,
      'limit' => $limit
    ];

    // Nếu đã đồng bộ trước đó, chỉ lấy sản phẩm đã cập nhật
    if ($last_sync > 0) {
      $data['updatedAtFrom'] = date('Y-m-d H:i:s', $last_sync);
    }

    // Gọi API
    return $this->send_request('/api/product/search', $data);
  }

  /**
   * Get product by ID from Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int      $product_id    The product ID.
   * @return   array                   The product.
   */
  public function get_product($product_id)
  {
    $data = [
      'id' => $product_id
    ];

    return $this->send_request('/api/product/detail', $data);
  }

  /**
   * Get inventory from Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int      $page     The page number.
   * @param    int      $limit    The number of products per page.
   * @return   array              The inventory.
   */
  public function get_inventory($page = 1, $limit = 20)
  {
    $data = [
      'page' => $page,
      'limit' => $limit
    ];

    return $this->send_request('/api/product/inventory', $data);
  }

  /**
   * Send an order to Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int      $order_id    The order ID.
   * @return   array                 The API response.
   */
  public function send_order($order_id)
  {
    $order = wc_get_order($order_id);
    if (!$order)
      return false;

    // Chuẩn bị dữ liệu khách hàng
    $customer = [
      'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
      'mobile' => $order->get_billing_phone(),
      'email' => $order->get_billing_email(),
      'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
      'cityName' => $order->get_billing_city(),
      'districtName' => $order->get_billing_state()
    ];

    // Chuẩn bị dữ liệu sản phẩm
    $items = [];
    foreach ($order->get_items() as $item) {
      $product = $item->get_product();
      $nhanh_product_id = get_post_meta($product->get_id(), '_nhanhvn_product_id', true);

      if (!$nhanh_product_id) {
        // Thử tìm theo SKU
        $sku = $product->get_sku();
        if ($sku) {
          $nhanh_product = $this->find_product_by_sku($sku);
          if ($nhanh_product) {
            $nhanh_product_id = $nhanh_product['id'];
            update_post_meta($product->get_id(), '_nhanhvn_product_id', $nhanh_product_id);
          }
        }
      }

      if (!$nhanh_product_id)
        continue;

      $items[] = [
        'productId' => $nhanh_product_id,
        'quantity' => $item->get_quantity(),
        'price' => $item->get_total() / $item->get_quantity()
      ];
    }

    if (empty($items)) {
      error_log('Nhanh.vn: No valid products found for order #' . $order_id);
      return false;
    }

    // Chuẩn bị dữ liệu đơn hàng
    $order_data = [
      'id' => $order->get_id(),
      'code' => $order->get_order_number(),
      'type' => 'Online',
      'customerShipFee' => $order->get_shipping_total(),
      'customerNote' => $order->get_customer_note(),
      'customer' => $customer,
      'products' => $items
    ];

    // Gọi API
    $response = $this->send_request('/api/order/add', $order_data);

    if (isset($response['code']) && $response['code'] == 1) {
      // Lưu ID đơn hàng từ Nhanh.vn
      update_post_meta($order_id, '_nhanhvn_order_id', $response['data']['id']);
      return true;
    }

    return false;
  }

  /**
   * Find a product by SKU.
   *
   * @since    1.0.0
   * @param    string    $sku    The product SKU.
   * @return   array|false       The product or false if not found.
   */
  private function find_product_by_sku($sku)
  {
    $data = [
      'sku' => $sku
    ];

    $response = $this->send_request('/api/product/search', $data);

    if (isset($response['data']) && !empty($response['data'])) {
      return $response['data'][0];
    }

    return false;
  }
}
