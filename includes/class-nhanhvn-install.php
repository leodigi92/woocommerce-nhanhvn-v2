<?php
class NhanhVN_Install
{
  public static function install()
  {
    self::create_tables();
    self::update_tables(); // Thêm hàm để cập nhật bảng nếu cần
    self::create_options();
    self::schedule_cron_jobs();
    flush_rewrite_rules();
  }

  private static function create_tables()
  {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'nhanhvn_sync_log';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            data TEXT NULL, -- Thêm cột data để lưu dữ liệu JSON
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
  }

  private static function update_tables()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nhanhvn_sync_log';

    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'data'");
    if (empty($column_exists)) {
      $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `data` TEXT NULL AFTER `message`");
    }
  }

  private static function create_options()
  {
    add_option('nhanhvn_version', NHANHVN_VERSION);
    add_option('nhanhvn_webhook_token', wp_generate_password(32, false));
    add_option('nhanhvn_sync_product_images', '1');
    add_option('nhanhvn_sync_product_categories', '1');
    add_option('nhanhvn_auto_update_stock', '1');
    add_option('nhanhvn_enable_shipping', '1');
  }

  private static function schedule_cron_jobs()
  {
    if (!wp_next_scheduled('nhanhvn_sync_products')) {
      wp_schedule_event(time(), 'daily', 'nhanhvn_sync_products');
    }
    if (!wp_next_scheduled('nhanhvn_sync_inventory')) {
      wp_schedule_event(time(), 'hourly', 'nhanhvn_sync_inventory');
    }
  }
}
