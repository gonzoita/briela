# Bandeja de mensajes — atender a quien escribe

Ruta: `/bandeja` · Permisos: `bandeja.ver`, `bandeja.responder`, `bandeja.asignar`
· Módulo: **Bandeja de mensajes**

## Qué es

Una sola pantalla con todo lo que los clientes escriben desde afuera: WhatsApp,
y los mensajes directos y comentarios de Instagram y Facebook. Se lee, se
contesta, se adjunta, se asigna a alguien y se archiva.

**Qué resolvió.** Los mensajes de WhatsApp se guardaban desde julio de 2026 y no
existía ninguna pantalla para verlos. La campanita avisaba «te escribieron» y el
clic llevaba a `/whatsapp`, que no era ninguna ruta. En la práctica el cliente
solo recibía la respuesta automática, y cualquier cosa que el agente de IA no
supiera contestar se quedaba ahí sin que nadie la viera.

## Cómo se ve

- **En celular, una pantalla a la vez**: la lista ocupa todo, y al tocar una
  conversación el hilo la tapa, con botón de volver. Una bandeja se atiende desde
  el celular, en la calle.
- **En escritorio, dos columnas**: la lista a la izquierda, el hilo a la derecha.

Abrir una conversación **no cambia de pantalla**: el hilo se trae por detrás y la
lista se queda donde está. Quien atiende abre cuarenta conversaciones en una
mañana, y cuarenta recargas del menú son media mañana mirando parpadeos.

La lista se refresca sola cada 20 segundos mientras la pestaña está al frente, y
se detiene cuando se pasa a otra: una pestaña en segundo plano no necesita
contadores frescos.

## La lista

Lo último que se movió, arriba. Es la única lista del sistema **sin control de
orden**, y a propósito: una bandeja ordenada por cualquier otra cosa deja de ser
una bandeja. Lo que sí tiene son filtros, que es como se reparte el trabajo:

| Filtro | Para qué |
|---|---|
| **Canal** | Solo WhatsApp, solo los comentarios de Instagram… |
| **Estado** | Activas, sin leer, archivadas, todas |
| **Quién atiende** | Las mías, las que no son de nadie, las de otra persona |
| **Buscar** | Por nombre o por número |

Cada renglón dice de un vistazo: el canal (por su logo), quién escribió, lo
último que se dijo —con «Tú:» delante si fue la empresa—, por qué línea o cuenta
entró, si no tiene dueño, si ya hay un lead, y si **se venció el plazo** para
escribirle.

## El plazo de 24 horas

Es la regla que más cuesta explicar y la que más se rompe, y no es nuestra: es de
Meta. **Solo se puede escribir texto libre dentro de las 24 horas siguientes al
último mensaje de la persona.**

- La cuenta corre desde el **último mensaje del cliente**, no desde el último de
  la conversación: que la empresa haya contestado hace cinco minutos no reabre
  nada.
- Mientras está abierto, la caja de escribir dice cuánto queda.
- Cuando se cierra, la caja se reemplaza por lo que sí se puede hacer.

**Por qué la pantalla lo pregunta antes de intentarlo.** El error que devuelve
Meta habla de un «re-engagement message» y no dice «se te venció el plazo». Antes
de que la bandeja lo mirara, el mensaje salía marcado como enviado y el cliente
no recibía nada.

| Canal | Con el plazo cerrado |
|---|---|
| **WhatsApp** | Se manda una **plantilla aprobada** (ver [WhatsApp](./whatsapp.md)) |
| **Instagram y Messenger** | No hay forma de escribir primero: hay que esperar |
| **Comentarios** | No tienen plazo: se responden cuando sea |

Los comentarios no tienen plazo porque la respuesta queda colgada de la
publicación, no en el buzón de nadie.

## Responder

Se escribe y se manda. En escritorio, Enter manda y Shift+Enter hace salto de
línea; **en celular no**, porque ahí el Enter es el salto de línea de siempre, y
mandar el mensaje a medias es un mensaje que ya no se puede recoger.

