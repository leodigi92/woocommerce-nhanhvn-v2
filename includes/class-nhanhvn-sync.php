<?php
/**
 * The synchronization functionality of the plugin.
 *
 * @since      1.0.0
 * @package    Nhanhvn
 * @subpackage Nhanhvn/includes
 */

if (!defined('ABSPATH')) {
  exit;
}

class NhanhVN_Sync
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
    // Register cron schedules
    add_action('init', array($this, 'register_cron_schedules'));

    // Register webhooks
    add_action('rest_api_init', array($this, 'register_webhooks'));

    // WooCommerce hooks
    add_action('woocommerce_checkout_order_processed', array($this, 'sync_order_to_nhanh'), 10, 1);
    add_action('woocommerce_order_status_changed', array($this, 'sync_order_status_to_nhanh'), 10, 3);
  }

  /**
   * Register cron schedules.
   *
   * @since    1.0.0
   */
  public function register_cron_schedules()
  {
    // Check if sync is enabled
    $sync_products = get_option('nhanhvn_sync_products', '1');
    $sync_inventory = get_option('nhanhvn_sync_inventory', '1');
    $sync_frequency = get_option('nhanhvn_sync_frequency', 'hourly');

    // Schedule product sync
    if ($sync_products === '1') {
      if (!wp_next_scheduled('nhanhvn_sync_products')) {
        wp_schedule_event(time(), $sync_frequency, 'nhanhvn_sync_products');
      }
    } else {
      $timestamp = wp_next_scheduled('nhanhvn_sync_products');
      if ($timestamp) {
        wp_unschedule_event($timestamp, 'nhanhvn_sync_products');
      }
    }

    // Schedule inventory sync
    if ($sync_inventory === '1') {
      if (!wp_next_scheduled('nhanhvn_sync_inventory')) {
        wp_schedule_event(time(), $sync_frequency, 'nhanhvn_sync_inventory');
      }
    } else {
      $timestamp = wp_next_scheduled('nhanhvn_sync_inventory');
      if ($timestamp) {
        wp_unschedule_event($timestamp, 'nhanhvn_sync_inventory');
      }
    }

    // Add cron actions
    add_action('nhanhvn_sync_products', array($this, 'sync_products'));
    add_action('nhanhvn_sync_inventory', array($this, 'sync_inventory'));
  }

  /**
   * Register webhooks.
   *
   * @since    1.0.0
   */
  public function register_webhooks()
  {
    register_rest_route('nhanhvn/v1', '/webhook', array(
      'methods' => 'POST',
      'callback' => array($this, 'handle_webhook'),
      'permission_callback' => '__return_true'
    ));
  }

  /**
   * Handle webhook.
   *
   * @since    1.0.0
   * @param    WP_REST_Request    $request    The request.
   * @return   WP_REST_Response                The response.
   */
  public function handle_webhook($request)
  {
    // Xác thực webhook
    $verify_token = $request->get_param('webhooksVerifyToken');
    $stored_token = get_option('nhanhvn_webhook_verify_token', '');

    if (empty($verify_token) || $verify_token !== $stored_token) {
      return new WP_REST_Response(array(
        'status' => 'error',
        'message' => 'Invalid verify token'
      ), 403);
    }

    // Lấy dữ liệu
    $data = json_decode($request->get_body(), true);
    $event = $request->get_param('event');

    // Log webhook
    error_log('Nhanh.vn Webhook: ' . $event . ' - ' . print_r($data, true));

    // Xử lý sự kiện
    switch ($event) {
      case 'product.update':
        $this->handle_product_update($data);
        break;
      case 'inventory.update':
        $this->handle_inventory_update($data);
        break;
      case 'order.update':
        $this->handle_order_update($data);
        break;
      default:
        // Sự kiện không được hỗ trợ
        return new WP_REST_Response(array(
          'status' => 'error',
          'message' => 'Unsupported event'
        ), 400);
    }

    return new WP_REST_Response(array(
      'status' => 'success',
      'message' => 'Webhook processed successfully'
    ), 200);
  }

  /**
   * Handle product update webhook.
   *
   * @since    1.0.0
   * @param    array    $data    The product data.
   */
  private function handle_product_update($data)
  {
    if (empty($data) || !isset($data['id'])) {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Invalid product data received from webhook');
      return;
    }

    // Lấy thông tin chi tiết sản phẩm
    $response = NhanhVN_API::instance()->get_product($data['id']);

    if (isset($response['data'])) {
      $product_data = $response['data'];
      $result = $this->process_product($product_data);

      if ($result) {
        NhanhVN_Admin::instance()->add_log('webhook', 'success', 'Product updated from webhook: ' . $product_data['name']);
      } else {
        NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Failed to update product from webhook: ' . $product_data['name']);
      }
    } else {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Failed to get product details from Nhanh.vn: ' . $data['id']);
    }
  }

  /**
   * Handle inventory update webhook.
   *
   * @since    1.0.0
   * @param    array    $data    The inventory data.
   */
  private function handle_inventory_update($data)
  {
    if (empty($data) || !isset($data['items']) || !is_array($data['items'])) {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Invalid inventory data received from webhook');
      return;
    }

    $results = array(
      'total' => count($data['items']),
      'synced' => 0,
      'failed' => 0
    );

    foreach ($data['items'] as $item) {
      $product_id = $this->get_product_id_by_nhanh_id($item['productId']);

      if ($product_id) {
        $product = wc_get_product($product_id);

        if ($product) {
          $product->set_stock_quantity($item['quantity']);
          $product->set_stock_status($item['quantity'] > 0 ? 'instock' : 'outofstock');
          $product->save();

          $results['synced']++;
        } else {
          $results['failed']++;
        }
      } else {
        $results['failed']++;
      }
    }

    NhanhVN_Admin::instance()->update_sync_stats('inventory', $results);
    NhanhVN_Admin::instance()->add_log('webhook', 'success', 'Inventory updated from webhook: ' . $results['synced'] . '/' . $results['total'] . ' items');
  }

  /**
   * Handle order update webhook.
   *
   * @since    1.0.0
   * @param    array    $data    The order data.
   */
  private function handle_order_update($data)
  {
    if (empty($data) || !isset($data['id'])) {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Invalid order data received from webhook');
      return;
    }

    // Tìm đơn hàng WooCommerce theo ID Nhanh.vn
    $order_id = $this->get_order_id_by_nhanh_id($data['id']);

    if (!$order_id) {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'Order not found: ' . $data['id']);
      return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
      NhanhVN_Admin::instance()->add_log('webhook', 'error', 'WooCommerce order not found: ' . $order_id);
      return;
    }

    // Cập nhật trạng thái đơn hàng
    if (isset($data['status'])) {
      $wc_status = $this->map_nhanh_status_to_wc($data['status']);
      $order->update_status($wc_status, __('Updated from Nhanh.vn', 'nhanhvn'));

      NhanhVN_Admin::instance()->add_log('webhook', 'success', 'Order status updated from webhook: ' . $order_id . ' -> ' . $wc_status);
    }

    // Cập nhật thông tin vận chuyển nếu có
    if (isset($data['shipmentInfo'])) {
      $tracking_number = isset($data['shipmentInfo']['trackingNumber']) ? $data['shipmentInfo']['trackingNumber'] : '';
      $carrier = isset($data['shipmentInfo']['carrier']) ? $data['shipmentInfo']['carrier'] : '';

      if (!empty($tracking_number)) {
        update_post_meta($order_id, '_nhanhvn_tracking_number', $tracking_number);
        $order->add_order_note(sprintf(__('Tracking number: %s (Carrier: %s)', 'nhanhvn'), $tracking_number, $carrier));
      }
    }
  }

  /**
   * Get product ID by Nhanh.vn ID.
   *
   * @since    1.0.0
   * @param    int    $nhanh_id    The Nhanh.vn product ID.
   * @return   int                 The WooCommerce product ID.
   */
  private function get_product_id_by_nhanh_id($nhanh_id)
  {
    global $wpdb;

    $product_id = $wpdb->get_var($wpdb->prepare(
      "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_nhanhvn_product_id' AND meta_value = %s LIMIT 1",
      $nhanh_id
    ));

    return $product_id ? (int) $product_id : 0;
  }

  /**
   * Get order ID by Nhanh.vn ID.
   *
   * @since    1.0.0
   * @param    int    $nhanh_id    The Nhanh.vn order ID.
   * @return   int                 The WooCommerce order ID.
   */
  private function get_order_id_by_nhanh_id($nhanh_id)
  {
    global $wpdb;

    $order_id = $wpdb->get_var($wpdb->prepare(
      "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_nhanhvn_order_id' AND meta_value = %s LIMIT 1",
      $nhanh_id
    ));

    return $order_id ? (int) $order_id : 0;
  }

  /**
   * Map Nhanh.vn status to WooCommerce status.
   *
   * @since    1.0.0
   * @param    string    $nhanh_status    The Nhanh.vn status.
   * @return   string                     The WooCommerce status.
   */
  private function map_nhanh_status_to_wc($nhanh_status)
  {
    $status_map = array(
      'New' => 'pending',
      'Confirming' => 'processing',
      'Confirmed' => 'processing',
      'Packing' => 'processing',
      'Packed' => 'processing',
      'Shipping' => 'on-hold',
      'Success' => 'completed',
      'Failed' => 'failed',
      'Canceled' => 'cancelled',
      'Aborted' => 'cancelled',
      'Returned' => 'refunded'
    );

    return isset($status_map[$nhanh_status]) ? $status_map[$nhanh_status] : 'pending';
  }

  /**
   * Map WooCommerce status to Nhanh.vn status.
   *
   * @since    1.0.0
   * @param    string    $wc_status    The WooCommerce status.
   * @return   string|false            The Nhanh.vn status or false if not mapped.
   */
  private function map_wc_status_to_nhanh($wc_status)
  {
    $status_map = array(
      'pending' => 'New',
      'processing' => 'Confirming',
      'on-hold' => 'Confirming',
      'completed' => 'Success',
      'cancelled' => 'Canceled',
      'refunded' => 'Returned',
      'failed' => 'Failed'
    );

    return isset($status_map[$wc_status]) ? $status_map[$wc_status] : false;
  }

  /**
   * Sync products from Nhanh.vn to WooCommerce.
   *
   * @since    1.0.0
   */
  public function sync_products()
  {
    // Kiểm tra token
    if (NhanhVN_API::instance()->is_token_expired()) {
      NhanhVN_Admin::instance()->add_log('products', 'error', 'Access token expired or not set');
      return;
    }

    $page = 1;
    $limit = 20;
    $results = array(
      'total' => 0,
      'synced' => 0,
      'failed' => 0
    );

    do {
      $response = NhanhVN_API::instance()->get_products($page, $limit);

      if (!isset($response['data']) || !is_array($response['data'])) {
        $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : 'Unknown error';
        NhanhVN_Admin::instance()->add_log('products', 'error', 'Failed to get products: ' . $error_message);
        break;
      }

      $products = $response['data'];
      $results['total'] += count($products);

      foreach ($products as $product_data) {
        $result = $this->process_product($product_data);

        if ($result) {
          $results['synced']++;
        } else {
          $results['failed']++;
        }
      }

      $page++;

      // Kiểm tra còn trang tiếp theo không
      $has_more = isset($response['totalPages']) && $page <= $response['totalPages'];
    } while ($has_more);

    // Cập nhật thời gian đồng bộ
    update_option('nhanhvn_last_product_sync', time());

    // Cập nhật thống kê
    NhanhVN_Admin::instance()->update_sync_stats('products', $results);

    // Thêm log
    NhanhVN_Admin::instance()->add_log('products', 'success', 'Products synced: ' . $results['synced'] . '/' . $results['total']);
  }

  /**
   * Process a product from Nhanh.vn.
   *
   * @since    1.0.0
   * @param    array    $product_data    The product data.
   * @return   bool                      Whether the product was processed successfully.
   */
  private function process_product($product_data)
  {
    if (empty($product_data) || !isset($product_data['id'])) {
      return false;
    }

    // Kiểm tra sản phẩm đã tồn tại chưa
    $product_id = $this->get_product_id_by_nhanh_id($product_data['id']);

    // Chuẩn bị dữ liệu sản phẩm
    $name = isset($product_data['name']) ? $product_data['name'] : '';
    $description = isset($product_data['description']) ? $product_data['description'] : '';
    $short_description = isset($product_data['shortDescription']) ? $product_data['shortDescription'] : '';
    $sku = isset($product_data['code']) ? $product_data['code'] : '';
    $price = isset($product_data['price']) ? $product_data['price'] : 0;
    $sale_price = isset($product_data['salePrice']) ? $product_data['salePrice'] : '';
    $stock_quantity = isset($product_data['quantity']) ? $product_data['quantity'] : 0;
    $weight = isset($product_data['shippingWeight']) ? $product_data['shippingWeight'] : '';
    $status = isset($product_data['status']) ? $product_data['status'] : 'Active';

    // Xử lý trạng thái sản phẩm
    $product_status = 'publish';
    if ($status === 'Inactive') {
      $product_status = 'draft';
    }

    if ($product_id) {
      // Cập nhật sản phẩm
      $product = wc_get_product($product_id);

      if (!$product) {
        return false;
      }

      $product->set_name($name);
      $product->set_description($description);
      $product->set_short_description($short_description);
      $product->set_sku($sku);
      $product->set_regular_price($price);

      if (!empty($sale_price)) {
        $product->set_sale_price($sale_price);
      }

      $product->set_stock_quantity($stock_quantity);
      $product->set_manage_stock(true);
      $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

      if (!empty($weight)) {
        $product->set_weight($weight);
      }

      $product->set_status($product_status);

      // Lưu sản phẩm
      $product->save();

      // Xử lý hình ảnh
      if (isset($product_data['images']) && is_array($product_data['images'])) {
        $this->process_product_images($product_id, $product_data['images']);
      }

      // Xử lý danh mục
      if (isset($product_data['categoryName'])) {
        $this->process_product_category($product_id, $product_data['categoryName']);
      }

      return true;
    } else {
      // Tạo sản phẩm mới
      $product = new WC_Product();

      $product->set_name($name);
      $product->set_description($description);
      $product->set_short_description($short_description);
      $product->set_sku($sku);
      $product->set_regular_price($price);

      if (!empty($sale_price)) {
        $product->set_sale_price($sale_price);
      }

      $product->set_stock_quantity($stock_quantity);
      $product->set_manage_stock(true);
      $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

      if (!empty($weight)) {
        $product->set_weight($weight);
      }

      $product->set_status($product_status);

      // Lưu sản phẩm
      $product_id = $product->save();

      if (!$product_id) {
        return false;
      }

      // Lưu ID Nhanh.vn
      update_post_meta($product_id, '_nhanhvn_product_id', $product_data['id']);

      // Xử lý hình ảnh
      if (isset($product_data['images']) && is_array($product_data['images'])) {
        $this->process_product_images($product_id, $product_data['images']);
      }

      // Xử lý danh mục
      if (isset($product_data['categoryName'])) {
        $this->process_product_category($product_id, $product_data['categoryName']);
      }

      return true;
    }
  }

  /**
   * Process product images.
   *
   * @since    1.0.0
   * @param    int      $product_id    The product ID.
   * @param    array    $images        The images.
   */
  private function process_product_images($product_id, $images)
  {
    if (empty($images)) {
      return;
    }

    $product = wc_get_product($product_id);

    if (!$product) {
      return;
    }

    $attachment_ids = array();

    foreach ($images as $index => $image_url) {
      // Kiểm tra URL hình ảnh
      if (empty($image_url)) {
        continue;
      }

      // Tạo tên file từ URL
      $filename = basename($image_url);

      // Kiểm tra hình ảnh đã tồn tại chưa
      $attachment_id = $this->get_attachment_id_by_url($image_url);

      if (!$attachment_id) {
        // Tải hình ảnh
        $upload = $this->upload_image_from_url($image_url);

        if (is_wp_error($upload)) {
          continue;
        }

        // Tạo attachment
        $attachment_id = $this->create_image_attachment($upload, $product_id);

        if (!$attachment_id) {
          continue;
        }
      }

      $attachment_ids[] = $attachment_id;
    }

    if (!empty($attachment_ids)) {
      // Đặt hình ảnh đầu tiên làm hình ảnh chính
      $product->set_image_id($attachment_ids[0]);

      // Đặt các hình ảnh còn lại làm hình ảnh phụ
      if (count($attachment_ids) > 1) {
        $gallery_ids = array_slice($attachment_ids, 1);
        $product->set_gallery_image_ids($gallery_ids);
      }

      $product->save();
    }
  }

  /**
   * Upload image from URL.
   *
   * @since    1.0.0
   * @param    string    $url    The image URL.
   * @return   array|WP_Error    The upload data or error.
   */
  private function upload_image_from_url($url)
  {
    require_once(ABSPATH . 'wp-admin/includes/file.php');

    $temp_file = download_url($url);

    if (is_wp_error($temp_file)) {
      return $temp_file;
    }

    $file = array(
      'name' => basename($url),
      'tmp_name' => $temp_file
    );

    $upload = wp_handle_sideload(
      $file,
      array('test_form' => false)
    );

    if (isset($upload['error'])) {
      @unlink($temp_file);
      return new WP_Error('upload_error', $upload['error']);
    }

    return $upload;
  }

  /**
   * Create image attachment.
   *
   * @since    1.0.0
   * @param    array    $upload       The upload data.
   * @param    int      $product_id    The product ID.
   * @return   int|false              The attachment ID or false on failure.
   */
  private function create_image_attachment($upload, $product_id)
  {
    $attachment = array(
      'guid' => $upload['url'],
      'post_mime_type' => $upload['type'],
      'post_title' => basename($upload['file']),
      'post_content' => '',
      'post_status' => 'inherit'
    );

    $attachment_id = wp_insert_attachment($attachment, $upload['file'], $product_id);

    if (!$attachment_id) {
      return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    return $attachment_id;
  }

  /**
   * Get attachment ID by URL.
   *
   * @since    1.0.0
   * @param    string    $url    The attachment URL.
   * @return   int|false         The attachment ID or false if not found.
   */
  private function get_attachment_id_by_url($url)
  {
    global $wpdb;

    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $url));

    return isset($attachment[0]) ? $attachment[0] : false;
  }

  /**
   * Process product category.
   *
   * @since    1.0.0
   * @param    int      $product_id     The product ID.
   * @param    string   $category_name  The category name.
   */
  private function process_product_category($product_id, $category_name)
  {
    if (empty($category_name)) {
      return;
    }

    // Tìm hoặc tạo danh mục
    $term = term_exists($category_name, 'product_cat');

    if (!$term) {
      $term = wp_insert_term($category_name, 'product_cat');
    }

    if (is_wp_error($term)) {
      return;
    }

    $term_id = is_array($term) ? $term['term_id'] : $term;

    // Gán danh mục cho sản phẩm
    wp_set_object_terms($product_id, $term_id, 'product_cat');
  }

  /**
   * Sync inventory from Nhanh.vn to WooCommerce.
   *
   * @since    1.0.0
   */
  public function sync_inventory()
  {
    // Kiểm tra token
    if (NhanhVN_API::instance()->is_token_expired()) {
      NhanhVN_Admin::instance()->add_log('inventory', 'error', 'Access token expired or not set');
      return;
    }

    $page = 1;
    $limit = 50;
    $results = array(
      'total' => 0,
      'synced' => 0,
      'failed' => 0
    );

    do {
      $response = NhanhVN_API::instance()->get_inventory($page, $limit);

      if (!isset($response['data']) || !is_array($response['data'])) {
        $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : 'Unknown error';
        NhanhVN_Admin::instance()->add_log('inventory', 'error', 'Failed to get inventory: ' . $error_message);
        break;
      }

      $items = $response['data'];
      $results['total'] += count($items);

      foreach ($items as $item) {
        $product_id = $this->get_product_id_by_nhanh_id($item['productId']);

        if ($product_id) {
          $product = wc_get_product($product_id);

          if ($product) {
            $product->set_stock_quantity($item['quantity']);
            $product->set_stock_status($item['quantity'] > 0 ? 'instock' : 'outofstock');
            $product->save();

            $results['synced']++;
          } else {
            $results['failed']++;
          }
        } else {
          $results['failed']++;
        }
      }

      $page++;

      // Kiểm tra còn trang tiếp theo không
      $has_more = isset($response['totalPages']) && $page <= $response['totalPages'];
    } while ($has_more);

    // Cập nhật thời gian đồng bộ
    update_option('nhanhvn_last_inventory_sync', time());

    // Cập nhật thống kê
    NhanhVN_Admin::instance()->update_sync_stats('inventory', $results);

    // Thêm log
    NhanhVN_Admin::instance()->add_log('inventory', 'success', 'Inventory synced: ' . $results['synced'] . '/' . $results['total']);
  }

  /**
   * Sync order to Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int    $order_id    The order ID.
   */
  public function sync_order_to_nhanh($order_id)
  {
    // Kiểm tra đồng bộ đơn hàng có được bật không
    $sync_orders = get_option('nhanhvn_sync_orders', '1');

    if ($sync_orders !== '1') {
      return;
    }

    // Kiểm tra token
    if (NhanhVN_API::instance()->is_token_expired()) {
      NhanhVN_Admin::instance()->add_log('orders', 'error', 'Access token expired or not set');
      return;
    }

    // Gửi đơn hàng lên Nhanh.vn
    $result = NhanhVN_API::instance()->send_order($order_id);

    if ($result) {
      NhanhVN_Admin::instance()->add_log('orders', 'success', 'Order synced to Nhanh.vn: #' . $order_id);

      // Cập nhật thống kê
      $stats = get_option('nhanhvn_sync_stats', [
        'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
        'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
        'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
      ]);

      $stats['orders']['total']++;
      $stats['orders']['synced']++;

      update_option('nhanhvn_sync_stats', $stats);
    } else {
      NhanhVN_Admin::instance()->add_log('orders', 'error', 'Failed to sync order to Nhanh.vn: #' . $order_id);

      // Cập nhật thống kê
      $stats = get_option('nhanhvn_sync_stats', [
        'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
        'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
        'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
      ]);

      $stats['orders']['total']++;
      $stats['orders']['failed']++;

      update_option('nhanhvn_sync_stats', $stats);
    }
  }

  /**
   * Sync order status to Nhanh.vn.
   *
   * @since    1.0.0
   * @param    int       $order_id     The order ID.
   * @param    string    $old_status    The old status.
   * @param    string    $new_status    The new status.
   */
  public function sync_order_status_to_nhanh($order_id, $old_status, $new_status)
  {
    // Kiểm tra đồng bộ đơn hàng có được bật không
    $sync_orders = get_option('nhanhvn_sync_orders', '1');

    if ($sync_orders !== '1') {
      return;
    }

    // Kiểm tra token
    if (NhanhVN_API::instance()->is_token_expired()) {
      NhanhVN_Admin::instance()->add_log('orders', 'error', 'Access token expired or not set');
      return;
    }

    // Kiểm tra đơn hàng đã được đồng bộ với Nhanh.vn chưa
    $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);

    if (!$nhanh_order_id) {
      // Đơn hàng chưa được đồng bộ, thử đồng bộ ngay
      $result = NhanhVN_API::instance()->send_order($order_id);

      if (!$result) {
        NhanhVN_Admin::instance()->add_log('orders', 'error', 'Failed to sync order to Nhanh.vn: #' . $order_id);
        return;
      }

      $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);
    }

    // Ánh xạ trạng thái WooCommerce sang trạng thái Nhanh.vn
    $nhanh_status = $this->map_wc_status_to_nhanh($new_status);

    if (!$nhanh_status) {
      return; // Không có trạng thái tương ứng
    }

    // Chuẩn bị dữ liệu
    $data = [
      'id' => $nhanh_order_id,
      'status' => $nhanh_status
    ];

    // Gọi API để cập nhật trạng thái
    $response = NhanhVN_API::instance()->send_request('/api/order/update-status', $data);

    if (isset($response['code']) && $response['code'] == 1) {
      NhanhVN_Admin::instance()->add_log('orders', 'success', 'Order status synced to Nhanh.vn: #' . $order_id . ' -> ' . $nhanh_status);
    } else {
      $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : 'Unknown error';
      NhanhVN_Admin::instance()->add_log('orders', 'error', 'Failed to sync order status to Nhanh.vn: #' . $order_id . ' - ' . $error_message);
    }
  }

  /**
   * Add manual sync buttons to admin page.
   *
   * @since    1.0.0
   */
  public function add_manual_sync_buttons()
  {
    // Kiểm tra token
    if (NhanhVN_API::instance()->is_token_expired()) {
      echo '<div class="notice notice-warning inline"><p>' . __('Access token expired or not set. Please reconnect to Nhanh.vn.', 'nhanhvn') . '</p></div>';
      return;
    }

    ?>
    <div class="nhanhvn-manual-sync">
      <h3><?php _e('Manual Synchronization', 'nhanhvn'); ?></h3>
      <p><?php _e('Use these buttons to manually trigger synchronization with Nhanh.vn', 'nhanhvn'); ?></p>

      <button id="nhanhvn-sync-products-btn" class="button button-primary">
        <?php _e('Sync Products', 'nhanhvn'); ?>
      </button>

      <button id="nhanhvn-sync-inventory-btn" class="button button-primary">
        <?php _e('Sync Inventory', 'nhanhvn'); ?>
      </button>

      <script>
        jQuery(document).ready(function ($) {
          $('#nhanhvn-sync-products-btn').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text('<?php _e('Syncing...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_manual_sync_products',
                nonce: '<?php echo wp_create_nonce('nhanhvn_manual_sync'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                } else {
                  alert(response.data.message || 'Error syncing products');
                }
              },
              error: function () {
                alert('<?php _e('Error syncing products', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Sync Products', 'nhanhvn'); ?>');
              }
            });
          });

          $('#nhanhvn-sync-inventory-btn').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text('<?php _e('Syncing...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_manual_sync_inventory',
                nonce: '<?php echo wp_create_nonce('nhanhvn_manual_sync'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                } else {
                  alert(response.data.message || 'Error syncing inventory');
                }
              },
              error: function () {
                alert('<?php _e('Error syncing inventory', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Sync Inventory', 'nhanhvn'); ?>');
              }
            });
          });
        });
      </script>
    </div>
    <?php
  }

  /**
   * Register AJAX handlers for manual sync.
   *
   * @since    1.0.0
   */
  public function register_ajax_handlers()
  {
    add_action('wp_ajax_nhanhvn_manual_sync_products', array($this, 'ajax_sync_products'));
    add_action('wp_ajax_nhanhvn_manual_sync_inventory', array($this, 'ajax_sync_inventory'));
  }

  /**
   * AJAX handler for manual product sync.
   *
   * @since    1.0.0
   */
  public function ajax_sync_products()
  {
    check_ajax_referer('nhanhvn_manual_sync', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    // Thực hiện đồng bộ sản phẩm
    $this->sync_products();

    // Lấy thống kê đồng bộ
    $stats = get_option('nhanhvn_sync_stats', [
      'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
    ]);

    wp_send_json_success(array(
      'message' => sprintf(
        __('Products synced: %d/%d', 'nhanhvn'),
        $stats['products']['synced'],
        $stats['products']['total']
      ),
      'stats' => $stats['products']
    ));
  }

  /**
   * AJAX handler for manual inventory sync.
   *
   * @since    1.0.0
   */
  public function ajax_sync_inventory()
  {
    check_ajax_referer('nhanhvn_manual_sync', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    // Thực hiện đồng bộ tồn kho
    $this->sync_inventory();

    // Lấy thống kê đồng bộ
    $stats = get_option('nhanhvn_sync_stats', [
      'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
    ]);

    wp_send_json_success(array(
      'message' => sprintf(
        __('Inventory synced: %d/%d', 'nhanhvn'),
        $stats['inventory']['synced'],
        $stats['inventory']['total']
      ),
      'stats' => $stats['inventory']
    ));
  }

  /**
   * Display sync status on product edit page.
   *
   * @since    1.0.0
   * @param    WC_Product    $product    The product.
   */
  public function display_product_sync_status($product)
  {
    $product_id = $product->get_id();
    $nhanh_product_id = get_post_meta($product_id, '_nhanhvn_product_id', true);

    if ($nhanh_product_id) {
      echo '<div class="options_group">';
      echo '<p class="form-field">';
      echo '<label>' . __('Nhanh.vn ID', 'nhanhvn') . '</label>';
      echo '<span>' . esc_html($nhanh_product_id) . '</span>';
      echo '</p>';

      // Hiển thị thời gian đồng bộ cuối cùng
      $last_sync = get_post_meta($product_id, '_nhanhvn_last_sync', true);
      if ($last_sync) {
        echo '<p class="form-field">';
        echo '<label>' . __('Last synced', 'nhanhvn') . '</label>';
        echo '<span>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync) . '</span>';
        echo '</p>';
      }

      echo '</div>';
    }
  }

  /**
   * Display sync status on order edit page.
   *
   * @since    1.0.0
   * @param    WC_Order    $order    The order.
   */
  public function display_order_sync_status($order)
  {
    $order_id = $order->get_id();
    $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);

    if ($nhanh_order_id) {
      echo '<div class="options_group">';
      echo '<p class="form-field">';
      echo '<label>' . __('Nhanh.vn ID', 'nhanhvn') . '</label>';
      echo '<span>' . esc_html($nhanh_order_id) . '</span>';
      echo '</p>';

      // Hiển thị thông tin vận chuyển
      $tracking_number = get_post_meta($order_id, '_nhanhvn_tracking_number', true);
      if ($tracking_number) {
        echo '<p class="form-field">';
        echo '<label>' . __('Tracking Number', 'nhanhvn') . '</label>';
        echo '<span>' . esc_html($tracking_number) . '</span>';
        echo '</p>';
      }

      echo '</div>';
    }
  }

  /**
   * Add meta box to product edit page.
   *
   * @since    1.0.0
   */
  public function add_product_meta_box()
  {
    add_meta_box(
      'nhanhvn_product_sync',
      __('Nhanh.vn Sync', 'nhanhvn'),
      array($this, 'render_product_meta_box'),
      'product',
      'side',
      'default'
    );
  }

  /**
   * Render product meta box.
   *
   * @since    1.0.0
   * @param    WP_Post    $post    The post.
   */
  public function render_product_meta_box($post)
  {
    $product_id = $post->ID;
    $nhanh_product_id = get_post_meta($product_id, '_nhanhvn_product_id', true);

    if ($nhanh_product_id) {
      echo '<p>';
      echo '<strong>' . __('Nhanh.vn ID', 'nhanhvn') . ':</strong> ';
      echo esc_html($nhanh_product_id);
      echo '</p>';

      // Hiển thị thời gian đồng bộ cuối cùng
      $last_sync = get_post_meta($product_id, '_nhanhvn_last_sync', true);
      if ($last_sync) {
        echo '<p>';
        echo '<strong>' . __('Last synced', 'nhanhvn') . ':</strong> ';
        echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync);
        echo '</p>';
      }

      echo '<button id="nhanhvn-sync-single-product" class="button" data-product-id="' . esc_attr($product_id) . '">';
      echo __('Sync Now', 'nhanhvn');
      echo '</button>';

      ?>
      <script>
        jQuery(document).ready(function ($) {
          $('#nhanhvn-sync-single-product').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text('<?php _e('Syncing...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_sync_single_product',
                product_id: $button.data('product-id'),
                nonce: '<?php echo wp_create_nonce('nhanhvn_sync_single_product'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                } else {
                  alert(response.data.message || 'Error syncing product');
                }
              },
              error: function () {
                alert('<?php _e('Error syncing product', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Sync Now', 'nhanhvn'); ?>');
              }
            });
          });
        });
      </script>
      <?php
    } else {
      echo '<p>' . __('This product is not linked to Nhanh.vn.', 'nhanhvn') . '</p>';

      echo '<button id="nhanhvn-link-product" class="button" data-product-id="' . esc_attr($product_id) . '">';
      echo __('Link to Nhanh.vn', 'nhanhvn');
      echo '</button>';

      ?>
      <script>
        jQuery(document).ready(function ($) {
          $('#nhanhvn-link-product').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            var productId = prompt('<?php _e('Enter Nhanh.vn Product ID:', 'nhanhvn'); ?>');

            if (!productId) {
              return;
            }

            $button.prop('disabled', true).text('<?php _e('Linking...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_link_product',
                product_id: $button.data('product-id'),
                nhanh_product_id: productId,
                nonce: '<?php echo wp_create_nonce('nhanhvn_link_product'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                  location.reload();
                } else {
                  alert(response.data.message || 'Error linking product');
                }
              },
              error: function () {
                alert('<?php _e('Error linking product', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Link to Nhanh.vn', 'nhanhvn'); ?>');
              }
            });
          });
        });
      </script>
      <?php
    }
  }

  /**
   * AJAX handler for syncing a single product.
   *
   * @since    1.0.0
   */
  public function ajax_sync_single_product()
  {
    check_ajax_referer('nhanhvn_sync_single_product', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id) {
      wp_send_json_error(array('message' => __('Invalid product ID.', 'nhanhvn')));
      return;
    }

    $nhanh_product_id = get_post_meta($product_id, '_nhanhvn_product_id', true);

    if (!$nhanh_product_id) {
      wp_send_json_error(array('message' => __('This product is not linked to Nhanh.vn.', 'nhanhvn')));
      return;
    }

    // Lấy thông tin sản phẩm từ Nhanh.vn
    $response = NhanhVN_API::instance()->get_product($nhanh_product_id);

    if (!isset($response['data'])) {
      $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : __('Unknown error', 'nhanhvn');
      wp_send_json_error(array('message' => __('Failed to get product from Nhanh.vn: ', 'nhanhvn') . $error_message));
      return;
    }

    // Xử lý sản phẩm
    $result = $this->process_product($response['data']);

    if ($result) {
      // Cập nhật thời gian đồng bộ
      update_post_meta($product_id, '_nhanhvn_last_sync', time());

      wp_send_json_success(array('message' => __('Product successfully synced from Nhanh.vn.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to process product data.', 'nhanhvn')));
    }
  }

  /**
   * AJAX handler for linking a product to Nhanh.vn.
   *
   * @since    1.0.0
   */
  public function ajax_link_product()
  {
    check_ajax_referer('nhanhvn_link_product', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $nhanh_product_id = isset($_POST['nhanh_product_id']) ? sanitize_text_field($_POST['nhanh_product_id']) : '';

    if (!$product_id || !$nhanh_product_id) {
      wp_send_json_error(array('message' => __('Invalid product ID or Nhanh.vn product ID.', 'nhanhvn')));
      return;
    }

    // Kiểm tra sản phẩm tồn tại trong Nhanh.vn
    $response = NhanhVN_API::instance()->get_product($nhanh_product_id);

    if (!isset($response['data'])) {
      $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : __('Unknown error', 'nhanhvn');
      wp_send_json_error(array('message' => __('Failed to get product from Nhanh.vn: ', 'nhanhvn') . $error_message));
      return;
    }

    // Lưu ID Nhanh.vn
    update_post_meta($product_id, '_nhanhvn_product_id', $nhanh_product_id);

    // Xử lý sản phẩm
    $result = $this->process_product($response['data']);

    if ($result) {
      // Cập nhật thời gian đồng bộ
      update_post_meta($product_id, '_nhanhvn_last_sync', time());

      wp_send_json_success(array('message' => __('Product successfully linked to Nhanh.vn and synced.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Product linked to Nhanh.vn but failed to sync data.', 'nhanhvn')));
    }
  }

  /**
   * Add meta box to order edit page.
   *
   * @since    1.0.0
   */
  public function add_order_meta_box()
  {
    add_meta_box(
      'nhanhvn_order_sync',
      __('Nhanh.vn Sync', 'nhanhvn'),
      array($this, 'render_order_meta_box'),
      'shop_order',
      'side',
      'default'
    );
  }

  /**
   * Render order meta box.
   *
   * @since    1.0.0
   * @param    WP_Post    $post    The post.
   */
  public function render_order_meta_box($post)
  {
    $order_id = $post->ID;
    $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);

    if ($nhanh_order_id) {
      echo '<p>';
      echo '<strong>' . __('Nhanh.vn ID', 'nhanhvn') . ':</strong> ';
      echo esc_html($nhanh_order_id);
      echo '</p>';

      // Hiển thị thông tin vận chuyển
      $tracking_number = get_post_meta($order_id, '_nhanhvn_tracking_number', true);
      if ($tracking_number) {
        echo '<p>';
        echo '<strong>' . __('Tracking Number', 'nhanhvn') . ':</strong> ';
        echo esc_html($tracking_number);
        echo '</p>';
      }

      echo '<button id="nhanhvn-sync-order-status" class="button" data-order-id="' . esc_attr($order_id) . '">';
      echo __('Sync Status', 'nhanhvn');
      echo '</button>';

      ?>
      <script>
        jQuery(document).ready(function ($) {
          $('#nhanhvn-sync-order-status').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text('<?php _e('Syncing...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_sync_order_status',
                order_id: $button.data('order-id'),
                nonce: '<?php echo wp_create_nonce('nhanhvn_sync_order_status'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                } else {
                  alert(response.data.message || 'Error syncing order status');
                }
              },
              error: function () {
                alert('<?php _e('Error syncing order status', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Sync Status', 'nhanhvn'); ?>');
              }
            });
          });
        });
      </script>
      <?php
    } else {
      echo '<p>' . __('This order is not linked to Nhanh.vn.', 'nhanhvn') . '</p>';

      echo '<button id="nhanhvn-send-order" class="button" data-order-id="' . esc_attr($order_id) . '">';
      echo __('Send to Nhanh.vn', 'nhanhvn');
      echo '</button>';

      ?>
      <script>
        jQuery(document).ready(function ($) {
          $('#nhanhvn-send-order').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text('<?php _e('Sending...', 'nhanhvn'); ?>');

            $.ajax({
              url: ajaxurl,
              type: 'POST',
              data: {
                action: 'nhanhvn_send_order',
                order_id: $button.data('order-id'),
                nonce: '<?php echo wp_create_nonce('nhanhvn_send_order'); ?>'
              },
              success: function (response) {
                if (response.success) {
                  alert(response.data.message);
                  location.reload();
                } else {
                  alert(response.data.message || 'Error sending order');
                }
              },
              error: function () {
                alert('<?php _e('Error sending order', 'nhanhvn'); ?>');
              },
              complete: function () {
                $button.prop('disabled', false).text('<?php _e('Send to Nhanh.vn', 'nhanhvn'); ?>');
              }
            });
          });
        });
      </script>
      <?php
    }
  }

  /**
   * AJAX handler for syncing order status.
   *
   * @since    1.0.0
   */
  public function ajax_sync_order_status()
  {
    check_ajax_referer('nhanhvn_sync_order_status', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
      wp_send_json_error(array('message' => __('Invalid order ID.', 'nhanhvn')));
      return;
    }

    $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);

    if (!$nhanh_order_id) {
      wp_send_json_error(array('message' => __('This order is not linked to Nhanh.vn.', 'nhanhvn')));
      return;
    }

    // Lấy thông tin đơn hàng từ Nhanh.vn
    $response = NhanhVN_API::instance()->send_request('/api/order/detail', ['id' => $nhanh_order_id]);

    if (!isset($response['data'])) {
      $error_message = isset($response['messages']) ? implode(', ', $response['messages']) : __('Unknown error', 'nhanhvn');
      wp_send_json_error(array('message' => __('Failed to get order from Nhanh.vn: ', 'nhanhvn') . $error_message));
      return;
    }

    $order_data = $response['data'];

    // Cập nhật trạng thái đơn hàng
    $order = wc_get_order($order_id);

    if (!$order) {
      wp_send_json_error(array('message' => __('Order not found.', 'nhanhvn')));
      return;
    }

    if (isset($order_data['status'])) {
      $wc_status = $this->map_nhanh_status_to_wc($order_data['status']);
      $order->update_status($wc_status, __('Updated from Nhanh.vn', 'nhanhvn'));

      NhanhVN_Admin::instance()->add_log('orders', 'success', 'Order status updated from Nhanh.vn: ' . $order_id . ' -> ' . $wc_status);

      wp_send_json_success(array('message' => __('Order status successfully synced from Nhanh.vn.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('No status information found in the response.', 'nhanhvn')));
    }
  }

  /**
   * AJAX handler for sending an order to Nhanh.vn.
   *
   * @since    1.0.0
   */
  public function ajax_send_order()
  {
    check_ajax_referer('nhanhvn_send_order', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
      wp_send_json_error(array('message' => __('Invalid order ID.', 'nhanhvn')));
      return;
    }

    // Gửi đơn hàng lên Nhanh.vn
    $result = NhanhVN_API::instance()->send_order($order_id);

    if ($result) {
      NhanhVN_Admin::instance()->add_log('orders', 'success', 'Order manually sent to Nhanh.vn: #' . $order_id);

      wp_send_json_success(array('message' => __('Order successfully sent to Nhanh.vn.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to send order to Nhanh.vn.', 'nhanhvn')));
    }
  }

  /**
   * Add bulk action to products list.
   *
   * @since    1.0.0
   * @param    array    $actions    The bulk actions.
   * @return   array                The modified bulk actions.
   */
  public function add_product_bulk_actions($actions)
  {
    $actions['nhanhvn_sync_products'] = __('Sync with Nhanh.vn', 'nhanhvn');
    return $actions;
  }

  /**
   * Handle product bulk actions.
   *
   * @since    1.0.0
   * @param    string    $redirect_to    The redirect URL.
   * @param    string    $action         The action.
   * @param    array     $post_ids       The post IDs.
   * @return   string                    The modified redirect URL.
   */
  public function handle_product_bulk_actions($redirect_to, $action, $post_ids)
  {
    if ($action !== 'nhanhvn_sync_products') {
      return $redirect_to;
    }

    $synced = 0;
    $failed = 0;

    foreach ($post_ids as $post_id) {
      $nhanh_product_id = get_post_meta($post_id, '_nhanhvn_product_id', true);

      if (!$nhanh_product_id) {
        $failed++;
        continue;
      }

      // Lấy thông tin sản phẩm từ Nhanh.vn
      $response = NhanhVN_API::instance()->get_product($nhanh_product_id);

      if (!isset($response['data'])) {
        $failed++;
        continue;
      }

      // Xử lý sản phẩm
      $result = $this->process_product($response['data']);

      if ($result) {
        // Cập nhật thời gian đồng bộ
        update_post_meta($post_id, '_nhanhvn_last_sync', time());
        $synced++;
      } else {
        $failed++;
      }
    }

    // Thêm thông báo
    $redirect_to = add_query_arg(array(
      'nhanhvn_synced' => $synced,
      'nhanhvn_failed' => $failed
    ), $redirect_to);

    return $redirect_to;
  }

  /**
   * Display admin notices for bulk actions.
   *
   * @since    1.0.0
   */
  public function display_bulk_action_notices()
  {
    if (!empty($_GET['nhanhvn_synced']) || !empty($_GET['nhanhvn_failed'])) {
      $synced = isset($_GET['nhanhvn_synced']) ? intval($_GET['nhanhvn_synced']) : 0;
      $failed = isset($_GET['nhanhvn_failed']) ? intval($_GET['nhanhvn_failed']) : 0;

      if ($synced > 0) {
        echo '<div class="notice notice-success is-dismissible"><p>';
        printf(_n(
          '%d product successfully synced with Nhanh.vn.',
          '%d products successfully synced with Nhanh.vn.',
          $synced,
          'nhanhvn'
        ), $synced);
        echo '</p></div>';
      }

      if ($failed > 0) {
        echo '<div class="notice notice-error is-dismissible"><p>';
        printf(_n(
          '%d product failed to sync with Nhanh.vn.',
          '%d products failed to sync with Nhanh.vn.',
          $failed,
          'nhanhvn'
        ), $failed);
        echo '</p></div>';
      }
    }
  }

  /**
   * Add bulk action to orders list.
   *
   * @since    1.0.0
   * @param    array    $actions    The bulk actions.
   * @return   array                The modified bulk actions.
   */
  public function add_order_bulk_actions($actions)
  {
    $actions['nhanhvn_send_orders'] = __('Send to Nhanh.vn', 'nhanhvn');
    return $actions;
  }

  /**
   * Handle order bulk actions.
   *
   * @since    1.0.0
   * @param    string    $redirect_to    The redirect URL.
   * @param    string    $action         The action.
   * @param    array     $post_ids       The post IDs.
   * @return   string                    The modified redirect URL.
   */
  public function handle_order_bulk_actions($redirect_to, $action, $post_ids)
  {
    if ($action !== 'nhanhvn_send_orders') {
      return $redirect_to;
    }

    $sent = 0;
    $failed = 0;

    foreach ($post_ids as $post_id) {
      // Gửi đơn hàng lên Nhanh.vn
      $result = NhanhVN_API::instance()->send_order($post_id);

      if ($result) {
        $sent++;
      } else {
        $failed++;
      }
    }

    // Thêm thông báo
    $redirect_to = add_query_arg(array(
      'nhanhvn_sent' => $sent,
      'nhanhvn_failed' => $failed
    ), $redirect_to);

    return $redirect_to;
  }

  /**
   * Display admin notices for order bulk actions.
   *
   * @since    1.0.0
   */
  public function display_order_bulk_action_notices()
  {
    if (!empty($_GET['nhanhvn_sent']) || !empty($_GET['nhanhvn_failed'])) {
      $sent = isset($_GET['nhanhvn_sent']) ? intval($_GET['nhanhvn_sent']) : 0;
      $failed = isset($_GET['nhanhvn_failed']) ? intval($_GET['nhanhvn_failed']) : 0;

      if ($sent > 0) {
        echo '<div class="notice notice-success is-dismissible"><p>';
        printf(_n(
          '%d order successfully sent to Nhanh.vn.',
          '%d orders successfully sent to Nhanh.vn.',
          $sent,
          'nhanhvn'
        ), $sent);
        echo '</p></div>';
      }

      if ($failed > 0) {
        echo '<div class="notice notice-error is-dismissible"><p>';
        printf(_n(
          '%d order failed to send to Nhanh.vn.',
          '%d orders failed to send to Nhanh.vn.',
          $failed,
          'nhanhvn'
        ), $failed);
        echo '</p></div>';
      }
    }
  }

  /**
   * Add custom column to products list.
   *
   * @since    1.0.0
   * @param    array    $columns    The columns.
   * @return   array                The modified columns.
   */
  public function add_product_column($columns)
  {
    $new_columns = array();

    foreach ($columns as $key => $value) {
      $new_columns[$key] = $value;

      // Add column after SKU
      if ($key === 'sku') {
        $new_columns['nhanhvn_sync'] = __('Nhanh.vn', 'nhanhvn');
      }
    }

    return $new_columns;
  }

  /**
   * Display custom column content for products.
   *
   * @since    1.0.0
   * @param    string    $column     The column name.
   * @param    int       $post_id    The post ID.
   */
  public function display_product_column($column, $post_id)
  {
    if ($column !== 'nhanhvn_sync') {
      return;
    }

    $nhanh_product_id = get_post_meta($post_id, '_nhanhvn_product_id', true);

    if ($nhanh_product_id) {
      echo '<mark class="yes" style="color: #7ad03a;"><span class="dashicons dashicons-yes"></span></mark>';
      echo '<span class="nhanhvn-id" style="display: block; font-size: 0.8em; color: #999;">' . esc_html($nhanh_product_id) . '</span>';
    } else {
      echo '<mark class="no" style="color: #a00;"><span class="dashicons dashicons-no"></span></mark>';
    }
  }

  /**
   * Add custom column to orders list.
   *
   * @since    1.0.0
   * @param    array    $columns    The columns.
   * @return   array                The modified columns.
   */
  public function add_order_column($columns)
  {
    $new_columns = array();

    foreach ($columns as $key => $value) {
      $new_columns[$key] = $value;

      // Add column after order status
      if ($key === 'order_status') {
        $new_columns['nhanhvn_sync'] = __('Nhanh.vn', 'nhanhvn');
      }
    }

    return $new_columns;
  }

  /**
   * Display custom column content for orders.
   *
   * @since    1.0.0
   * @param    string    $column    The column name.
   */
  public function display_order_column($column)
  {
    global $post;

    if ($column !== 'nhanhvn_sync') {
      return;
    }

    $order_id = $post->ID;
    $nhanh_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);

    if ($nhanh_order_id) {
      echo '<mark class="yes" style="color: #7ad03a;"><span class="dashicons dashicons-yes"></span></mark>';
      echo '<span class="nhanhvn-id" style="display: block; font-size: 0.8em; color: #999;">' . esc_html($nhanh_order_id) . '</span>';
    } else {
      echo '<mark class="no" style="color: #a00;"><span class="dashicons dashicons-no"></span></mark>';
    }
  }

  /**
   * Add custom filter to products list.
   *
   * @since    1.0.0
   */
  public function add_product_filter()
  {
    global $typenow;

    if ($typenow !== 'product') {
      return;
    }

    $current = isset($_GET['nhanhvn_filter']) ? $_GET['nhanhvn_filter'] : '';
    ?>
    <select name="nhanhvn_filter">
      <option value=""><?php _e('Nhanh.vn Sync', 'nhanhvn'); ?></option>
      <option value="synced" <?php selected($current, 'synced'); ?>><?php _e('Synced', 'nhanhvn'); ?></option>
      <option value="not_synced" <?php selected($current, 'not_synced'); ?>><?php _e('Not Synced', 'nhanhvn'); ?></option>
    </select>
    <?php
  }

  /**
   * Filter products by Nhanh.vn sync status.
   *
   * @since    1.0.0
   * @param    WP_Query    $query    The query.
   */
  public function filter_products($query)
  {
    global $typenow, $pagenow;

    if ($pagenow !== 'edit.php' || $typenow !== 'product' || !isset($_GET['nhanhvn_filter']) || empty($_GET['nhanhvn_filter'])) {
      return;
    }

    $meta_query = $query->get('meta_query');

    if (!is_array($meta_query)) {
      $meta_query = array();
    }

    if ($_GET['nhanhvn_filter'] === 'synced') {
      $meta_query[] = array(
        'key' => '_nhanhvn_product_id',
        'compare' => 'EXISTS'
      );
    } elseif ($_GET['nhanhvn_filter'] === 'not_synced') {
      $meta_query[] = array(
        'key' => '_nhanhvn_product_id',
        'compare' => 'NOT EXISTS'
      );
    }

    $query->set('meta_query', $meta_query);
  }

  /**
   * Add custom filter to orders list.
   *
   * @since    1.0.0
   */
  public function add_order_filter()
  {
    global $typenow;

    if ($typenow !== 'shop_order') {
      return;
    }

    $current = isset($_GET['nhanhvn_filter']) ? $_GET['nhanhvn_filter'] : '';
    ?>
    <select name="nhanhvn_filter">
      <option value=""><?php _e('Nhanh.vn Sync', 'nhanhvn'); ?></option>
      <option value="synced" <?php selected($current, 'synced'); ?>><?php _e('Synced', 'nhanhvn'); ?></option>
      <option value="not_synced" <?php selected($current, 'not_synced'); ?>><?php _e('Not Synced', 'nhanhvn'); ?></option>
    </select>
    <?php
  }

  /**
   * Filter orders by Nhanh.vn sync status.
   *
   * @since    1.0.0
   * @param    WP_Query    $query    The query.
   */
  public function filter_orders($query)
  {
    global $typenow, $pagenow;

    if ($pagenow !== 'edit.php' || $typenow !== 'shop_order' || !isset($_GET['nhanhvn_filter']) || empty($_GET['nhanhvn_filter'])) {
      return;
    }

    $meta_query = $query->get('meta_query');

    if (!is_array($meta_query)) {
      $meta_query = array();
    }

    if ($_GET['nhanhvn_filter'] === 'synced') {
      $meta_query[] = array(
        'key' => '_nhanhvn_order_id',
        'compare' => 'EXISTS'
      );
    } elseif ($_GET['nhanhvn_filter'] === 'not_synced') {
      $meta_query[] = array(
        'key' => '_nhanhvn_order_id',
        'compare' => 'NOT EXISTS'
      );
    }

    $query->set('meta_query', $meta_query);
  }

  /**
   * Add tracking information to order emails.
   *
   * @since    1.0.0
   * @param    WC_Order    $order         The order.
   * @param    bool        $sent_to_admin Whether the email is sent to admin.
   * @param    bool        $plain_text    Whether the email is plain text.
   */
  public function add_tracking_to_emails($order, $sent_to_admin, $plain_text)
  {
    if ($sent_to_admin) {
      return;
    }

    $order_id = $order->get_id();
    $tracking_number = get_post_meta($order_id, '_nhanhvn_tracking_number', true);

    if (!$tracking_number) {
      return;
    }

    if ($plain_text) {
      echo "\n\n" . __('Tracking Information', 'nhanhvn') . "\n";
      echo __('Tracking Number', 'nhanhvn') . ': ' . $tracking_number . "\n";
    } else {
      echo '<h2>' . __('Tracking Information', 'nhanhvn') . '</h2>';
      echo '<p><strong>' . __('Tracking Number', 'nhanhvn') . ':</strong> ' . esc_html($tracking_number) . '</p>';
    }
  }

  /**
   * Add tracking information to order details page.
   *
   * @since    1.0.0
   * @param    WC_Order    $order    The order.
   */
  public function add_tracking_to_order_details($order)
  {
    $order_id = $order->get_id();
    $tracking_number = get_post_meta($order_id, '_nhanhvn_tracking_number', true);

    if (!$tracking_number) {
      return;
    }

    echo '<h2>' . __('Tracking Information', 'nhanhvn') . '</h2>';
    echo '<p><strong>' . __('Tracking Number', 'nhanhvn') . ':</strong> ' . esc_html($tracking_number) . '</p>';
  }

  /**
   * Check for rate limits and handle them.
   *
   * @since    1.0.0
   * @param    array    $response    The API response.
   * @return   bool                  Whether the request was rate limited.
   */
  public function check_rate_limit($response)
  {
    if (isset($response['errorCode']) && $response['errorCode'] == 429) {
      // Rate limit reached
      $unlocked_at = isset($response['unlockedAt']) ? strtotime($response['unlockedAt']) : time() + 60;

      // Store the time when we can make requests again
      update_option('nhanhvn_rate_limit_unlocked_at', $unlocked_at);

      // Log the rate limit
      NhanhVN_Admin::instance()->add_log('api', 'warning', 'Rate limit reached. Requests will be paused until ' . date('Y-m-d H:i:s', $unlocked_at));

      return true;
    }

    return false;
  }

  /**
   * Check if the API is currently rate limited.
   *
   * @since    1.0.0
   * @return   bool    Whether the API is rate limited.
   */
  public function is_rate_limited()
  {
    $unlocked_at = get_option('nhanhvn_rate_limit_unlocked_at', 0);

    if ($unlocked_at > time()) {
      return true;
    }

    return false;
  }

  /**
   * Get the remaining wait time for rate limit.
   *
   * @since    1.0.0
   * @return   int    The remaining wait time in seconds.
   */
  public function get_rate_limit_wait_time()
  {
    $unlocked_at = get_option('nhanhvn_rate_limit_unlocked_at', 0);

    if ($unlocked_at > time()) {
      return $unlocked_at - time();
    }

    return 0;
  }

  /**
   * Handle debug logging.
   *
   * @since    1.0.0
   * @param    string    $message    The message to log.
   * @param    string    $level      The log level.
   */
  public function log($message, $level = 'info')
  {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
      return;
    }

    $log_file = NHANHVN_PLUGIN_DIR . 'logs/sync-' . date('Y-m-d') . '.log';

    // Create logs directory if it doesn't exist
    if (!file_exists(NHANHVN_PLUGIN_DIR . 'logs')) {
      mkdir(NHANHVN_PLUGIN_DIR . 'logs', 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [{$level}] {$message}\n";

    error_log($log_message, 3, $log_file);
  }
}
