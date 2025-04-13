<?php
class NhanhVN_Product
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
    $this->schedule_sync();
  }

  public function schedule_sync()
  {
    if (!wp_next_scheduled('nhanhvn_product_sync')) {
      wp_schedule_event(time(), 'daily', 'nhanhvn_product_sync');
    }
    add_action('nhanhvn_product_sync', array($this, 'sync_products'));
  }

  public function sync_products($params = array())
  {
    $api = NhanhVN_API::instance();
    $page = isset($params['page']) ? $params['page'] : 1;
    $per_page = 50;
    $sync_images = isset($params['sync_images']) ? $params['sync_images'] : false;
    $sync_categories = isset($params['sync_categories']) ? $params['sync_categories'] : false;
    $sync_inventory = isset($params['sync_inventory']) ? $params['sync_inventory'] : false;

    $response = $api->get_products(array(
      'page' => $page,
      'perPage' => $per_page
    ));

    if (is_wp_error($response)) {
      return new WP_Error('sync_error', 'Product sync failed: ' . $response->get_error_message());
    }

    $products = $response['data']['products'];
    foreach ($products as $product_data) {
      $this->update_or_create_product($product_data, $sync_images, $sync_categories);
    }

    $total_pages = $response['data']['totalPages'];
    $progress = ($page / $total_pages) * 100;

    return array(
      'success' => true,
      'data' => array(
        'current' => $page * $per_page,
        'total' => $response['data']['totalRecords'],
        'progress' => $progress,
        'message' => "Đã đồng bộ trang $page/$total_pages",
        'more' => $page < $total_pages
      )
    );
  }

  public function update_or_create_product($data, $sync_images = false, $sync_categories = false)
  {
    $product_id = wc_get_product_id_by_sku($data['code']);
    $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();

    $product->set_name($data['name']);
    $product->set_sku($data['code']);
    $product->set_regular_price($data['price']);
    $product->set_description($data['description'] ?? '');
    $product->set_stock_quantity($data['inventory']['available'] ?? 0);
    $product->set_manage_stock(true);

    if ($sync_images && !empty($data['images'])) {
      $this->handle_product_image($product, $data['images'][0]);
    }

    $product_id = $product->save();
    update_post_meta($product_id, '_nhanhvn_id', $data['id']);
    update_post_meta($product_id, '_nhanhvn_last_sync', current_time('mysql'));
  }

  private function handle_product_image($product, $image_url)
  {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) {
      return false;
    }

    $file_array = array(
      'name' => basename($image_url),
      'tmp_name' => $tmp
    );

    $id = media_handle_sideload($file_array, $product->get_id());
    if (is_wp_error($id)) {
      @unlink($file_array['tmp_name']);
      return false;
    }

    $product->set_image_id($id);
  }

  private function log_error($message)
  {
    global $wpdb;
    $wpdb->insert(
      $wpdb->prefix . 'nhanhvn_sync_log',
      array(
        'type' => 'product_sync',
        'status' => 'error',
        'message' => $message
      ),
      array('%s', '%s', '%s')
    );
  }
}
