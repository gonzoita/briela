# Hilos internos — comentar sobre un documento

## Dónde está

Hay un **botón flotante verde** abajo a la derecha, justo encima del de la IA.
Son dos botones hermanos: el de **destellos** abre el asistente, el de **globo
de diálogo** abre el chat del equipo.

El botón del chat es sensible a dónde estás:

- **Dentro de un documento** (una OP, una cotización, un cliente, una orden de
  compra) → abre el hilo de ese documento.
- **En cualquier otra pantalla** → muestra **lo que tienes pendiente**: las
  solicitudes y tareas que te asignaron y siguen abiertas. Al tocar una, te
  lleva a su documento.

Sobre el botón sale un **globito rojo** con cuántos pendientes tienes.

El hilo también aparece dentro de la propia ficha de la orden de producción,
al final, para quien prefiera verlo ahí.

## Escribirle a una persona

Arriba a la derecha del panel hay un **ícono de personas**. Ahí se busca a
cualquier usuario del sistema por nombre o correo y se le escribe directo.

Es el mismo motor que los hilos de documento, así que un mensaje directo
también puede ser una **solicitud** o una **tarea** con fecha límite — y en ese
caso queda a cargo de quien lo recibe, con su estado pendiente hasta que lo
cierre.

### Adjuntar

Al escribir un mensaje hay dos botones:

**📎 Adjuntar un documento del sistema.** Abre un buscador que encuentra
**cualquier cosa**: cotizaciones, remisiones, órdenes de producción y de
compra, clientes, productos, proveedores, solicitudes, leads, números de serie.
Es el mismo buscador global (Ctrl+K), así que **respeta tus permisos y tu
sede** — no puedes compartir lo que no puedes ver — y cualquier módulo nuevo
aparece ahí solo, sin que haya que programarlo.

Llega como un enlace: quien lo recibe toca y va directo al documento.

**🖼️ Adjuntar una imagen o un archivo.** Hasta 10 MB. Las imágenes se ven
dentro del mensaje; los demás archivos llegan como enlace para descargar. Se
pueden mandar hasta cinco por mensaje.

> Solo se admiten enlaces **internos del sistema**. Si alguien intentara
> adjuntar una dirección externa, se rechaza: si no, el chat sería una vía
> cómoda para mandar enlaces de phishing "de parte de un compañero".

Debajo del buscador aparecen tus **conversaciones recientes**, con el último
mensaje y cuántos tienes sin leer de cada persona. Al abrir una conversación,
sus mensajes se marcan como leídos.

## Qué es y qué no es

Un espacio para que el equipo hable **sobre un documento concreto**, dejando
rastro. La discusión sobre la OP-045 vive dentro de la OP-045, no perdida en el
celular de alguien.

> **No compite con WhatsApp para lo urgente.** Para eso la gente ya tiene el
> celular abierto. El valor de esto es otro: que la conversación quede pegada al
> documento y que se pueda responder "¿por qué se cambió este precio?" mirando
> el hilo.

**El cliente nunca lo ve.** Es interno.

## Tres tipos de mensaje

| Tipo | Para qué | Tiene estado |
|---|---|---|
| **Comentario** | Dejar una nota, explicar algo | No |
| **Solicitud** | Pedirle algo a alguien | Sí: pendiente → resuelta / rechazada |
| **Tarea** | Encargar algo, con responsable y fecha | Sí |

La diferencia importa: un comentario se lee y ya, pero una solicitud **queda
abierta hasta que alguien la cierre**. Arriba del hilo se ve cuántas hay sin
resolver, para que no se pierdan.

## Menciones

Escribir **`@` y el nombre** de alguien le manda un aviso por la campanita.
Funciona con el nombre completo o solo el primero: `@Diego` encuentra a Diego
González. Sin esto, en un hilo activo la gente deja de leer y las cosas se
pierden.

## Avisos que salen solos

- **Te mencionaron** en un documento.
- **Te asignaron** una tarea o una solicitud.
- **Resolvieron tu solicitud** — le llega a quien la pidió, que es el que está
  esperando.

Como todos los avisos del sistema, cada uno se puede apagar en Ajustes. Ver
[Notificaciones](./notificaciones.md).

## Nada se borra: las conversaciones son evidencia

**No hay botón de borrar, y no es un olvido.** Un hilo sirve para responder
"¿quién pidió este cambio?", "¿esto se avisó a tiempo?", "¿quién autorizó el
descuento?". Si cualquiera pudiera quitar lo que dijo, el rastro dejaría de
servir justo cuando más se necesita — que es cuando a alguien le conviene que
no aparezca.

Hay **tres candados**, no uno:

1. **No existe el botón** en la interfaz.
2. **El sistema rechaza la petición** aunque alguien la mande a mano.
3. **El modelo se niega a borrarse por código**: un borrado en cascada, una
   limpieza mal apuntada o un script de mantenimiento fallan en vez de dejar
   un hueco silencioso en la conversación.

Y en la base de datos, **borrar un usuario ya no arrastra sus mensajes**. Antes
sí: estaban en cascada, así que eliminar una cuenta habría borrado en silencio
todo lo que esa persona escribió. Ahora la base lo impide. (El módulo de
Usuarios de todos modos **desactiva** en vez de borrar, precisamente para
conservar el rastro.)

## Nota técnica

- Tabla: `comentarios`, **polimórfica** (`comentable_type` + `comentable_id`).
  El mismo hilo sirve para una OP, una cotización, un cliente o una orden de
  compra, sin una tabla por módulo.
- El tipo de documento llega desde el navegador, así que el controlador tiene
  una **lista blanca**: sin ella, alguien podría colgar comentarios de cualquier
  modelo del sistema. También se comprueba que el documento exista, para no
  dejar hilos huérfanos.
- Componente: `resources/js/Components/HiloComentarios.vue`. Para agregarlo a
  otra pantalla basta con `<HiloComentarios documento="cotizacion" :id="..." />`
  y sumar el modelo a la lista blanca del controlador.

## Pendiente

- **Adjuntar archivos** dentro del hilo (la tabla `archivos` ya es polimórfica,
  así que engancharla es directo).
- **Buscar** dentro de los hilos: se llenan rápido.
- Montarlo en cotizaciones, clientes y órdenes de compra — el backend ya los
  acepta, falta ponerlo en esas pantallas.
