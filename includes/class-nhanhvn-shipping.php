<?php

if (!defined('ABSPATH'))
  exit;

if (!class_exists('WC_Shipping_Method')) {
  return;
}

class NhanhVN_Shipping extends WC_Shipping_Method
{

  public function __construct($instance_id = 0)
  {

    $this->id = 'nhanhvn';

    $this->instance_id = absint($instance_id);

    $this->method_title = 'Nhanh.vn Shipping';

    $this->method_description = 'Tính phí vận chuyển qua Nhanh.vn';

    $this->supports = array('shipping-zones', 'instance-settings');


    $this->init();

  }


  public function init()
  {

    $this->init_form_fields();

    $this->init_settings();


    $this->enabled = $this->get_option('enabled');

    $this->title = $this->get_option('title', 'Nhanh.vn Shipping');


    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));

  }


  public function init_form_fields()
  {

    $this->instance_form_fields = array(

      'enabled' => array(

        'title' => 'Enable/Disable',

        'type' => 'checkbox',

        'label' => 'Enable Nhanh.vn shipping',

        'default' => 'yes'

      ),

      'title' => array(

        'title' => 'Title',

        'type' => 'text',

        'description' => 'This controls the title which the user sees during checkout.',

        'default' => 'Nhanh.vn Shipping',

        'desc_tip' => true

      )

    );

  }


  public function calculate_shipping($package = array())
  {

    if (!get_option('nhanhvn_enable_shipping')) {

      return;

    }


    $api = NhanhVN_API::instance();


    // Prepare shipping data

    $shipping_data = array(

      'fromCityName' => get_option('nhanhvn_shop_city'),

      'fromDistrictName' => get_option('nhanhvn_shop_district'),

      'toCityName' => $package['destination']['city'],

      'toDistrictName' => $package['destination']['state'],

      'weight' => $this->calculate_total_weight($package),

      'defaultShippingMethod' => get_option('nhanhvn_default_shipping_method')

    );


    $response = $api->get_shipping_fee($shipping_data);


    if (!is_wp_error($response)) {

      $this->add_rate(array(

        'id' => $this->id,

        'label' => $this->title,

        'cost' => $response['data']['fee'],

        'calc_tax' => 'per_order'

      ));

    }

  }


  private function calculate_total_weight($package)
  {

    $weight = 0;

    foreach ($package['contents'] as $item) {

      $product = $item['data'];

      $weight += ($product->get_weight() * $item['quantity']);

    }

    return $weight;

  }

}


// Register shipping method

add_action('woocommerce_shipping_init', function () {

  new NhanhVN_Shipping();

});


add_filter('woocommerce_shipping_methods', function ($methods) {

  $methods['nhanhvn'] = 'NhanhVN_Shipping';

  return $methods;

});
