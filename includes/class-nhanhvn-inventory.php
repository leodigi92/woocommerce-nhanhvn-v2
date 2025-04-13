<?php
class NhanhVN_Inventory
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
    $this->init_hooks();
  }

  private function init_hooks()
  {
    add_action('woocommerce_product_set_stock', array($this, 'sync_stock_to_nhanh'));
    add_action('woocommerce_variation_set_stock', array($this, 'sync_stock_to_nhanh'));
    add_action('nhanhvn_sync_inventory', array($this, 'sync_inventory_from_nhanh'));
    add_filter('woocommerce_product_data_tabs', array($this, 'add_inventory_tab'));
    add_action('woocommerce_product_data_panels', array($this, 'add_inventory_panel'));
  }

  public function sync_stock_to_nhanh($product)
  {
    if (!get_option('nhanhvn_auto_update_stock')) {
      return;
    }

    $api = NhanhVN_API::instance();
    $data = array(
      'productId' => get_post_meta($product->get_id(), '_nhanhvn_id', true),
      'quantity' => $product->get_stock_quantity(),
      'warehouseId' => get_option('nhanhvn_default_warehouse')
    );

    $response = $api->update_inventory($data);
    if (is_wp_error($response)) {
      $this->log_error('Failed to sync stock to Nhanh.vn: ' . $response->get_error_message());
    }
  }

  public function sync_inventory_from_nhanh()
  {
    $api = NhanhVN_API::instance();
    $page = 1;
    $per_page = 100;

    do {
      $response = $api->get_inventory(array(
        'page' => $page,
        'limit' => $per_page,
        'warehouseId' => get_option('nhanhvn_default_warehouse')
      ));

      if (is_wp_error($response)) {
        $this->log_error('Inventory sync failed: ' . $response->get_error_message());
        break;
      }

      foreach ($response['data'] as $inventory) {
        $this->update_product_stock($inventory);
      }

      $page++;
    } while (count($response['data']) === $per_page);
  }

  private function update_product_stock($inventory_data)
  {
    $product_id = wc_get_product_id_by_sku($inventory_data['productCode']);
    if (!$product_id) {
      return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
      return;
    }

    wc_update_product_stock($product, $inventory_data['quantity']);
    update_post_meta($product_id, '_nhanhvn_last_stock_sync', current_time('mysql'));
  }

  public function add_inventory_tab($tabs)
  {
    $tabs['nhanhvn_inventory'] = array(
      'label' => 'Nhanh.vn Inventory',
      'target' => 'nhanhvn_inventory_data',
      'class' => array()
    );
    return $tabs;
  }

  public function add_inventory_panel()
  {
    global $post;
    ?>
    <div id="nhanhvn_inventory_data" class="panel woocommerce_options_panel">
      <?php
      $product = wc_get_product($post->ID);
      $nhanhvn_id = get_post_meta($post->ID, '_nhanhvn_id', true);
      $last_sync = get_post_meta($post->ID, '_nhanhvn_last_stock_sync', true);
      ?>
      <div class="options_group">
        <p><strong>Nhanh.vn ID:</strong> <?php echo $nhanhvn_id; ?></p>
        <p><strong>Last Sync:</strong> <?php echo $last_sync; ?></p>
        <button type="button" class="button sync-inventory" data-product-id="<?php echo $post->ID; ?>">
          Sync Inventory Now
        </button>
      </div>
    </div>
    <?php
  }

  private function log_error($message)
  {
    global $wpdb;
    $wpdb->insert(
      $wpdb->prefix . 'nhanhvn_sync_log',
      array(
        'type' => 'inventory_sync',
        'status' => 'error',
        'message' => $message
      ),
      array('%s', '%s', '%s')
    );
  }
}
