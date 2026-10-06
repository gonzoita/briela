# Correo — sale por el panel de Briela

## Cómo funciona

Igual que la IA: **la instalación no tiene credencial de ningún proveedor de correo.** Envía
al panel de Briela (`superadmin.briela.app`) identificándose con su serial, y el panel lo
manda con Brevo o con Amazon SES, que son quienes cuidan la entrega.

```
Briela ──(serial + correos)──► panel de Briela ──(clave del proveedor)──► Brevo / Amazon SES
   ▲                                │
   └── rebotes, quejas y bajas ◄────┘  (correo:sincronizar-eventos, cada hora)
```

Cada instalación envía desde **su propio subdominio**, que el panel crea de un clic en una
zona de Briela en Cloudflare: `fabrica-acme.envios.briela.app`. El cliente no toca su DNS.
El panel escribe los registros DKIM, SPF y DMARC, y el proveedor los verifica.

| Remitente | Para qué |
|---|---|
| `notificaciones@su-subdominio` | Avisos del sistema, cotizaciones, formularios. **No se cobran** |
| `boletin@su-subdominio` | Boletines y campañas. Se cobra lo que pase de lo incluido en el plan |

Las respuestas llegan al correo de la empresa (Configuración → Perfil fiscal).

## En el código

- `App\Mail\TransporteBriela` — un transporte de Laravel como el SMTP. Todo `Mail::` del
  sistema sale por ahí sin cambiar nada. Un correo masivo se marca con la cabecera
  `X-Briela-Tipo: masivo`; sin ella es una notificación.
- `SmtpConfigService::aplicar()` decide la ruta: si el último latido dice
  `correo.disponible`, el correo va por el panel; si no, por el SMTP propio.
- **Respaldo:** si el panel no toma el correo (no responde, dominio sin verificar), sale por
  el SMTP de la instalación, si tiene uno. Lo que el panel **rechaza de fondo** —el tope
  diario de notificaciones, una suscripción vencida para masivo— no se reintenta por otro
  lado.
- `correo:sincronizar-eventos` (cada hora) baja del panel los rebotes duros, las quejas y las
  bajas a `correo_supresiones`. A esas direcciones no se les vuelve a escribir: el panel
  tampoco lo permite.

## Las reglas del panel

- Las notificaciones **no se cobran nunca** y no las detiene una suscripción vencida. Solo
  tienen un techo diario alto (por defecto 2.000 por instalación) para que no se usen como
  boletín gratis.
- El masivo exige la suscripción al día. Lo que pase de lo incluido en el plan se suma a la
  factura del mes, al precio por mil del plan. Puede tener un tope mensual.
- El panel puede **suspender** el correo de una instalación ante un abuso.

## Pantalla

**Configuración → Correo**: por dónde sale, los remitentes, el consumo del mes contra lo
incluido, un envío de prueba y la lista de direcciones suprimidas. El SMTP de la pestaña
Email queda como respaldo.

## Lo que viene

Suscriptores con doble confirmación y formulario de inscripción, editor de boletines por
bloques, campañas con estadísticas y automatizaciones. Todo sale por este mismo camino.
