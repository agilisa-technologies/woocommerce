# Bug Fix — Redirect Loop en la Página de Confirmación de Pago

**Fecha:** 2026-08-12
**Archivo afectado:** `src/woocommerce-agilpay.php`
**Línea eliminada:** 70 (constructor de `WC_Gateway_Agilpay`)
**Síntoma reportado:** HTTP 500 Internal Server Error en `checkout/order-received/{id}/`

---

## Contexto: cómo funciona el plugin

El plugin de Agilpay es un gateway de pago de tipo **redirect**. El flujo normal es:

1. El cliente elige Agilpay en el checkout de WooCommerce.
2. WooCommerce llama a `process_payment()`, que obtiene un token OAuth de Agilpay y genera un formulario HTML oculto que se auto-envía por JavaScript.
3. El cliente es redirigido al **hosted payment page de Agilpay** para ingresar sus datos de tarjeta.
4. Una vez procesado el pago, Agilpay hace un **POST** al webhook del plugin (`/wc-api/agilpay_response`).
5. El webhook valida la respuesta, marca la orden como pagada en WooCommerce y redirige al cliente a la **página de confirmación** (`checkout/order-received/{id}/`).

---

## El problema

### Síntoma

Al finalizar el pago exitosamente, el cliente veía la siguiente pantalla en lugar de la página de confirmación:

> **"There has been a critical error on this website."**
> HTTP 500 Internal Server Error

Esto ocurría en la URL `checkout/order-received/{id}/`, que es la página de confirmación de WooCommerce.

### Causa raíz: un hook mal colocado

Dentro del constructor de la clase `WC_Gateway_Agilpay`, el plugin registraba el método `receipt_page()` en **dos hooks distintos**:

```php
// Constructor — src/woocommerce-agilpay.php línea 69-70
add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
add_action('woocommerce_thankyou_' . $this->id, array($this, 'receipt_page')); // <- línea problemática
```

Estos dos hooks tienen propósitos completamente distintos en WooCommerce:

| Hook | Página donde se dispara | Propósito |
|------|------------------------|-----------|
| `woocommerce_receipt_{id}` | `checkout/order-pay/{id}/` | Página de pago intermedia — muestra el formulario antes de ir al gateway |
| `woocommerce_thankyou_{id}` | `checkout/order-received/{id}/` | Página de confirmación — se dispara **después** de que el pago fue completado |

El método `receipt_page()` fue diseñado exclusivamente para el primer caso: mostrar el formulario de pago y redirigir al cliente a Agilpay. Su lógica interna hace lo siguiente:

```php
public function receipt_page($order_id) {
    $order = wc_get_order($order_id);

    // Guarda para redirigir si la orden ya fue pagada
    if ($order->get_status() !== 'pending') {
        wp_redirect($order->get_checkout_order_received_url()); // <- redirige a order-received
        exit;
    }

    // Si aún es 'pending', genera el formulario y envía al cliente a Agilpay
    echo $this->generate_agilpay_form($order->get_id());
    echo '<script>document.getElementById("agilpay_payment_form").submit();</script>';
}
```

### Por qué esto genera un loop infinito

Cuando el cliente llega a `checkout/order-received/{id}/` luego de pagar, la secuencia de eventos es:

```
1. Agilpay POST → webhook /wc-api/agilpay_response
       |
       ↓
2. agilpay_handle_response() valida la respuesta
       |
       ↓
3. $order->payment_complete() → orden pasa a status 'processing'
       |
       ↓
4. wp_redirect($order->get_checkout_order_received_url())
       |
       ↓
5. Cliente llega a: checkout/order-received/40/
       |
       ↓
6. WooCommerce dispara hook: woocommerce_thankyou_agilpay
       |
       ↓
7. receipt_page() se ejecuta
       |
       ↓
8. $order->get_status() === 'processing' → no es 'pending'
       |
       ↓
9. wp_redirect($order->get_checkout_order_received_url())
       |
       ↓  ← LOOP: regresa al paso 5
      💥 500 Internal Server Error
```

El método intenta redirigir de vuelta a la **misma página** en la que ya está el cliente. Esto genera un loop de redirecciones. Adicionalmente, el hook `woocommerce_thankyou_{id}` se dispara en medio del renderizado del template de WooCommerce — cuando el HTML de la página ya comenzó a escribirse — por lo que intentar enviar un header de redirección (`wp_redirect`) en ese punto es inválido en PHP, y el servidor responde con un **500**.

---

## El fix

Se eliminó la línea que registraba `receipt_page()` en el hook de la thank-you page:

**Antes (`src/woocommerce-agilpay.php`, líneas 69–70):**
```php
add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
add_action('woocommerce_thankyou_' . $this->id, array($this, 'receipt_page'));
```

**Después:**
```php
add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
```

Un cambio de **una línea**. El hook `woocommerce_thankyou_agilpay` no es necesario para este tipo de gateway — la página de confirmación es manejada completamente por WooCommerce y muestra el resumen de la orden de forma automática.

---

## Por qué el bug pasó desapercibido

El hook `woocommerce_thankyou_{id}` solo se dispara si la orden está asociada al gateway `agilpay`. En instalaciones de prueba donde el flujo no llegaba a completarse (token inválido, sandbox sin respuesta, etc.), la página de confirmación nunca se cargaba, y el bug quedaba oculto. Solo se manifestó en un entorno productivo con el flujo completo end-to-end.

---

## Nota para desarrollo futuro

Si se quiere mostrar información adicional en la página de confirmación (ej. número de autorización, referencia de transacción), se puede agregar un método dedicado que **solo haga `echo` de contenido HTML**. Nunca debe llamar a `wp_redirect()` desde dentro de un hook `woocommerce_thankyou_*`:

```php
// Correcto: solo output, nunca redirect
add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

public function thankyou_page($order_id) {
    $order = wc_get_order($order_id);
    $auth = $order->get_meta('Agilpay AuthNumber');
    if ($auth) {
        echo '<p>Número de autorización: ' . esc_html($auth) . '</p>';
    }
}
```

---

## Entorno donde se detectó

- Servidor: Azure App Service (nginx)
- PHP: 8.4.16
- WooCommerce: 10.x
- Cliente: `raffle-asp-test.azurewebsites.net`
