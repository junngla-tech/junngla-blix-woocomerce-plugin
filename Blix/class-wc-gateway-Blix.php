<?php
/**
 * Plugin Name: Blix
 * Plugin URI: http://www.junngla.com
 * Description: Plugin de procesos de pagos para Blix para woocomerce 2.x.
 * Version: 1.0
 * Author: Junngla
 * Author URI: http://www.junngla.com
 * License: GPL2
 */

 include('GenerateSignature.php');

// check that woocommerce is an active plugin before initializing payment gateway
if (in_array('woocommerce/woocommerce.php', (array)get_option('active_plugins'))) {
    add_action('plugins_loaded', 'blix_init', 0);
    add_filter('woocommerce_payment_gateways', 'blix_add_gateway');
}

function blix_add_gateway($methods)
{
    $methods[] = 'WC_Gateway_blix';
    return $methods;
}

function blix_init()
{
    class WC_Gateway_blix extends WC_Payment_Gateway
    {

        var $notify_url;

        public function __construct()
        {

            if (!function_exists('array_is_list')) {
                function array_is_list(array $arr)
                {
                    if ($arr === []) {
                        return true;
                    }
                    return array_keys($arr) === range(0, count($arr) - 1);
                }
            }
		  
            $this->id = 'blix';
            $this->icon = apply_filters('woocommerce_blix_icon', plugin_dir_url('') . 'Blix/blix_logo.png');
            $this->order_button_text = __('Pagar en Blix', 'woocommerce');
            $this->method_title = __('Blix', 'woocommerce');
            $this->notify_url = WC()->api_request_url('wc_gateway_blix');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->service_directUrl = $this->get_option('service_directUrl');
            $this->merchantID = $this->get_option('merchantID');
            $this->securityKey = $this->get_option('securityKey');
            $this->useCCStorage = $this->get_option('useCCStorage') == 'yes';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_handler'));
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'hpp_notify_handler'));
        }

        public function admin_options()
        {

            ?>
          <h3><?php _e('Formulario de configuracion de ambiente', 'woocommerce'); ?></h3>
          <p><?php _e('A continuacion te presentamos el formulario a configurar para poder comunicar el ecosistema Blix.', 'woocommerce'); ?></p>

            <?php ?>

          <table class="form-table">
              <?php
              // Generate the HTML For the settings form.
              $this->generate_settings_html();
              ?>
          </table><!--/.form-table-->

            <?php
        }

        function init_form_fields()
        {

            $this->form_fields = array(
                'title' => array(
                    'title' => __('Titulo', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Esta descripcion aparecera cuando el usuario este eligiendo su pago', 'woocommerce'),
                    'default' => __('Blix', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'service_directUrl' => array(
                    'title' => __('URL Blix', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Se enviara al cliente a esta url para completar la transaccion', 'woocommerce'),
                    'default' => __('', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'merchantID' => array(
                    'title' => __('Llave de medio de pago', 'woocommerce'),
                    'type' => 'number',
                    'description' => __('Identificador del comercio', 'woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                    'placeholder' => '0000000'
                ),
                'securityKey' => array(
                    'title' => __('Secret de medio de pago', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Tu secreto asociado a tu comercio, encriptara los datos de la transaccion.', 'woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                    'placeholder' => __('Optional', 'woocommerce')
                )
            );
        }

        public function get_state($cc, $state)
        {
            if ('US' === $cc || 'CA' === $cc) return $state;
            $states = WC()->countries->get_states($cc);
            if (isset($states[$state])) return $states[$state];
            return $state;
        }

        function get_hpp_args($order)
        {
            $order_id = $order->id;
            $send_total = number_format($order->get_total(), 2, '.', '');
            $ret_args = array(
                'merchantID' => $this->merchantID,
                'trans_currency' => get_woocommerce_currency(),
                'trans_amount' => $send_total,
                'trans_refNum' => ltrim($order->get_order_number(), '#'),
                'trans_storePm' => $this->useCCStorage ? '1' : '0',
                'url_redirect' => add_query_arg('utm_nooverride', '1', $this->get_return_url($order)),
                'url_notify' => $this->notify_url,
                'url_cancel' => $order->get_cancel_order_url_raw(),
                'client_invoiceName' => $order->billing_company,
                'client_fullName' => $order->billing_first_name . ' ' . $order->billing_last_name,
                'client_billAddress1' => $order->billing_address_1,
                'client_billAddress2' => $order->billing_address_2,
                'client_billCity' => $order->billing_city,
                'client_billState' => $this->get_state($order->billing_country, $order->billing_state),
                'client_billZipcode' => $order->billing_postcode,
                'client_billCountry' => $order->billing_country,
                'client_email' => $order->billing_email,
                'client_phoneNum' => $order->billing_phone
            );

            $ret_args['signature'] = generateSignature($ret_args, $this->securityKey);

            return $ret_args;
        }

        function receipt_page($order_id)
        {
            echo '<p>' . __('Gracias, su pedido ahora está pendiente de pago. Debería ser redirigido automáticamente a Blix para realizar el pago.', 'woocommerce') . '</p>';

            $order = new WC_Order($order_id);
            $post_adr = $this->service_directUrl . '?';
            $send_args = $this->get_hpp_args($order);
            $send_args_array = array();
            foreach ($send_args as $key => $value) {
                $send_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
            wc_enqueue_js('
			  $.blockUI({
					  message: "' . esc_js(__('Gracias por su compra. Ahora lo redirigiremos a Blix para realizar el pago.', 'woocommerce')) . '",
					  baseZ: 99999,
					  overlayCSS:
					  {
						  background: "#fff",
						  opacity: 0.6
					  },
					  css: {
						  padding:        "20px",
						  zindex:         "9999999",
						  textAlign:      "center",
						  color:          "#555",
						  border:         "3px solid #aaa",
						  backgroundColor:"#fff",
						  cursor:         "wait",
						  lineHeight:		"24px",
					  }
				  });
			  jQuery("#submit_payment_form").click();
		  ');

            echo
                '<form action="' . esc_url($post_adr) . '" method="post" id="payment_form" target="_top">
				  ' . implode('', $send_args_array) . '
				  <!-- Button Fallback -->
				  <div class="payment_buttons">
					  <input type="submit" class="button alt" id="submit_payment_form" value="' . __('Pago Blix', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancelar orden &amp; vaciar carrito', 'woocommerce') . '</a>
				  </div>
				  <script type="text/javascript">
					  jQuery(".payment_buttons").hide();
				  </script>
			  </form>';

        }

        private function setProcessResultValues($order, $transId, $transConfirm, $storageId, $status)
        {
            try {
                $order->add_order_note('Blix notificacion, Resultado del pago ' . $status);
                if ($transId) update_post_meta($order->id, 'Transaccion ID', wc_clean($transId));
                if ($transConfirm) update_post_meta($order->id, 'Numero de confirmacion', wc_clean($transConfirm));
                if ($storageId) update_post_meta($order->id, 'Storage ID', wc_clean($storageId));
                $order->update_status($status);
                return true;
            } catch (Exception $e) {
                echo 'Caught exception: ', $e->getMessage(), "\n";
                return false;
            }
        }

        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        private function get_payed_order($order_id)
        {
            $order = new WC_Order($order_id);
            return $order;
        }

        public function thankyou_handler()
        {
            $response_data = !empty($_GET) ? $_GET : false;
            if ($response_data && !empty($response_data['replyCode'])) {
                $order = $this->get_payed_order($response_data['trans_refNum']);
                if ('pending' != $order->status) return false;
                if ($this->setProcessResultValues($order, 'Redirect back', $response_data['replyCode'], $response_data['trans_id'], null, $response_data['storage_id'])) {
                    return true;
                } else {
                    header('Location: ' . $order->get_checkout_payment_url());
                    die();
                    return false;
                }
            }
            return false;
        }

        function hpp_notify_handler()
        {

            @ob_clean();
            $response_data = !empty($_GET) ? $_GET : false;

            $params = array(
                'trans_refNum' => $response_data["trans_refNum"],
                'trans_id' => $response_data["trans_id"],
                'trans_confirm' => $response_data["trans_confirm"],
                'storage_id' => $response_data["storage_id"],
                'status' => $response_data["status"]
            );

            if ($response_data['signature'] == generateSignature($params, $this->securityKey)) {
                header('HTTP/1.1 200 OK');
                $order = $this->get_payed_order($response_data['trans_refNum']);
                $this->setProcessResultValues($order, $response_data['trans_id'], $response_data['trans_confirm'], $response_data['storage_id'], $response_data['status']);
                echo 'OK';
            } else {
                wp_die('Proceso verificacion erroneo ', "base notify handler", array('response' => 403));
            }
        }

    }
}