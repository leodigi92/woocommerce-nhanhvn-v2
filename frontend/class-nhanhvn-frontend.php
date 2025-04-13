<?php
class NhanhVN_Frontend
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
    add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    add_filter('woocommerce_get_availability_text', array($this, 'modify_stock_status_text'), 10, 2);
    add_action('woocommerce_after_shop_loop_item', array($this, 'add_shipping_estimate'));
  }

  public function enqueue_scripts()
  {
    if (is_product() || is_cart() || is_checkout()) {
      wp_enqueue_style('nhanhvn-frontend', NHANHVN_PLUGIN_URL . 'assets/css/frontend.css', array(), NHANHVN_VERSION);
      wp_enqueue_script('nhanhvn-frontend', NHANHVN_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), NHANHVN_VERSION, true);

      wp_localize_script('nhanhvn-frontend', 'nhanhvn', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('nhanhvn-frontend')
      ));
    }
  }

  public function modify_stock_status_text($availability, $product)
  {
    if ($product->is_in_stock()) {
      $stock_quantity = $product->get_stock_quantity();
      if ($stock_quantity > 0) {
        return sprintf(__('Còn %d sản phẩm', 'woocommerce-nhanhvn'), $stock_quantity);
      }
    }
    return $availability;
  }

  public function add_shipping_estimate()
  {
    if (get_option('nhanhvn_enable_shipping')) {
      $api = NhanhVN_API::instance();
      $estimate = $api->get_shipping_estimate();
      if (!is_wp_error($estimate)) {
        echo '<div class="nhanhvn-shipping-estimate">';
        echo sprintf(__('Thời gian giao hàng dự kiến: %s', 'woocommerce-nhanhvn'), $estimate);
        echo '</div>';
      }
    }
  }
}
