<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://leodigi.com
 * @since      1.0.0
 *
 * @package    Nhanhvn
 * @subpackage Nhanhvn/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Nhanhvn
 * @subpackage Nhanhvn/admin
 * @author     Leo Digi <leodigi92@gmail.com>
 */
class Nhanhvn_Admin
{

  /**
   * Singleton instance.
   *
   * @since    1.0.0
   * @access   private
   * @var      Nhanhvn_Admin    $instance    The singleton instance.
   */
  private static $instance = null;

  /**
   * Get singleton instance.
   *
   * @since    1.0.0
   * @return   Nhanhvn_Admin    The singleton instance.
   */
  public static function instance()
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

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

    // Add admin menu
    add_action('admin_menu', array($this, 'add_admin_menu'));

    // Register settings
    add_action('admin_init', array($this, 'register_settings'));

    // Add admin notices
    add_action('admin_notices', array($this, 'admin_notices'));

    // Handle OAuth callback
    add_action('admin_init', array($this, 'handle_oauth_callback'));

    // Add meta boxes
    add_action('add_meta_boxes', array($this, 'add_meta_boxes'));

    // Save meta box data
    add_action('save_post', array($this, 'save_meta_box_data'));

    // Add custom columns to products list
    add_filter('manage_edit-product_columns', array($this, 'add_product_columns'));
    add_action('manage_product_posts_custom_column', array($this, 'render_product_columns'), 10, 2);

    // Add custom columns to orders list
    add_filter('manage_edit-shop_order_columns', array($this, 'add_order_columns'));
    add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_columns'), 10, 2);

