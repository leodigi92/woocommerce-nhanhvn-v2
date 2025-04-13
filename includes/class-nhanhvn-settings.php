<?php
class NhanhVN_Settings
{
    private static $instance = null;
    private $tabs = array();

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->init_tabs();
        $this->init_hooks();
    }

    private function init_tabs()
    {
        $this->tabs = array(
            'general' => 'Cài đặt chung',
            'products' => 'Sản phẩm',
            'inventory' => 'Kho hàng',
            'shipping' => 'Vận chuyển',
            'webhooks' => 'Webhooks',
            'logs' => 'Nhật ký'
        );
    }

    private function init_hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Nhanh.vn Integration',
            'Nhanh.vn',
            'manage_options',
            'nhanhvn-settings',
            array($this, 'render_settings_page'),
            'dashicons-cart',
            56
        );
    }

    public function register_settings()
    {
        register_setting('nhanhvn_settings', 'nhanhvn_app_id');
        register_setting('nhanhvn_settings', 'nhanhvn_secret_key');
        register_setting('nhanhvn_settings', 'nhanhvn_business_id');
        register_setting('nhanhvn_settings', 'nhanhvn_auto_sync');
        register_setting('nhanhvn_settings', 'nhanhvn_sync_interval');
        register_setting('nhanhvn_settings', 'nhanhvn_webhook_token');
        register_setting('nhanhvn_settings', 'nhanhvn_auto_update_stock');
        register_setting('nhanhvn_settings', 'nhanhvn_default_warehouse');
        register_setting('nhanhvn_settings', 'nhanhvn_enable_shipping');
        register_setting('nhanhvn_settings', 'nhanhvn_default_shipping_method');
    }

    public function render_settings_page()
    {
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="nhanhvn-wrap">
            <div class="nhanhvn-header">
                <h1>Nhanh.vn Integration Settings</h1>
                <img src="<?php echo NHANHVN_PLUGIN_URL . 'assets/images/logo.png'; ?>" alt="Nhanh.vn" class="nhanhvn-logo">
            </div>

            <div class="nhanhvn-nav">
                <ul class="nhanhvn-nav-tabs">
                    <?php foreach ($this->tabs as $tab_key => $tab_label): ?>
                        <li>
                            <a href="?page=nhanhvn-settings&tab=<?php echo $tab_key; ?>"
                                class="<?php echo $current_tab === $tab_key ? 'active' : ''; ?>">
                                <?php echo $tab_label; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="nhanhvn-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('nhanhvn_settings');
                    do_settings_sections('nhanhvn_settings');
                    $this->render_all_tab_content($current_tab);
                    submit_button('Lưu thay đổi', 'nhanhvn-button');
                    ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_all_tab_content($current_tab)
    {
        foreach ($this->tabs as $tab_key => $tab_label) {
            echo '<div class="nhanhvn-tab-content" style="display: ' . ($current_tab === $tab_key ? 'block' : 'none') . '">';
            $this->render_tab_content($tab_key);
            echo '</div>';
        }
    }

    private function render_tab_content($tab)
    {
        switch ($tab) {
            case 'general':
                $this->render_general_settings();
                break;
            case 'products':
                $this->render_product_settings();
                break;
            case 'inventory':
                $this->render_inventory_settings();
                break;
            case 'shipping':
                $this->render_shipping_settings();
                break;
            case 'webhooks':
                $this->render_webhook_settings();
                break;
            case 'logs':
                $this->render_logs();
                break;
        }
    }

    private function render_general_settings()
    {
        $redirect_url = admin_url('admin.php?page=nhanhvn-settings');
        $app_id = get_option('nhanhvn_app_id', '');
        $secret_key = get_option('nhanhvn_secret_key', '');
        $business_id = get_option('nhanhvn_business_id', '');
        $access_token = get_option('nhanhvn_access_token', '');

        // Xử lý khi nhận được `accessCode` từ Nhanh.vn
        if (isset($_GET['accessCode']) && !empty($_GET['accessCode'])) {
            $access_token = sanitize_text_field($_GET['accessCode']);
            update_option('nhanhvn_access_token', $access_token);
            error_log('Nhanh.vn Access Token Updated: ' . $access_token);
            // Redirect để xóa param `accessCode` khỏi URL
            wp_redirect(admin_url('admin.php?page=nhanhvn-settings&tab=general'));
            exit;
        }

        ?>
        <div class="nhanhvn-settings-section">
            <h2>Cài đặt API Nhanh.vn</h2>

            <div class="nhanhvn-form-group">
                <label for="nhanhvn_app_id">App ID</label>
                <input type="text" id="nhanhvn_app_id" name="nhanhvn_app_id" value="<?php echo esc_attr($app_id); ?>"
                    class="regular-text">
                <p class="description">Nhập App ID từ trang quản trị Nhanh.vn</p>
            </div>

            <div class="nhanhvn-form-group">
                <label for="nhanhvn_secret_key">Secret Key</label>
                <input type="password" id="nhanhvn_secret_key" name="nhanhvn_secret_key"
                    value="<?php echo esc_attr($secret_key); ?>" class="regular-text">
                <p class="description">Nhập Secret Key từ trang quản trị Nhanh.vn</p>
            </div>

            <div class="nhanhvn-form-group">
                <label for="nhanhvn_business_id">Business ID</label>
                <input type="text" id="nhanhvn_business_id" name="nhanhvn_business_id"
                    value="<?php echo esc_attr($business_id); ?>" class="regular-text">
                <p class="description">Nhập Business ID từ trang quản trị Nhanh.vn</p>
            </div>

            <div class="nhanhvn-form-group">
                <label for="nhanhvn_access_token">Access Token</label>
                <input type="text" id="nhanhvn_access_token" name="nhanhvn_access_token"
                    value="<?php echo esc_attr($access_token); ?>" class="regular-text">
                <a href="<?php echo $this->get_authorize_url($app_id, $redirect_url); ?>" class="button">Lấy Access Token</a>
                <p class="description">Nhấn "Lấy Access Token" để ủy quyền và nhận token từ Nhanh.vn, hoặc nhập thủ công nếu đã
                    có.</p>
            </div>

            <div class="nhanhvn-form-group">
                <label for="nhanhvn_redirect_url">Redirect URL</label>
                <input type="text" id="nhanhvn_redirect_url" value="<?php echo esc_url($redirect_url); ?>" class="regular-text"
                    readonly>
                <button type="button" class="button copy-redirect-url" data-target="nhanhvn_redirect_url">Copy URL</button>
                <p class="description">Sao chép URL này và sử dụng trong phần "Redirect URL" khi tạo ứng dụng trên Nhanh.vn.</p>
            </div>

            <div class="nhanhvn-setup-guide">
                <h3>Hướng dẫn cài đặt</h3>
                <ol>
                    <li>Đăng nhập vào trang quản trị Nhanh.vn.</li>
                    <li>Vào phần <strong>Cài đặt > API & Webhook</strong>.</li>
                    <li>Tạo ứng dụng mới với các thông tin:
                        <ul>
                            <li><strong>Tên ứng dụng</strong>: WooCommerce Integration</li>
                            <li><strong>Redirect URL</strong>: Dán URL từ trường "Redirect URL" phía trên
                                (<code><?php echo esc_url($redirect_url); ?></code>)</li>
                            <li><strong>Webhook URL</strong>: Lấy từ tab "Webhooks"</li>
                            <li>Chọn các sự kiện cần nhận thông báo (ví dụ: orderAdd, productUpdate...)</li>
                        </ul>
                    </li>
                    <li>Sao chép <strong>App ID</strong>, <strong>Secret Key</strong> và <strong>Business ID</strong> từ
                        Nhanh.vn, sau đó điền vào các trường phía trên.</li>
                    <li>Nhấn "Lấy Access Token" để nhận token tự động, hoặc nhập thủ công nếu đã có.</li>
                    <li>Lưu cài đặt và kiểm tra kết nối.</li>
                </ol>
            </div>
        </div>
        <?php
    }

    private function get_authorize_url($app_id, $redirect_url)
    {
        return "https://nhanh.vn/oauth?version=2.0&appId=" . urlencode($app_id) . "&returnLink=" . urlencode($redirect_url);
    }

    private function exchange_code_for_token($code, $app_id, $secret_key)
    {
        $response = wp_remote_post('https://open.nhanh.vn/oauth/accessToken', [
            'body' => [
                'version' => '2.0',
                'appId' => $app_id,
                'appSecret' => $secret_key,
                'code' => $code
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('Nhanh.vn Get Access Token Error: ' . $response->get_error_message());
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['code']) && $body['code'] == 1) {
            $access_token = $body['data']['accessToken'];
            update_option('nhanhvn_access_token', $access_token);
            error_log('Nhanh.vn Access Token Updated: ' . $access_token);
        } else {
            $error_message = $body['messages'] ?? 'Unknown error';
            error_log('Nhanh.vn Get Access Token Failed: ' . json_encode($error_message));
        }
    }

    private function render_product_settings()
    {
        ?>
        <div class="nhanhvn-form-group">
            <label>
                <input type="checkbox" name="nhanhvn_sync_product_images" value="1" <?php checked(1, get_option('nhanhvn_sync_product_images'), true); ?>>
                Đồng bộ hình ảnh sản phẩm
            </label>
        </div>

        <div class="nhanhvn-form-group">
            <label>
                <input type="checkbox" name="nhanhvn_sync_product_categories" value="1" <?php checked(1, get_option('nhanhvn_sync_product_categories'), true); ?>>
                Đồng bộ danh mục sản phẩm
            </label>
        </div>

        <div class="nhanhvn-form-group">
            <label>Đồng bộ giá sản phẩm:</label>
            <select name="nhanhvn_price_type">
                <option value="retail" <?php selected('retail', get_option('nhanhvn_price_type')); ?>>Giá bán lẻ</option>
                <option value="wholesale" <?php selected('wholesale', get_option('nhanhvn_price_type')); ?>>Giá bán buôn
                </option>
            </select>
        </div>
        <?php
    }

    private function render_inventory_settings()
    {
        ?>
        <div class="nhanhvn-form-group">
            <label>
                <input type="checkbox" name="nhanhvn_auto_update_stock" value="1" <?php checked(1, get_option('nhanhvn_auto_update_stock'), true); ?>>
                Tự động cập nhật tồn kho
            </label>
        </div>

        <div class="nhanhvn-form-group">
            <label>Kho mặc định:</label>
            <?php
            $api = NhanhVN_API::instance();
            $warehouses = $api->get_warehouses();
            if (is_wp_error($warehouses)) {
                echo '<p class="description error">Không thể lấy danh sách kho: ' . esc_html($warehouses->get_error_message()) . '. Vui lòng kiểm tra thông tin API trong tab "Cài đặt chung".</p>';
            } elseif (!empty($warehouses) && is_array($warehouses)) {
                ?>
                <select name="nhanhvn_default_warehouse">
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?php echo esc_attr($warehouse['id']); ?>" <?php selected($warehouse['id'], get_option('nhanhvn_default_warehouse')); ?>>
                            <?php echo esc_html($warehouse['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php
            } else {
                echo '<p class="description error">Danh sách kho trống. Vui lòng kiểm tra thông tin API trong tab "Cài đặt chung".</p>';
            }
            ?>
            <p class="description">Chọn kho mặc định để đồng bộ tồn kho.</p>
        </div>
        <?php
    }

    private function render_shipping_settings()
    {
        ?>
        <div class="nhanhvn-form-group">
            <label>
                <input type="checkbox" name="nhanhvn_enable_shipping" value="1" <?php checked(1, get_option('nhanhvn_enable_shipping'), true); ?>>
                Kích hoạt tính phí vận chuyển qua Nhanh.vn
            </label>
        </div>

        <div class="nhanhvn-form-group">
            <label>Phương thức vận chuyển mặc định:</label>
            <select name="nhanhvn_default_shipping_method">
                <option value="standard" <?php selected('standard', get_option('nhanhvn_default_shipping_method')); ?>>
                    Giao hàng tiêu chuẩn
                </option>
                <option value="express" <?php selected('express', get_option('nhanhvn_default_shipping_method')); ?>>
                    Giao hàng nhanh
                </option>
            </select>
        </div>
        <?php
    }

    private function render_webhook_settings()
    {
        $webhook_token = get_option('nhanhvn_webhook_token', wp_generate_password(32, false));
        $webhook_url = rest_url('nhanh/v1/webhook') . '?token=' . $webhook_token;
        ?>
        <div class="nhanhvn-form-group">
            <label>Webhook URL:</label>
            <input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="regular-text" id="webhook-url">
            <button type="button" class="button copy-webhook-url" data-target="webhook-url">Copy URL</button>
            <p class="description">Sử dụng URL này trong cài đặt webhook của Nhanh.vn</p>
        </div>

        <div class="nhanhvn-form-group">
            <label>Webhook Token:</label>
            <input type="text" name="nhanhvn_webhook_token" value="<?php echo esc_attr($webhook_token); ?>" class="regular-text"
                id="webhook-token">
            <button type="button" class="button generate-webhook-token">Tạo token mới</button>
            <p class="description">Token này dùng để xác thực webhook từ Nhanh.vn</p>
        </div>
        <?php
    }

    private function render_logs()
    {
        global $wpdb;
        $logs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}nhanhvn_sync_log ORDER BY created_at DESC LIMIT 100");
        ?>
        <table class="nhanhvn-table">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Loại</th>
                    <th>Trạng thái</th>
                    <th>Nội dung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo $log->created_at; ?></td>
                        <td><?php echo $log->type; ?></td>
                        <td><?php echo $log->status; ?></td>
                        <td><?php echo $log->message; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function show_admin_notices()
    {
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Cài đặt đã được lưu thành công.</p>
            </div>
            <?php
        }
    }
}
