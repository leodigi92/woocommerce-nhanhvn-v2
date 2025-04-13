<?php
/*
Plugin Name: WooCommerce - Nhanh.vn Integration
Description: Kết nối WooCommerce với Nhanh.vn
Version: 1.0.0
Author: Thắng Nguyễn - Leodigi.dev
*/

if (!defined('ABSPATH')) {
  exit;
}

define('NHANHVN_VERSION', '1.0.0');
define('NHANHVN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NHANHVN_PLUGIN_URL', plugin_dir_url(__FILE__));

class WC_NhanhVN
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
    $this->includes();
    $this->init_hooks();
  }

  private function includes()
  {
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-api.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-settings.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-product.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-order.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-inventory.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-webhook.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-shipping.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-cron.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-install.php';
    require_once NHANHVN_PLUGIN_DIR . 'includes/class-nhanhvn-sync.php';

    require_once NHANHVN_PLUGIN_DIR . 'admin/class-nhanhvn-admin.php';
    require_once NHANHVN_PLUGIN_DIR . 'frontend/class-nhanhvn-frontend.php';
  }

  private function init_hooks()
  {
    add_action('plugins_loaded', array($this, 'check_woocommerce'));
    add_action('init', array($this, 'init'));
    register_activation_hook(__FILE__, array('NhanhVN_Install', 'install'));
    register_deactivation_hook(__FILE__, array($this, 'deactivate'));
  }

  public function check_woocommerce()
  {
    if (!class_exists('WooCommerce')) {
      add_action('admin_notices', function () {
        echo '<div class="error"><p>WooCommerce - Nhanh.vn Integration yêu cầu cài đặt và kích hoạt WooCommerce.</p></div>';
      });
      return;
    }
  }

  public function init()
  {
    NhanhVN_API::instance();
    NhanhVN_Settings::instance();
    NhanhVN_Product::instance();
    NhanhVN_Order::instance();
    NhanhVN_Inventory::instance();
    NhanhVN_Webhook::instance();
    NhanhVN_Cron::instance();
    NhanhVN_Admin::instance();
    NhanhVN_Frontend::instance();
    NhanhVN_Sync::instance();
  }

  public function deactivate()
  {
    wp_clear_scheduled_hook('nhanhvn_product_sync');
    wp_clear_scheduled_hook('nhanhvn_sync_inventory');
    flush_rewrite_rules();
  }
}

function WC_NhanhVN()
{
  return WC_NhanhVN::instance();
}

WC_NhanhVN();