**Lo escrito solo se borra cuando el envío salió.** Un error de Meta —el plazo,
el token, la línea desactivada— no se puede llevar el mensaje que la persona
acababa de redactar, y el motivo se muestra tal cual en vez de un «no se pudo
enviar» que hace reintentar cinco veces.

**Adjuntos** donde el canal los admite. Los archivos se suben a Meta y se mandan
por identificador, no por enlace: así funciona también en una instalación que no
es alcanzable desde internet, y no deja archivos de clientes en una URL
adivinable. El tope es 16 MB, que es el techo más bajo de Meta (video).

Lo que llega **se baja al servidor** y queda en Multimedia con
`categoria='bandeja'`, pegado a su conversación. Los enlaces que da Meta vencen
en minutos: guardar la URL dejaba un historial de imágenes rotas al día
siguiente, que es peor que no tener el archivo —se ve que «había una foto» y no
se puede abrir—.

Una foto, una nota de voz, una ubicación o un toque a un botón ya no entran en
blanco: se describen en una línea, y la ubicación trae su enlace al mapa. Antes
solo se leía el texto, así que todo lo demás aparecía como un renglón vacío.

## Quién atiende

Asignarse una conversación es decir «esta es mía». El dueño del número ya decide
a quién le llega el aviso, pero una línea central la atienden varios y hay que
poder repartir sin reasignar el número entero.

**Tomar una conversación la saca del agente de IA.** Si una persona se hizo
cargo, el agente no vuelve a hablar: dos voces en el mismo chat son peores que
ninguna. Es la misma regla que ya aplicaba cuando el lead caía en manos de un
asesor.

En el selector solo salen los usuarios **activos**: asignarle una conversación a
alguien que ya no entra al sistema es dejarla con dueño y sin atender.

## Archivar

Saca la conversación de la vista sin perder nada. Una bandeja sin esto se
convierte en una lista infinita donde lo de hoy queda debajo de lo del año
pasado.

Archivar **la marca leída**: es «ya la atendí», y dejarla sin leer la haría
contar en el menú para siempre, en una lista que nadie vuelve a abrir.

**Archivar no es bloquear**: si la persona vuelve a escribir, la conversación
vuelve a la bandeja y vuelve a quedar sin leer.

## Instagram y Facebook

Cuatro canales más, en la misma bandeja: los **mensajes directos** y los **comentarios** de
cada red.

### Conectar

Las cuentas se conectan en **Marketing → Redes Sociales → Cuentas**, igual que para publicar:
es la misma autorización y los permisos de mensajería se piden ahí mismo. Si las páginas ya
estaban conectadas desde antes de oct 2026, hay que **volver a conectarlas una vez** para que
Meta conceda los permisos nuevos; mientras no se haga, se publica igual y no llegan mensajes.

En la misma pantalla, el bloque **Cuando alguien escribe** trae la **dirección del webhook**
con botón de copiar. Hay que pegarla en la aplicación de Meta y suscribirla a:

| Red | Campos |
|---|---|
| Instagram | `messages` y `comments` |
| Página de Facebook | `messages` y `feed` |

> **Sin suscribir esos campos la dirección responde pero no llega nada.** Es el error más
> común, el mismo que ya pasaba con WhatsApp y `messages`.

Es **la misma dirección y el mismo token de verificación** para las dos redes y los cuatro
canales, porque Meta manda todo por el webhook de la misma aplicación. El App Secret también es
el mismo, y sin él no entra nada: ver [WhatsApp](./whatsapp.md).

### Los comentarios

Un hilo de comentarios se atiende como un chat: se lee, se contesta y se asigna. Tres cosas lo
hacen distinto, y las tres están resueltas:

- **Es una sola conversación, no una por respuesta.** Se agrupa por el comentario raíz. Si cada
  respuesta abriera su propia conversación, una ida y vuelta de cinco mensajes serían cinco
  renglones en la bandeja.
