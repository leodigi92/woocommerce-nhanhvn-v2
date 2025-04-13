<div class="wrap">

  <h1><?php _e('Cài đặt Nhanh.vn', 'woocommerce-nhanhvn'); ?></h1>

  <form method="post" action="options.php">

    <?php settings_fields('nhanhvn_settings_group'); ?>

    <table class="form-table">

      <tr>

        <th><label for="nhanhvn_app_id">App ID test</label></th>

        <td><input type="text" name="nhanhvn_app_id" value="<?php echo esc_attr(get_option('nhanhvn_app_id')); ?>" />

        </td>

      </tr>

      <tr>

        <th><label for="nhanhvn_secret_key">Secret Key</label></th>

        <td><input type="text" name="nhanhvn_secret_key"
            value="<?php echo esc_attr(get_option('nhanhvn_secret_key')); ?>" /></td>

      </tr>

      <tr>

        <th><label for="nhanhvn_business_id">Business ID</label></th>

        <td><input type="text" name="nhanhvn_business_id"
            value="<?php echo esc_attr(get_option('nhanhvn_business_id')); ?>" /></td>

      </tr>

      <tr>
        <th scope="row">Redirect URL</th>
        <td>
          <code><?php echo admin_url('admin.php?page=nhanhvn-settings'); ?></code>
          <p class="description">Sử dụng URL này để cấu hình Redirect URL trong app Nhanh.vn</p>
        </td>
      </tr>

      <tr>

        <th><label for="nhanhvn_auto_push_order">Tự động đăng đơn</label></th>

        <td><input type="checkbox" name="nhanhvn_auto_push_order" value="yes" <?php checked(get_option('nhanhvn_auto_push_order'), 'yes'); ?> /></td>

      </tr>

    </table>

    <?php submit_button(); ?>

  </form>

</div>
