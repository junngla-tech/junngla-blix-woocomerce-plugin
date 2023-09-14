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

include_once 'GenerateSignature.php';

// check that woocommerce is an active plugin before initializing payment gateway
if (in_array('woocommerce/woocommerce.php', (array)get_option('active_plugins'))) {
    add_action('plugins_loaded', 'blixInit', 0);
    add_filter('woocommerce_payment_gateways', 'blixAddGateway');
}

function blixAddGateway($methods)
{
    $methods[] = 'WCGatewayBlix';
    return $methods;
}

function blixInit()
{
    class WCGatewayBlix extends WC_Payment_Gateway
    {

        public $notifyurl;

        public function __construct()
        {
            $this->id = 'blix';
            $this->icon = apply_filters(
                'woocommerce_blix_icon',
                plugin_dir_url('') . 'junngla-blix-woocommerce-plugin/blix_logo.png'
            );
            $this->order_button_text = __('Pagar en Blix', 'woocommerce');
            $this->method_title = __('RedPay by Blix', 'woocommerce');
            $this->notifyurl = WC()->api_request_url('WCGatewayBlix');

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
            add_action(
                'woocommerce_update_options_payment_gateways_' .
                $this->id,
                array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_handler'));
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'hpp_notify_handler'));
        }

        public function admin_options()
        {

            ?>
            <h3><?php _e('Formulario de configuracion de ambiente', 'woocommerce'); ?></h3>
            <?php
            _e(
                'A continuacion te presentamos' .
                ' el formulario a configurar para poder comunicar el ecosistema Blix.',
                'woocommerce'
            ); ?>

            <?php ?>

            <table class="form-table" aria-describedby="form">
                <th scope="col"></th>
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->

            <?php
        }

        public function init_form_fields()
        {

            $this->form_fields = array(
                'title' => array(
                    'title' => __('Nombre', 'woocommerce'),
                    'type' => 'text',
                    'description' => __(
                        'Nombre del método de pago que aparecerá en el checkout.',
                        'woocommerce'
                    ),
                    'default' => __('RedPay by Blix', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'service_directUrl' => array(
                    'title' => __('Url de método de pago', 'woocommerce'),
                    'type' => 'text',
                    'description' => __(
                        'El URL donde proveemos información sobre tus pedidos a la cuenta de merchant.',
                        'woocommerce'
                    ),
                    'default' => __('https://api.blix.global/woocommerce/init-transaction', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'merchantID' => array(
                    'title' => __('Llave de medio de pago', 'woocommerce'),
                    'type' => 'number',
                    'description' => __('El identificador de credenciales de tu cuenta de merchant.', 'woocommerce'),
                    'default' => '',
                    'desc_tip' => true,
                    'placeholder' => '0000000'
                ),
                'securityKey' => array(
                    'title' => __('Secret de medio de pago', 'woocommerce'),
                    'type' => 'text',
                    'description' => __(
                        'La credencial secreta de su cuenta de comerciante emitida por su proveedor.',
                        'woocommerce'
                    ),
                    'default' => '',
                    'desc_tip' => true,
                    'placeholder' => __('Optional', 'woocommerce')
                )
            );
        }

        public function get_state($cc, $state)
        {
            if ('US' === $cc || 'CA' === $cc) {
                return $state;
            }
            $states = WC()->countries->get_states($cc);
            if (isset($states[$state])) {
                return $states[$state];
            }
            return $state;
        }

        public function get_hpp_args($order)
        {
            $sendtotal = number_format($order->get_total(), 2, '.', '');
            $retargs = array(
                'merchantID' => $this->merchantID,
                'trans_currency' => get_woocommerce_currency(),
                'trans_amount' => $sendtotal,
                'trans_refNum' => ltrim($order->get_order_number(), '#'),
                'trans_storePm' => $this->useCCStorage ? '1' : '0',
                'url_redirect' => add_query_arg('utm_nooverride', '1', $this->get_return_url($order)),
                'url_notify' => $this->notifyurl,
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

            $retargs['signature'] = generateSignature($retargs, $this->securityKey);

            return $retargs;
        }

        public function receipt_page($orderid)
        {
            echo '<p>' .
                __(
                    'Gracias, su pedido ahora está pendiente de pago.' .
                    'Sera redirigido automáticamente a Blix para realizar el pago.',
                    'woocommerce'
                ) . '</p>';

            $order = new WC_Order($orderid);
            $postadr = $this->service_directUrl . '?';
            $sendargs = $this->get_hpp_args($order);
            $sendargsarray = array();
            foreach ($sendargs as $value => $key) {
                $sendargsarray[] = '<input type="hidden" name="' .
                    esc_attr($value) . '" value="' .
                    esc_attr($key) . '" />';
            }
            wc_enqueue_js('
              $.blockUI({
                      message: "' . esc_js(
                    __(
                        'Gracias por su compra. Ahora lo redirigiremos a Blix para realizar el pago.',
                        'woocommerce'
                    )) . '",
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
                '<form action="' . esc_url($postadr) . '" method="post" id="payment_form" target="_top">
                  ' . implode('', $sendargsarray) . '
                  <!-- Button Fallback -->
                  <div class="payment_buttons">
                      <input type="submit" class="button alt" id="submit_payment_form" value="' .
                __('Pago Blix', 'woocommerce') .
                '" /> <a class="button cancel" href="' .
                esc_url($order->get_cancel_order_url()) . '">' .
                __('Cancelar orden &amp; vaciar carrito', 'woocommerce') . '</a>
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
                if ($transId) {
                    update_post_meta($order->id, 'Transaccion ID', wc_clean($transId));
                }
                if ($transConfirm) {
                    update_post_meta($order->id, 'Numero de confirmacion', wc_clean($transConfirm));
                }
                if ($storageId) {
                    update_post_meta($order->id, 'Storage ID', wc_clean($storageId));
                }
                $order->update_status($status);
                return true;
            } catch (Exception $e) {
                echo 'Caught exception: ', $e->getMessage(), "\n";
                return false;
            }
        }

        public function process_payment($orderid)
        {
            $order = new WC_Order($orderid);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        private function getpayedorder($orderid)
        {
            return new WC_Order($orderid);
        }

        public function thankyou_handler()
        {
            $responsedata = !empty($_GET) ? $_GET : false;
            if ($responsedata && !empty($responsedata['replyCode'])) {
                $order = $this->getpayedorder($responsedata['trans_refNum']);
                if ('pending' != $order->status) {
                    return false;
                }
                if ($this->setProcessResultValues(
                    $order,
                    'Redirect back',
                    $responsedata['replyCode'],
                    $responsedata['trans_id'],
                    null,
                    $responsedata['storage_id']
                )) {
                    return true;
                } else {
                    header('Location: ' . $order->get_checkout_payment_url());
                    die();
                }
            }
            return false;
        }

        public function hpp_notify_handler()
        {

            @ob_clean();
            $responsedata = !empty($_GET) ? $_GET : false;

            $params = array(
                'trans_refNum' => $responsedata["trans_refNum"],
                'trans_id' => $responsedata["trans_id"],
                'trans_confirm' => $responsedata["trans_confirm"],
                'storage_id' => $responsedata["storage_id"],
                'status' => $responsedata["status"]
            );

            if ($responsedata['signature'] == generateSignature($params, $this->securityKey)) {
                header('HTTP/1.1 200 OK');
                $order = $this->getpayedorder($responsedata['trans_refNum']);
                $this->setProcessResultValues(
                    $order,
                    $responsedata['trans_id'],
                    $responsedata['trans_confirm'],
                    $responsedata['storage_id'],
                    $responsedata['status']
                );
                echo 'OK';
            } else {
                wp_die('Proceso verificacion erroneo ', "base notify handler", array('response' => 403));
            }
        }
    }
}