- **El hilo muestra de qué publicación salió**, con su texto y un enlace para abrirla. Hace
  falta de verdad: «¿cuánto vale?» no se puede responder sin saber qué foto estaba mirando quien
  lo escribió.
- **La respuesta se cuelga del último comentario del hilo**, no del primero. Responder siempre
  al raíz deja todas las respuestas una debajo de otra sin relación con lo último que dijo la
  persona, y el hilo se vuelve ilegible para cualquiera que lo lea desde afuera.

Lo que comenta la propia empresa **no entra**: la respuesta vuelve por el mismo webhook, y sin
ese corte el agente de IA se contestaba a sí mismo en bucle, en público, debajo de una
publicación de la empresa.

### Cuando alguien escribe

El bloque de automatización en `/rrss/cuentas` define qué pasa con cada mensaje o comentario
nuevo. Tiene un interruptor general; apagado, los mensajes se guardan y nada más ocurre.

**1. Avisar por la campanita** en el primer contacto. El aviso va al rol de **vendedores**: una
cuenta de Instagram es de la empresa, no de una persona, igual que el número central de
WhatsApp. Quien la tome se la asigna desde la bandeja.

**2. Que conteste el agente de IA**, en los mensajes directos. Solo conoce lo que ya es público
—quién es la empresa, cómo contactarla, qué vende— y nada de ningún cliente. Se configura en
[Agentes](./agentes.md) marcando el canal de Instagram o Facebook.

> **Por qué estos canales siempre son de perfil público.** En WhatsApp el número da una pista de
> quién escribe y se le puede pedir que lo confirme. En un mensaje de Instagram no hay ninguna
> pista, y montar ahí la verificación de identidad sería pedirle el documento a cualquiera que
> pase — un formulario de recolección de datos disfrazado de chat. Quien quiera hablar de lo
> suyo entra por WhatsApp o por el portal de seguimiento.

**3. …y también los comentarios públicos** es un interruptor **aparte, y apagado de fábrica**.
Una respuesta automática en un chat la lee una persona; en un comentario la lee cualquiera que
pase por la publicación, queda colgada ahí y la indexa Google. Encenderlo es una decisión de la
empresa, no un valor por omisión que alguien descubre cuando ya pasó.

**4. Crear el lead en el CRM**, con el mismo reparto que los formularios web: fijo o rotando.
Por Instagram **no llega teléfono ni correo**: el lead queda con el nombre y el usuario, y se le
sigue contestando por ahí. Un lead sin teléfono al que hay que escribirle por Instagram sigue
siendo una oportunidad de venta.

Cada paso va aislado: que falle el aviso no impide crear el lead, y un error del CRM no deja al
cliente sin respuesta.

### Lo que no se puede, y por qué

| Cosa | Motivo |
|---|---|
| Escribir primero por Instagram o Messenger | Meta no lo permite fuera de las 24 horas, y estas redes **no tienen plantillas** como WhatsApp |
| Mandar archivos desde una instalación interna | Meta no recibe el archivo: recibe una URL y viene a descargarla. Si la dirección solo existe en la red de la empresa, no hay forma |
| Ver todas las fotos de un carrusel | Se guarda la primera y el resto se nombra en el texto. Bajar diez archivos dentro de un webhook que tiene que responder rápido hace que Meta reintente y todo entre dos veces |

## Permisos

Son tres, y están separados porque en la mayoría de las empresas varios pueden
mirar la bandeja y solo los asesores hablan a nombre de la marca:

| Permiso | Qué permite |
|---|---|
| `bandeja.ver` | Leer la lista y los hilos |
| `bandeja.responder` | Contestar y mandar plantillas |
| `bandeja.asignar` | Decidir quién atiende cada conversación |

Quien puede ver y no responder ve el hilo completo, y en vez de la caja de
escribir le sale la razón.

## El número del menú

