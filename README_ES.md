# WooCommerce Agilpay Gateway Plugin

Este plugin permite integrar WooCommerce con el gateway de pago Agilpay, utilizando una página de pago alojada.

## Requisitos

- WordPress 5.0 o superior
- WooCommerce 3.0 o superior
- PHP 7.0 o superior

## Instalación

1. **Descargar el plugin**:
   - Descarga el archivo del plugin `woocommerce-agilpay.zip` desde el repositorio o crea un archivo ZIP de la carpeta del plugin.

2. **Subir el plugin**:
   - Ve al panel de administración de WordPress.
   - Navega a `Plugins` > `Añadir nuevo`.
   - Haz clic en `Subir plugin` y selecciona el archivo `woocommerce-agilpay.zip`.
   - Haz clic en `Instalar ahora` y luego en `Activar`.

3. **Configurar el plugin**:
   - Ve a `WooCommerce` > `Ajustes` > `Pagos`.
   - Activa `Agilpay` y haz clic en `Gestionar`.
   - Configura los siguientes campos:
     - **Título**: El título que verán los clientes durante el pago.
     - **Descripción**: La descripción que verán los clientes durante el pago.
     - **Site ID**: Identificación única del sitio web proporcionada por Agilpay.
     - **Merchant Key**: Clave de identificación del comercio proporcionada por Agilpay.
     - **Merchant Name**: Nombre del comercio.

## Uso

1. **Realizar una compra de prueba**:
   - Añade un producto al carrito y procede al pago.
   - Selecciona `Agilpay` como método de pago y completa la compra.
   - Serás redirigido a la página de pago de Agilpay con los parámetros necesarios.

2. **Verificar el pago**:
   - Una vez completado el pago en la página de Agilpay, el cliente será redirigido de vuelta a tu sitio de WooCommerce.
   - Verifica que el pedido se haya procesado correctamente en `WooCommerce` > `Pedidos`.

## Soporte

Si tienes alguna pregunta o necesitas ayuda, por favor contacta con el soporte técnico de Agilpay o revisa la documentación oficial de WooCommerce.

## Contribuciones

Las contribuciones son bienvenidas. Si deseas contribuir, por favor abre un issue o envía un pull request en el repositorio del plugin.

## Licencia

Este plugin está licenciado bajo la [Licencia GPLv2](https://www.gnu.org/licenses/gpl-2.0.html).

