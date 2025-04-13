<?php
class NhanhVN_Cron
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
    add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    add_action('nhanhvn_sync_products', array($this, 'do_product_sync'));
    add_action('nhanhvn_sync_inventory', array($this, 'do_inventory_sync'));
  }

  public function add_cron_schedules($schedules)
  {
    $schedules['every_15_minutes'] = array(
      'interval' => 900,
      'display' => __('Every 15 minutes', 'woocommerce-nhanhvn')
    );
    return $schedules;
  }

  public function do_product_sync()
  {
    $product = NhanhVN_Product::instance();
    $product->sync_products();
  }

  public function do_inventory_sync()
  {
    $inventory = NhanhVN_Inventory::instance();
    $inventory->sync_inventory_from_nhanh();
  }
}
