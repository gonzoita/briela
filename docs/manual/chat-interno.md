# Chat interno — mensajes directos y grupos

Rutas: `/api/chat/*` · La burbuja abajo a la derecha, en cualquier pantalla.

Para hablar con un compañero sin salir del sistema ni cambiar de aplicación.

## Dos formas

- **Directo**: una conversación con otro usuario.
- **Grupos**: varios usuarios, con su nombre.

## Adjuntar un documento del sistema

Se puede adjuntar una cotización, una OP o un producto **por referencia**: en el chat queda un
enlace que lleva al documento. Es la diferencia con un chat cualquiera — el mensaje «revisa la
COT-2026-014» se convierte en algo en lo que se puede hacer clic.

## En vivo, Enter, pegar y responder *(15 oct 2026)*

- **En vivo**: con una conversación abierta, el chat pregunta cada 4 s solo por lo nuevo
  (`?desde={id}`), en pausa con la pestaña oculta. Antes la conversación se pedía una vez y lo que
  llegaba después no aparecía hasta cerrarla. No hay websockets: la instalación vive en el hosting
  del cliente.
- **Enter envía**, Shift+Enter hace una línea nueva.
- **Ctrl+V pega una imagen** (una captura de pantalla) y se adjunta sola. Una imagen se puede
  mandar sin texto.
- **Responder**: la flecha junto a cada mensaje lo cita; la respuesta muestra la cita y un toque
  lleva al original (`comentarios.respuesta_a_id`). Solo se responde a mensajes de la misma
  conversación.
- **Las notificaciones del chat ya no dan 404**: abren la burbuja en esa conversación, donde uno
  esté. El enlace `/chat/{usuario}` o `/chat/grupos/{grupo}` (el del correo) lleva al tablero con
  la burbuja abierta.

## Diferencia con los hilos internos

| | Chat | Hilos internos |
|---|---|---|
| Dónde vive | Aparte, en la burbuja | **Dentro** del documento |
| Para qué | Hablar con alguien | Dejar constancia sobre ese documento |
| Quién lo ve después | Los que estaban | Cualquiera que abra el documento |

Una decisión sobre una cotización va en su hilo, no en el chat: el que la lea en tres meses
tiene que encontrarla ahí. Ver [Hilos internos](./hilos-internos.md).