El pendiente sale en el menú, al lado de «Bandeja»: una bandeja que no avisa
desde afuera es una bandeja que nadie abre. Cuenta las conversaciones sin leer
que **le tocan a esa persona** —las suyas más las que no son de nadie—; las de
otro asesor no son su pendiente.

Viaja dentro de la misma petición que ya trae la campanita, no en una propia. Una
quinta petición por ronda es justo lo que costó trabajo quitar (ver «Velocidad:
nada cuesta una petición por clic» en `CLAUDE.md`).

## Apagar el módulo

Apagar **Bandeja de mensajes** en `/configuracion/modulos` se lleva la pantalla,
sus permisos, la conexión de WhatsApp y su webhook. **El historial se conserva**:
al encender otra vez, todo está donde estaba.

Se lleva el webhook a propósito: sin bandeja no hay dónde leer lo que entra, y
seguir recibiendo mensajes que nadie puede ver es peor que no recibirlos.

Los canales de redes necesitan además el módulo **Redes sociales** encendido.

## Nota técnica

- Tablas: `whatsapp_conversaciones` y `whatsapp_mensajes` para WhatsApp;
  `bandeja_conversaciones` y `bandeja_mensajes` para las redes.

  **Son dos juegos de tablas y una sola pantalla.** No se unificaron porque
  renombrar una tabla está prohibido en un producto instalado (ver «Reglas del
  producto instalable»), y porque el almacenamiento de verdad es distinto:
  WhatsApp identifica a la gente por número de teléfono y Meta por un
  identificador por página. Lo que **sí** es uno solo es todo lo de arriba: una
  lista, un hilo, una caja de respuesta, unas reglas de plazo.

- `App\Support\Canales` — el catálogo de canales y las reglas de cada uno: plazo,
  si admite plantillas, si admite adjuntos, de qué módulos depende. Está en un
  solo sitio porque las reglas son de Meta y cambian por canal; escritas en cada
  pantalla, la bandeja ofrecía botones que la API rechazaba.
- `App\Models\Concerns\EsConversacion` — el plazo y el «sin leer / archivada»,
  compartidos por las dos tablas. La primera versión calculaba el plazo en dos
  sitios y daban respuestas distintas con el mismo dato.
- `App\Services\Bandeja\BandejaService` — la lista (un `UNION` de las dos tablas,
  normalizado en el `SELECT`), los contadores, y asignar / archivar / marcar
  leída.
- `App\Services\Bandeja\CanalBandeja` — lo que cambia de un canal a otro: cómo
  sale un mensaje. Un canal nuevo se agrega escribiendo su adaptador y
  registrándolo en `AppServiceProvider`. Hay dos: `CanalWhatsapp` y `CanalMeta`
  —los cuatro canales de redes entran por uno solo, porque entre ellos lo que
  cambia es a qué endpoint va la respuesta—.
- `App\Services\Rrss\MetaMensajeriaService` — mandar directos y responder
  comentarios. Separado de `MetaRrssService`, que publica en el muro: son dos
  permisos distintos en Meta y dos cosas distintas en el negocio, y juntarlos
  obligaría a una empresa que solo quiere contestar a pedir también los permisos
  de publicar.
- `App\Services\Bandeja\AdjuntoBandeja` — baja y guarda los archivos de
  cualquier canal. Lo único que cambia por canal es cómo se pide el archivo;
  guardarlo es idéntico.
- `App\Services\Bandeja\BandejaAutomatizacionService` — aviso, respuesta del
  agente y lead para los canales de redes.
- `App\Support\UrlPublica` — si la instalación es alcanzable desde internet. Lo
  necesitan el webhook (Meta lo llama) y los adjuntos de Messenger (Meta los
  descarga), y la respuesta tiene que ser la misma en los dos sitios.
- Webhook: `GET/POST /webhook/meta`, con la misma firma y el mismo token de
  verificación que el de WhatsApp.
- La clave de una conversación es `canal:id` y **llega del navegador**, así que el
  canal se valida contra el catálogo antes de tocar la base. Un canal que no
  existe —o que está apagado— responde 404.
