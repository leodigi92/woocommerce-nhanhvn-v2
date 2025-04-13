<?php
class NhanhVN_Order
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
    add_action('woocommerce_order_status_changed', array($this, 'sync_order_to_nhanh'), 10, 3);
    add_action('woocommerce_checkout_order_processed', array($this, 'create_nhanh_order'));
    add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
  }

  public function create_nhanh_order($order_id)
  {
    $order = wc_get_order($order_id);
    $api = NhanhVN_API::instance();

    $order_data = array(
      'id' => $order_id,
      'customerName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
      'customerPhone' => $order->get_billing_phone(),
      'customerAddress' => $order->get_billing_address_1(),
      'customerCity' => $order->get_billing_city(),
      'totalAmount' => $order->get_total(),
      'products' => array()
    );

    foreach ($order->get_items() as $item) {
      $product = $item->get_product();
      $order_data['products'][] = array(
        'code' => $product->get_sku(),
        'quantity' => $item->get_quantity(),
        'price' => $item->get_total()
      );
    }

    $response = $api->create_order($order_data);

    if (!is_wp_error($response)) {
      update_post_meta($order_id, '_nhanhvn_order_id', $response['data']['id']);
      update_post_meta($order_id, '_nhanhvn_order_status', $response['data']['status']);
    }
  }

  public function sync_order_to_nhanh($order_id, $old_status, $new_status)
  {
    // Xử lý cập nhật trạng thái đơn hàng lên Nhanh.vn
  }

  public function add_order_meta_box()
  {
    add_meta_box(
      'nhanhvn-order-info',
      'Thông tin Nhanh.vn',
      array($this, 'render_order_meta_box'),
      'shop_order',
      'side',
      'high'
    );
  }

  public function render_order_meta_box($post)
  {
    $order_id = $post->ID;
    $nhanhvn_order_id = get_post_meta($order_id, '_nhanhvn_order_id', true);
    $nhanhvn_status = get_post_meta($order_id, '_nhanhvn_order_status', true);
    ?>
    <div class="nhanhvn-order-info">
      <p><strong>Mã đơn Nhanh.vn:</strong> <?php echo $nhanhvn_order_id; ?></p>
      <p><strong>Trạng thái:</strong> <?php echo $nhanhvn_status; ?></p>
      <button type="button" class="button sync-order" data-order-id="<?php echo $order_id; ?>">
        Đồng bộ đơn hàng
      </button>
    </div>
    <?php
  }
}
