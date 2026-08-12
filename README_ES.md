# Plugin WooCommerce Agilpay Gateway

Este plugin integra WooCommerce con el gateway de pago Agilpay usando una página de pago alojada. El cliente es redirigido a Agilpay para completar el pago, y Agilpay informa el resultado de vuelta a tu tienda.

## Requisitos

- WordPress 6.0 o superior
- WooCommerce 8.2 o superior (probado hasta 10.9.4)
- PHP 7.4 o superior

Compatible con **HPOS** (High-Performance Order Storage) y con los **bloques de Carrito/Checkout** de WooCommerce.

## Instalación

1. **Armar el paquete del plugin**:
   - Crea un archivo ZIP de la carpeta `src/`.

2. **Subir el plugin**:
   - Ve al panel de administración de WordPress.
   - Navega a `Plugins` > `Añadir nuevo`.
   - Haz clic en `Subir plugin` y selecciona el archivo ZIP.
   - Haz clic en `Instalar ahora` y luego en `Activar`.

   El manejador de respuestas lo carga automáticamente el archivo principal del plugin. Hay un solo plugin para activar.

3. **Configurar el plugin**:
   - Ve a `WooCommerce` > `Ajustes` > `Pagos`.
   - Activa `Agilpay` y haz clic en `Gestionar`.

### Configuración

| Campo | Propósito |
|-------|-----------|
| **Título** | El título que verán los clientes durante el pago. |
| **Descripción** | La descripción que verán los clientes durante el pago. |
| **Site ID** | Identificación única del sitio web proporcionada por Agilpay. |
| **Site Password** | Contraseña del Site ID. También se usa como `Client_Secret` al validar respuestas. |
| **Merchant Key** | Clave de identificación del comercio proporcionada por Agilpay. |
| **Merchant Name** | Nombre del comercio, se muestra en la página alojada de Agilpay. |
| **Payment URL** | URL de la página de pago (por defecto: `https://sandbox-webpay.agilpay.net/Payment/`). |
| **Token URL** | Endpoint OAuth del token (por defecto: `https://sandbox-webapi.agilpay.net/oauth/paymenttoken`). |
| **Hash Secret** | Opcional. Sobrescribe el `Client_Secret` usado para validar la respuesta de la página alojada. Vacío = usa el Site Password. |
| **Authoritative webhook** | Liquidar pedidos únicamente desde el webhook server-to-server de Agilpay. Recomendado — ver [Seguridad](#seguridad). |
| **Webhook API Key** | Clave precompartida que Agilpay envía en el header `x-api-key` del webhook server-to-server. |

> Las URLs por defecto apuntan al **sandbox** de Agilpay. Reemplazalas por las de producción antes de salir en vivo.

## Configuración del endpoint

Después de activar el plugin, refrescá las reglas de reescritura:

- Ve a `Ajustes` > `Enlaces permanentes` en el panel de WordPress.
- Haz clic en `Guardar cambios`.

El plugin expone dos endpoints:

| Endpoint | Quién lo llama |
|----------|----------------|
| `{sitio}/wc-api/agilpay_response` | La página de pago alojada de Agilpay, vía el navegador del cliente |
| `{sitio}/wc-api/agilpay_webhook` | El backend de Agilpay, server-to-server |

## Seguridad

Agilpay informa el resultado de un pago por dos canales independientes, y el plugin los trata distinto a propósito.

**1. Respuesta de la página alojada** (`wc-api/agilpay_response`)

Un POST de formulario que viaja por el navegador del cliente. Lleva un `MessageHash` que el plugin valida así:

```
MessageHash = base64( sha256( Client_Secret + AccountToken + Invoice + Amount ) )
```

Ver la guía [Validating Responses](https://agilpay.readme.io/docs/validating-responses) de Agilpay. Una petición cuyo hash no valida es rechazada.

Como el conjunto de campos firmados **no** incluye `ResponseCode`, un hash válido prueba que la factura y el monto no fueron alterados, pero **no** prueba que el pago fue aprobado. Y como el mensaje pasa por el navegador del cliente, el cliente puede modificarlo.

**2. Webhook server-to-server** (`wc-api/agilpay_webhook`)

Lo emite el backend de Agilpay cuando el pago se aprueba realmente, autenticado con un header `x-api-key` precompartido. Nunca pasa por el navegador del cliente, y eso lo convierte en el canal confiable.

### Configuración recomendada

Pedile a Agilpay que configure el webhook para hacer POST a `{tu-sitio}/wc-api/agilpay_webhook` con una API key. Después completá **Webhook API Key** y activá **Authoritative webhook**. Los pedidos se van a liquidar solo desde el webhook.

Mientras el webhook no esté configurado, el plugin liquida los pedidos desde la respuesta de la página alojada, para que no queden colgados en `pending`. Ese fallback igual exige un `MessageHash` válido, que el monto coincida y que el `ResponseCode` sea `00` — pero es el modo más débil de los dos. Activá el webhook cuando puedas.

En ambos modos el plugin además:

- verifica que el monto informado coincida con el total del pedido antes de liquidar;
- ignora notificaciones duplicadas de un pedido ya pagado, así los reintentos y los replays son seguros;
- se niega a actuar sobre pedidos que pertenecen a otro método de pago;
- compara secretos con una comparación resistente a ataques de tiempo.

### Reportar una vulnerabilidad

Por favor **no** abras un issue público por problemas de seguridad. Contactá directamente al soporte técnico de Agilpay.

## Uso

1. **Realizar una compra de prueba**:
   - Añade un producto al carrito y procede al pago.
   - Selecciona `Agilpay` como método de pago y completa la compra.
   - Serás redirigido a la página de pago de Agilpay.

2. **Verificar el pago**:
   - Una vez completado el pago, el cliente es redirigido de vuelta a tu tienda.
   - Confirmá el pedido en `WooCommerce` > `Pedidos`.

## Logs

El plugin escribe logs en la fuente `agilpay`, visible en `WooCommerce` > `Estado` > `Logs`. Las credenciales nunca se escriben al log.

Si un pago es rechazado, el log dice por qué — un `MessageHash` inválido, un monto que no coincide, o una API key faltante producen mensajes distintos.

## Limitaciones conocidas

- La moneda enviada a Agilpay está fija en `840` (USD), sin importar la moneda configurada en la tienda.
- Los textos de la interfaz no están internacionalizados.

## Soporte

Si tienes alguna pregunta o necesitas ayuda, por favor contacta con el soporte técnico de Agilpay o revisa la documentación oficial de WooCommerce.

## Contribuciones

Las contribuciones son bienvenidas. Por favor abre un issue o envía un pull request en el repositorio del plugin.

## Licencia

Este plugin está licenciado bajo la [Licencia GPLv2](https://www.gnu.org/licenses/gpl-2.0.html).