    // Add AJAX handlers
    add_action('wp_ajax_nhanhvn_sync_products', array($this, 'ajax_sync_products'));
    add_action('wp_ajax_nhanhvn_sync_inventory', array($this, 'ajax_sync_inventory'));
    add_action('wp_ajax_nhanhvn_sync_single_product', array($this, 'ajax_sync_single_product'));
    add_action('wp_ajax_nhanhvn_sync_order', array($this, 'ajax_sync_order'));
  }

  /**
   * Register the stylesheets for the admin area.
   *
   * @since    1.0.0
   */
  public function enqueue_styles()
  {
    wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/nhanhvn-admin.css', array(), $this->version, 'all');
  }

  /**
   * Register the JavaScript for the admin area.
   *
   * @since    1.0.0
   */
  public function enqueue_scripts()
  {
    wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/nhanhvn-admin.js', array('jquery'), $this->version, false);

    wp_localize_script($this->plugin_name, 'nhanhvn_admin', array(
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('nhanhvn_admin_nonce'),
      'syncing_text' => __('Syncing...', 'nhanhvn'),
      'sync_complete_text' => __('Sync Complete', 'nhanhvn'),
      'sync_error_text' => __('Sync Error', 'nhanhvn')
    ));
  }

  /**
   * Add admin menu.
   *
   * @since    1.0.0
   */
  public function add_admin_menu()
  {
    // Main menu
    add_menu_page(
      __('Nhanh.vn Integration', 'nhanhvn'),
      __('Nhanh.vn', 'nhanhvn'),
      'manage_options',
      'nhanhvn-settings',
      array($this, 'render_settings_page'),
      'dashicons-cart',
      56
    );

    // Settings submenu
    add_submenu_page(
      'nhanhvn-settings',
      __('Settings', 'nhanhvn'),
      __('Settings', 'nhanhvn'),
      'manage_options',
      'nhanhvn-settings',
      array($this, 'render_settings_page')
    );

    // Sync status submenu
    add_submenu_page(
      'nhanhvn-settings',
      __('Sync Status', 'nhanhvn'),
      __('Sync Status', 'nhanhvn'),
      'manage_options',
      'nhanhvn-sync-status',
      array($this, 'render_sync_status_page')
    );

    // Logs submenu
    add_submenu_page(
      'nhanhvn-settings',
      __('Logs', 'nhanhvn'),
      __('Logs', 'nhanhvn'),
      'manage_options',
      'nhanhvn-logs',
      array($this, 'render_logs_page')
    );
  }

  /**
   * Register settings.
   *
   * @since    1.0.0
   */
  public function register_settings()
  {
    // API Settings
    register_setting('nhanhvn_api_settings', 'nhanhvn_app_id');
    register_setting('nhanhvn_api_settings', 'nhanhvn_secret_key');
    register_setting('nhanhvn_api_settings', 'nhanhvn_business_id');
    register_setting('nhanhvn_api_settings', 'nhanhvn_webhook_verify_token');

    add_settings_section(
      'nhanhvn_api_section',
      __('API Settings', 'nhanhvn'),
      array($this, 'render_api_section'),
      'nhanhvn_api_settings'
    );

    add_settings_field(
      'nhanhvn_app_id',
      __('App ID', 'nhanhvn'),
      array($this, 'render_app_id_field'),
      'nhanhvn_api_settings',
      'nhanhvn_api_section'
    );

    add_settings_field(
      'nhanhvn_secret_key',
      __('Secret Key', 'nhanhvn'),
      array($this, 'render_secret_key_field'),
      'nhanhvn_api_settings',
      'nhanhvn_api_section'
    );

    add_settings_field(
      'nhanhvn_business_id',
      __('Business ID', 'nhanhvn'),
      array($this, 'render_business_id_field'),
      'nhanhvn_api_settings',
      'nhanhvn_api_section'
    );

    add_settings_field(
      'nhanhvn_webhook_verify_token',
      __('Webhook Verify Token', 'nhanhvn'),
      array($this, 'render_webhook_verify_token_field'),
      'nhanhvn_api_settings',
      'nhanhvn_api_section'
    );

    // Sync Settings
    register_setting('nhanhvn_sync_settings', 'nhanhvn_sync_products');
    register_setting('nhanhvn_sync_settings', 'nhanhvn_sync_orders');
    register_setting('nhanhvn_sync_settings', 'nhanhvn_sync_inventory');
    register_setting('nhanhvn_sync_settings', 'nhanhvn_sync_frequency');

    add_settings_section(
      'nhanhvn_sync_section',
      __('Synchronization Settings', 'nhanhvn'),
      array($this, 'render_sync_section'),
      'nhanhvn_sync_settings'
    );

    add_settings_field(
      'nhanhvn_sync_products',
      __('Sync Products', 'nhanhvn'),
      array($this, 'render_sync_products_field'),
      'nhanhvn_sync_settings',
      'nhanhvn_sync_section'
    );

    add_settings_field(
      'nhanhvn_sync_orders',
      __('Sync Orders', 'nhanhvn'),
      array($this, 'render_sync_orders_field'),
      'nhanhvn_sync_settings',
      'nhanhvn_sync_section'
    );

    add_settings_field(
      'nhanhvn_sync_inventory',
      __('Sync Inventory', 'nhanhvn'),
      array($this, 'render_sync_inventory_field'),
      'nhanhvn_sync_settings',
      'nhanhvn_sync_section'
    );

    add_settings_field(
      'nhanhvn_sync_frequency',
      __('Sync Frequency', 'nhanhvn'),
      array($this, 'render_sync_frequency_field'),
      'nhanhvn_sync_settings',
      'nhanhvn_sync_section'
    );
  }

  /**
   * Render API section.
   *
   * @since    1.0.0
   */
  public function render_api_section()
  {
    echo '<p>' . __('Enter your Nhanh.vn API credentials below.', 'nhanhvn') . '</p>';
  }

  /**
   * Render App ID field.
   *
   * @since    1.0.0
   */
  public function render_app_id_field()
  {
    $app_id = get_option('nhanhvn_app_id', '');
    echo '<input type="text" name="nhanhvn_app_id" value="' . esc_attr($app_id) . '" class="regular-text">';
  }

  /**
   * Render Secret Key field.
   *
   * @since    1.0.0
   */
  public function render_secret_key_field()
  {
    $secret_key = get_option('nhanhvn_secret_key', '');
    echo '<input type="password" name="nhanhvn_secret_key" value="' . esc_attr($secret_key) . '" class="regular-text">';
  }

  /**
   * Render Business ID field.
   *
   * @since    1.0.0
   */
  public function render_business_id_field()
  {
    $business_id = get_option('nhanhvn_business_id', '');
    echo '<input type="text" name="nhanhvn_business_id" value="' . esc_attr($business_id) . '" class="regular-text" ' . (empty($business_id) ? '' : 'readonly') . '>';
    echo '<p class="description">' . __('This will be automatically filled after connecting to Nhanh.vn.', 'nhanhvn') . '</p>';
  }

  /**
   * Render Webhook Verify Token field.
   *
   * @since    1.0.0
   */
  public function render_webhook_verify_token_field()
  {
    $webhook_verify_token = get_option('nhanhvn_webhook_verify_token', '');

    if (empty($webhook_verify_token)) {
      $webhook_verify_token = wp_generate_password(16, false);
      update_option('nhanhvn_webhook_verify_token', $webhook_verify_token);
    }

    echo '<input type="text" name="nhanhvn_webhook_verify_token" value="' . esc_attr($webhook_verify_token) . '" class="regular-text">';
    echo '<p class="description">' . __('Use this token when setting up webhooks in Nhanh.vn.', 'nhanhvn') . '</p>';

    // Display webhook URL
    $webhook_url = home_url('/wp-json/nhanhvn/v1/webhook');
    echo '<p>' . __('Webhook URL:', 'nhanhvn') . ' <code>' . $webhook_url . '</code></p>';
  }

  /**
   * Render Sync section.
   *
   * @since    1.0.0
   */
  public function render_sync_section()
  {
    echo '<p>' . __('Configure how data should be synchronized between WordPress and Nhanh.vn.', 'nhanhvn') . '</p>';
  }

  /**
   * Render Sync Products field.
   *
   * @since    1.0.0
   */
  public function render_sync_products_field()
  {
    $sync_products = get_option('nhanhvn_sync_products', '1');
    echo '<input type="checkbox" name="nhanhvn_sync_products" value="1" ' . checked('1', $sync_products, false) . '>';
    echo '<span class="description">' . __('Sync products from Nhanh.vn to WooCommerce.', 'nhanhvn') . '</span>';
  }

  /**
   * Render Sync Orders field.
   *
   * @since    1.0.0
   */
  public function render_sync_orders_field()
  {
    $sync_orders = get_option('nhanhvn_sync_orders', '1');
    echo '<input type="checkbox" name="nhanhvn_sync_orders" value="1" ' . checked('1', $sync_orders, false) . '>';
    echo '<span class="description">' . __('Sync orders from WooCommerce to Nhanh.vn.', 'nhanhvn') . '</span>';
  }

  /**
   * Render Sync Inventory field.
   *
   * @since    1.0.0
   */
  public function render_sync_inventory_field()
  {
    $sync_inventory = get_option('nhanhvn_sync_inventory', '1');
    echo '<input type="checkbox" name="nhanhvn_sync_inventory" value="1" ' . checked('1', $sync_inventory, false) . '>';
    echo '<span class="description">' . __('Sync inventory from Nhanh.vn to WooCommerce.', 'nhanhvn') . '</span>';
  }

  /**
   * Render Sync Frequency field.
   *
   * @since    1.0.0
   */
  public function render_sync_frequency_field()
  {
    $sync_frequency = get_option('nhanhvn_sync_frequency', 'hourly');

    $frequencies = array(
      'hourly' => __('Hourly', 'nhanhvn'),
      'twicedaily' => __('Twice Daily', 'nhanhvn'),
      'daily' => __('Daily', 'nhanhvn')
    );

    echo '<select name="nhanhvn_sync_frequency">';

    foreach ($frequencies as $value => $label) {
      echo '<option value="' . esc_attr($value) . '" ' . selected($sync_frequency, $value, false) . '>' . esc_html($label) . '</option>';
    }

    echo '</select>';
  }

  /**
   * Render settings page.
   *
   * @since    1.0.0
   */
  public function render_settings_page()
  {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
    ?>
        <div class="wrap">
          <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

          <h2 class="nav-tab-wrapper">
            <a href="?page=nhanhvn-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>"><?php _e('API Settings', 'nhanhvn'); ?></a>
            <a href="?page=nhanhvn-settings&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>"><?php _e('Sync Settings', 'nhanhvn'); ?></a>
          </h2>

          <form method="post" action="options.php">
            <?php
            if ($active_tab === 'api') {
              settings_fields('nhanhvn_api_settings');
              do_settings_sections('nhanhvn_api_settings');

              // Display OAuth connection button if App ID and Secret Key are set
              $app_id = get_option('nhanhvn_app_id');
              $secret_key = get_option('nhanhvn_secret_key');

              if (!empty($app_id) && !empty($secret_key)) {
                echo '<div class="nhanhvn-oauth-section">';
                echo '<h3>' . __('Connect to Nhanh.vn', 'nhanhvn') . '</h3>';
                echo '<p>' . __('Click the button below to connect your WordPress site to Nhanh.vn.', 'nhanhvn') . '</p>';
                echo '<a href="' . esc_url($this->api->get_login_url()) . '" class="button button-primary">' . __('Connect to Nhanh.vn', 'nhanhvn') . '</a>';
                echo '</div>';
              }
            } else {
              settings_fields('nhanhvn_sync_settings');
              do_settings_sections('nhanhvn_sync_settings');

              // Display manual sync buttons
              echo '<div class="nhanhvn-manual-sync-section">';
              echo '<h3>' . __('Manual Synchronization', 'nhanhvn') . '</h3>';
              echo '<p>' . __('Use these buttons to manually trigger synchronization with Nhanh.vn', 'nhanhvn') . '</p>';
              echo '<button id="nhanhvn-sync-products" class="button button-primary">' . __('Sync Products', 'nhanhvn') . '</button> ';
              echo '<button id="nhanhvn-sync-orders" class="button button-primary">' . __('Sync Orders', 'nhanhvn') . '</button> ';
              echo '<button id="nhanhvn-sync-inventory" class="button button-primary">' . __('Sync Inventory', 'nhanhvn') . '</button>';
              echo '</div>';
            }

            submit_button();
            ?>
          </form>
              </div>
        <?php
  }
  /**
   * Render sync status page.
   *
   * @since    1.0.0
   */
  public function render_sync_status_page()
  {
    $last_product_sync = get_option('nhanhvn_last_product_sync', 0);
    $last_order_sync = get_option('nhanhvn_last_order_sync', 0);
    $last_inventory_sync = get_option('nhanhvn_last_inventory_sync', 0);

    $sync_stats = get_option('nhanhvn_sync_stats', [
      'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
    ]);

    ?>
      <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="nhanhvn-sync-status">
          <h2><?php _e('Synchronization Status', 'nhanhvn'); ?></h2>

          <table class="widefat">
            <thead>
              <tr>
                <th><?php _e('Type', 'nhanhvn'); ?></th>
                <th><?php _e('Last Sync', 'nhanhvn'); ?></th>
                <th><?php _e('Total', 'nhanhvn'); ?></th>
                <th><?php _e('Synced', 'nhanhvn'); ?></th>
                <th><?php _e('Failed', 'nhanhvn'); ?></th>
                <th><?php _e('Actions', 'nhanhvn'); ?></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><?php _e('Products', 'nhanhvn'); ?></td>
                <td><?php echo $last_product_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_product_sync) : __('Never', 'nhanhvn'); ?></td>
                <td><?php echo $sync_stats['products']['total']; ?></td>
                <td><?php echo $sync_stats['products']['synced']; ?></td>
                <td><?php echo $sync_stats['products']['failed']; ?></td>
                <td><button id="nhanhvn-sync-products-status" class="button"><?php _e('Sync Now', 'nhanhvn'); ?></button></td>
              </tr>
              <tr>
                <td><?php _e('Orders', 'nhanhvn'); ?></td>
                <td><?php echo $last_order_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_order_sync) : __('Never', 'nhanhvn'); ?></td>
                <td><?php echo $sync_stats['orders']['total']; ?></td>
                <td><?php echo $sync_stats['orders']['synced']; ?></td>
                <td><?php echo $sync_stats['orders']['failed']; ?></td>
                <td><button id="nhanhvn-sync-orders-status" class="button"><?php _e('Sync Now', 'nhanhvn'); ?></button></td>
              </tr>
              <tr>
                <td><?php _e('Inventory', 'nhanhvn'); ?></td>
                <td><?php echo $last_inventory_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_inventory_sync) : __('Never', 'nhanhvn'); ?></td>
                <td><?php echo $sync_stats['inventory']['total']; ?></td>
                <td><?php echo $sync_stats['inventory']['synced']; ?></td>
                <td><?php echo $sync_stats['inventory']['failed']; ?></td>
                <td><button id="nhanhvn-sync-inventory-status" class="button"><?php _e('Sync Now', 'nhanhvn'); ?></button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <?php
  }

  /**
   * Render logs page.
   *
   * @since    1.0.0
   */
  public function render_logs_page()
  {
    $logs = get_option('nhanhvn_sync_logs', []);
    ?>
      <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <div class="nhanhvn-logs">
          <h2><?php _e('Synchronization Logs', 'nhanhvn'); ?></h2>

          <?php if (empty($logs)): ?>
              <p><?php _e('No logs found.', 'nhanhvn'); ?></p>
          <?php else: ?>
              <table class="widefat">
                <thead>
                  <tr>
                    <th><?php _e('Time', 'nhanhvn'); ?></th>
                    <th><?php _e('Type', 'nhanhvn'); ?></th>
                    <th><?php _e('Status', 'nhanhvn'); ?></th>
                    <th><?php _e('Message', 'nhanhvn'); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $log): ?>
                      <tr class="<?php echo $log['status'] === 'success' ? 'nhanhvn-success' : 'nhanhvn-error'; ?>">
                        <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['time']); ?></td>
                        <td><?php echo esc_html($log['type']); ?></td>
                        <td><?php echo esc_html($log['status']); ?></td>
                        <td><?php echo esc_html($log['message']); ?></td>
                      </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <p>
                <button id="nhanhvn-clear-logs" class="button"><?php _e('Clear Logs', 'nhanhvn'); ?></button>
              </p>
          <?php endif; ?>
        </div>
      </div>
      <?php
  }

  /**
   * Handle OAuth callback.
   *
   * @since    1.0.0
   */
  public function handle_oauth_callback()
  {
    if (
      isset($_GET['page']) && $_GET['page'] === 'nhanhvn-settings' &&
      isset($_GET['tab']) && $_GET['tab'] === 'api' &&
      isset($_GET['action']) && $_GET['action'] === 'oauth_callback' &&
      isset($_GET['accessCode'])
    ) {

      $access_code = sanitize_text_field($_GET['accessCode']);
      $result = Nhanhvn_Api::instance()->handle_oauth_callback($access_code);

      if ($result) {
        add_settings_error('nhanhvn_settings', 'nhanhvn_oauth_success', __('Successfully connected to Nhanh.vn!', 'nhanhvn'), 'success');
      } else {
        add_settings_error('nhanhvn_settings', 'nhanhvn_oauth_error', __('Failed to connect to Nhanh.vn. Please check your App ID and Secret Key.', 'nhanhvn'), 'error');
      }

      wp_redirect(admin_url('admin.php?page=nhanhvn-settings&tab=api&settings-updated=true'));
      exit;
    }
  }

  /**
   * Display admin notices.
   *
   * @since    1.0.0
   */
  public function admin_notices()
  {
    // Check if token is expired
    if (Nhanhvn_Api::instance()->is_token_expired() && isset($_GET['page']) && strpos($_GET['page'], 'nhanhvn') === 0) {
      echo '<div class="notice notice-warning is-dismissible"><p>' . __('Your Nhanh.vn access token has expired or is not set. Please reconnect to Nhanh.vn.', 'nhanhvn') . '</p></div>';
    }
  }

  /**
   * Add meta boxes.
   *
   * @since    1.0.0
   */
  public function add_meta_boxes()
  {
    add_meta_box(
      'nhanhvn_product_meta_box',
      __('Nhanh.vn Integration', 'nhanhvn'),
      array($this, 'render_product_meta_box'),
      'product',
      'side',
      'default'
    );

    add_meta_box(
      'nhanhvn_order_meta_box',
      __('Nhanh.vn Integration', 'nhanhvn'),
      array($this, 'render_order_meta_box'),
      'shop_order',
      'side',
      'default'
    );
  }

  /**
   * Render product meta box.
   *
   * @since    1.0.0
   * @param    WP_Post    $post    The post object.
   */
  public function render_product_meta_box($post)
  {
    $nhanh_product_id = get_post_meta($post->ID, '_nhanhvn_product_id', true);

    wp_nonce_field('nhanhvn_product_meta_box', 'nhanhvn_product_meta_box_nonce');

    ?>
      <p>
        <label for="nhanhvn_product_id"><?php _e('Nhanh.vn Product ID:', 'nhanhvn'); ?></label>
        <input type="text" id="nhanhvn_product_id" name="nhanhvn_product_id" value="<?php echo esc_attr($nhanh_product_id); ?>" class="widefat">
      </p>

      <?php if ($nhanh_product_id): ?>
          <p>
            <button type="button" id="nhanhvn-sync-product" class="button" data-product-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Sync from Nhanh.vn', 'nhanhvn'); ?></button>
          </p>
      <?php endif; ?>
      <?php
  }

  /**
   * Render order meta box.
   *
   * @since    1.0.0
   * @param    WP_Post    $post    The post object.
   */
  public function render_order_meta_box($post)
  {
    $nhanh_order_id = get_post_meta($post->ID, '_nhanhvn_order_id', true);

    wp_nonce_field('nhanhvn_order_meta_box', 'nhanhvn_order_meta_box_nonce');

    if ($nhanh_order_id) {
      ?>
          <p>
            <strong><?php _e('Nhanh.vn Order ID:', 'nhanhvn'); ?></strong>
            <?php echo esc_html($nhanh_order_id); ?>
          </p>

          <p>
            <button type="button" id="nhanhvn-sync-order-status" class="button" data-order-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Sync Status', 'nhanhvn'); ?></button>
          </p>
          <?php
    } else {
      ?>
          <p><?php _e('This order has not been synced to Nhanh.vn yet.', 'nhanhvn'); ?></p>

          <p>
            <button type="button" id="nhanhvn-send-order" class="button" data-order-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Send to Nhanh.vn', 'nhanhvn'); ?></button>
          </p>
          <?php
    }
  }

  /**
   * Save meta box data.
   *
   * @since    1.0.0
   * @param    int    $post_id    The post ID.
   */
  public function save_meta_box_data($post_id)
  {
    // Check if our nonce is set for product
    if (isset($_POST['nhanhvn_product_meta_box_nonce'])) {
      // Verify that the nonce is valid
      if (!wp_verify_nonce($_POST['nhanhvn_product_meta_box_nonce'], 'nhanhvn_product_meta_box')) {
        return;
      }

      // If this is an autosave, our form has not been submitted, so we don't want to do anything
      if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
      }

      // Check the user's permissions
      if (!current_user_can('edit_post', $post_id)) {
        return;
      }

      // Update the product ID
      if (isset($_POST['nhanhvn_product_id'])) {
        $nhanh_product_id = sanitize_text_field($_POST['nhanhvn_product_id']);

        if (empty($nhanh_product_id)) {
          delete_post_meta($post_id, '_nhanhvn_product_id');
        } else {
          update_post_meta($post_id, '_nhanhvn_product_id', $nhanh_product_id);
        }
      }
    }
  }

  /**
   * Add product columns.
   *
   * @since    1.0.0
   * @param    array    $columns    The columns.
   * @return   array                The modified columns.
   */
  public function add_product_columns($columns)
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
   * Render product columns.
   *
   * @since    1.0.0
   * @param    string    $column     The column name.
   * @param    int       $post_id    The post ID.
   */
  public function render_product_columns($column, $post_id)
  {
    if ($column === 'nhanhvn_sync') {
      $nhanh_product_id = get_post_meta($post_id, '_nhanhvn_product_id', true);

      if ($nhanh_product_id) {
        echo '<mark class="yes" style="color: #7ad03a;"><span class="dashicons dashicons-yes"></span></mark>';
        echo '<span class="nhanhvn-id" style="display: block; font-size: 0.8em; color: #999;">' . esc_html($nhanh_product_id) . '</span>';
      } else {
        echo '<mark class="no" style="color: #a00;"><span class="dashicons dashicons-no"></span></mark>';
      }
    }
  }

  /**
   * Add order columns.
   *
   * @since    1.0.0
   * @param    array    $columns    The columns.
   * @return   array                The modified columns.
   */
  public function add_order_columns($columns)
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
   * Render order columns.
   *
   * @since    1.0.0
   * @param    string    $column     The column name.
   * @param    int       $post_id    The post ID.
   */
  public function render_order_columns($column, $post_id)
  {
    if ($column === 'nhanhvn_sync') {
      $nhanh_order_id = get_post_meta($post_id, '_nhanhvn_order_id', true);

      if ($nhanh_order_id) {
        echo '<mark class="yes" style="color: #7ad03a;"><span class="dashicons dashicons-yes"></span></mark>';
        echo '<span class="nhanhvn-id" style="display: block; font-size: 0.8em; color: #999;">' . esc_html($nhanh_order_id) . '</span>';
      } else {
        echo '<mark class="no" style="color: #a00;"><span class="dashicons dashicons-no"></span></mark>';
      }
    }
  }

  /**
   * AJAX sync products.
   *
   * @since    1.0.0
   */
  public function ajax_sync_products()
  {
    check_ajax_referer('nhanhvn_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $sync = new Nhanhvn_Sync();
    $result = $sync->sync_products();

    if ($result) {
      wp_send_json_success(array('message' => __('Products synced successfully.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to sync products.', 'nhanhvn')));
    }
  }

  /**
   * AJAX sync inventory.
   *
   * @since    1.0.0
   */
  public function ajax_sync_inventory()
  {
    check_ajax_referer('nhanhvn_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $sync = new Nhanhvn_Sync();
    $result = $sync->sync_inventory();

    if ($result) {
      wp_send_json_success(array('message' => __('Inventory synced successfully.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to sync inventory.', 'nhanhvn')));
    }
  }

  /**
   * AJAX sync single product.
   *
   * @since    1.0.0
   */
  public function ajax_sync_single_product()
  {
    check_ajax_referer('nhanhvn_admin_nonce', 'nonce');

    if (!current_user_can('edit_products')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if (!$product_id) {
      wp_send_json_error(array('message' => __('Invalid product ID.', 'nhanhvn')));
      return;
    }

    $sync = new Nhanhvn_Sync();
    $result = $sync->sync_single_product($product_id);

    if ($result) {
      wp_send_json_success(array('message' => __('Product synced successfully.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to sync product.', 'nhanhvn')));
    }
  }

  /**
   * AJAX sync order.
   *
   * @since    1.0.0
   */
  public function ajax_sync_order()
  {
    check_ajax_referer('nhanhvn_admin_nonce', 'nonce');

    if (!current_user_can('edit_shop_orders')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if (!$order_id) {
      wp_send_json_error(array('message' => __('Invalid order ID.', 'nhanhvn')));
      return;
    }

    $sync = new Nhanhvn_Sync();
    $result = $sync->sync_order($order_id);

    if ($result) {
      wp_send_json_success(array('message' => __('Order synced successfully.', 'nhanhvn')));
    } else {
      wp_send_json_error(array('message' => __('Failed to sync order.', 'nhanhvn')));
    }
  }

  /**
   * Add a log entry.
   *
   * @since    1.0.0
   * @param    string    $type       The log type.
   * @param    string    $status     The log status.
   * @param    string    $message    The log message.
   */
  public function add_log($type, $status, $message)
  {
    $logs = get_option('nhanhvn_sync_logs', []);

    // Limit to 100 logs
    if (count($logs) >= 100) {
      array_pop($logs);
    }

    // Add new log at the beginning
    array_unshift($logs, [
      'time' => time(),
      'type' => $type,
      'status' => $status,
      'message' => $message
    ]);

    update_option('nhanhvn_sync_logs', $logs);
  }

  /**
   * Update sync stats.
   *
   * @since    1.0.0
   * @param    string    $type       The sync type.
   * @param    array     $results    The sync results.
   */
  public function update_sync_stats($type, $results)
  {
    $stats = get_option('nhanhvn_sync_stats', [
      'products' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'orders' => ['total' => 0, 'synced' => 0, 'failed' => 0],
      'inventory' => ['total' => 0, 'synced' => 0, 'failed' => 0]
    ]);

    if (!isset($stats[$type])) {
      $stats[$type] = ['total' => 0, 'synced' => 0, 'failed' => 0];
    }

    $stats[$type]['total'] = $results['total'];
    $stats[$type]['synced'] += $results['synced'];
    $stats[$type]['failed'] += $results['failed'];

    update_option('nhanhvn_sync_stats', $stats);
  }

  /**
   * Clear logs.
   *
   * @since    1.0.0
   */
  public function clear_logs()
  {
    check_ajax_referer('nhanhvn_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'nhanhvn')));
      return;
    }

    update_option('nhanhvn_sync_logs', []);
    wp_send_json_success(array('message' => __('Logs cleared successfully.', 'nhanhvn')));
  }
}
