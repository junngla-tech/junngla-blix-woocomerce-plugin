=== Plugin Name ===
Plugin Name: Blix
Plugin URI: http://www.junngla.com
Description: Plugin de procesos de pagos para Blix para woocomerce 2.x.
Version: 1.0
Author: Junngla
Author URI: http://www.junngla.com
License: GPL2

WooCommerce PSP Direct Payment Gateway Blix

== Descripción ==

Blix payment gateway for Woocommerce.  

Una vez instalado, puede configurarlo a través de la pestaña Pasarelas de pago de WooCommerce.

Habilite la pasarela de pago y aplique su número de comerciante único proporcionado por PSP.

Para el método de proceso directo (y no redirigido):
Como ocurre con todas las pasarelas de pago directo donde su cliente no abandona su sitio web,
Necesitará un certificado SSL válido y una certificación PCI DSS.


Probado con WooCommerce versión 2.0.20 y compatible con la versión 2.1

== Instalación ==

Instalación :

1. Descargar.

2. Subir al directorio /wp-contents/plugins/.

3. Active el plugin a través del menú 'Plugin' en WordPress.

4. Ir a Woocommerce -> Configuraciones y seleccionar Pagos.

== Configuración ==

Configurar Gateway:

1. Añadir Titulo que se mostrara al cliente al momento de presentar el medio de pago en el formulario de compra.

2. Añadir URL Blix a la cual se redireccionara al cliente cuando decida pagar.

3. Añadir Llave de medio de pago correspondiente a tu comercio, con el cual Blix te identificara.

4. Añadir Secret de pago.