# Historial de versiones de Briela

Las notas de cada versión se le muestran al cliente en el botón de actualizar,
así que se escriben para quien **usa** el sistema, no para quien lo programa.

Formato: [versionado semántico](https://semver.org/lang/es/).

- **Mayor** (1.0.0 → 2.0.0): cambios que exigen intervención o rompen algo.
- **Menor** (1.0.0 → 1.1.0): funcionalidad nueva, compatible.
- **Parche** (1.0.0 → 1.0.1): correcciones.

> Regla del producto: las migraciones de cada versión deben poder correr sobre
> cualquier versión anterior soportada. Ver `docs/BRIELA-PLAN.md` sección 6.3.

## [Sin publicar]

### Agregado
- **Secciones como zonas en la cotización**: creas una sección (+ Sección), le arrastras los ítems o
  usas «+ Ítem» dentro de ella, y las secciones también se reordenan arrastrándolas. Cada sección
  muestra su subtotal en vivo, se pliega y se renombra ahí mismo. Lo que no tiene sección se imprime
  como «Otros». En el celular, la sección y el orden se cambian desde el detalle de cada ítem.
- **Ítems en vista de tabla**: cada ítem ocupa una sola fila (descripción, cantidad, precio, descuento,
  IVA y subtotal, editables ahí mismo). Al tocar la fila se expande con la descripción, la
  disponibilidad, la comisión y el resto. El botón **Detallado** abre todos a la vez.
- **Activar o desactivar el IVA y las retenciones estimadas** en los totales de la cotización. Con el
  IVA apagado, las líneas quedan en 0 % y el total sale sin impuesto; al encenderlo, cada línea recupera
  su tarifa. Las retenciones estimadas se pueden ocultar (el navegador lo recuerda).

### Corregido
- Reordenar los ítems de una cotización ya guardada ahora se conserva al guardar (antes volvían a su
  posición anterior).

## [2.3.0] — 15 oct 2026

### Agregado
- **Secciones en las cotizaciones**: cada línea puede llevar una sección («Puerta corredera», «Cocina»,
  «Preparación»). En el PDF, cada sección sale con su título, sus productos y su subtotal.
- **Opciones del documento en las plantillas PDF de cotización**: agrupar por sección con subtotal,
  con o sin foto del producto, y con o sin precios. Siempre visibles al editar la plantilla.
- **Editar el nombre de un producto desde la lista**: junto a cada nombre hay un lápiz. Lo tocas, cambias
  el texto, y Enter lo guarda (Esc lo cancela). Funciona en la lista y en las tarjetas.
- **Marca, origen y características especiales de cada producto**, y un interruptor para publicar o no
  su precio en la tienda en línea. Sin precio, la tienda muestra «Cotizar».
- **Planos y fichas técnicas en los productos**: se adjuntan en el detalle del producto (PDF, imagen,
  DWG o DXF) y se ven en la tienda en línea, en su propia pestaña.
- **Adjuntar planos y fichas técnicas en la cotización**: una cuarta opción de la plantilla PDF agrega
  al final los documentos de los productos cotizados.
- **Catálogo sin precios**: un botón en Productos descarga el catálogo completo en PDF, sin ninguna
  cifra. El sitio web ofrece el suyo para descargar.
- **Tienda en línea (plugin Briela Connect 0.2.0)**: un producto con variantes se publica como un solo
  producto y el cliente elige la variante en la ficha; marca, origen y características salen como
  atributos, y los planos y fichas técnicas en una pestaña.
- **Diferencia en cambio en las importaciones**: la importación guarda la TRM de nacionalización (la
  de la declaración), con la que sale el costo. El botón **Registrar pago** anota cada anticipo o
  saldo al proveedor con su TRM, y Briela muestra lo pagado, el saldo y la ganancia o pérdida en
  cambio, sin mover el costo del producto.
- **Identificación fiscal de cualquier país**: donde decía «RUT» ahora dice identificación o
  documento fiscal. La empresa puede ser de otro país (Perfil fiscal), y sus PDF dicen RFC, RUC o EIN
  en vez de NIT. Llegan México, Ecuador, Perú, Chile y Estados Unidos (valores de partida, por
  confirmar con un contador), y todo país acepta una identificación del exterior en formato libre.
- **Inventario en tránsito**: lo pedido a proveedores en órdenes enviadas y todavía sin recibir.
  Se ve en la lista y la ficha de productos y en el tablero de inventario (con las órdenes atrasadas).
  Al cotizar se usa primero lo que hay en bodega y después lo que viene en camino, con aviso («30 en
  bodega + 10 en tránsito, llegan ~21 oct»); se aparta igual que el inventario real; el PDF le dice
  al cliente cuándo llega; y al recibir la orden se avisa al vendedor que contaba con esa mercancía.
- **Buscar productos por el código del proveedor** en la lista, la cotización, la orden de compra,
  la importación y el buscador Ctrl+K, con el código que coincidió a la vista.
- **Órdenes de compra en otra moneda y editables**: al crear una orden eliges la moneda del proveedor
  (dólar, euro…) y su tasa; los precios de las líneas se escriben en esa moneda y la orden, el PDF y
  la lista la muestran así, mientras el inventario, el costo y los informes siguen sumando en pesos.
  Y ahora **se puede editar** una orden mientras no esté recibida por completo: en borrador cambia
  todo; ya enviada o con recepciones, lo recibido se respeta (no se quita una línea que ya entró ni se
  baja su cantidad de lo recibido, y el proveedor y la moneda se quedan).
- **Mínimos y máximos de inventario a un toque**: se editan en el mismo tablero de inventario, sin abrir
  el modal, y aceptan decimales.
- **Margen del canal base en 0 %**: si el costo que registras ya incluye la utilidad mínima de la
  empresa, el canal base puede ir sin margen y el precio es exactamente el costo. Al cambiar un margen
  se ofrece recalcular los precios y las comisiones.
- **Duplicar o editar una cotización la reprecia según el segmento del cliente**: si el cliente es de
  otro segmento (o cambió de canal), los precios de las líneas se actualizan, con aviso de lo que cambió.
- **Los agentes del Equipo de IA pueden poner en marcha automatizaciones**: marcas una automatización
  como «Disponible para los agentes» y el agente decide cuándo y sobre qué documento lanzarla; ella hace
  el trabajo sin gastar tokens y lo delicado sigue pidiendo aprobación. Además, dos plantillas nuevas
  despiertan a un agente solo cuando hay algo que revisar (cotizaciones frías, stock bajo).

### Corregido
- **Dos decimales en todos los precios**, en todos los módulos y PDF (antes algunos se mostraban en
  pesos enteros).
- El modal **Editar** del inventario no guardaba (el campo de descripción corta era más largo que lo
  permitido, y no decía por qué): ahora guarda y muestra el error si algo falla.
- La columna **Precio Prom.** del inventario mostraba $0 aunque el costo estuviera definido: ahora
  muestra el costo vigente. El valor del tablero y el conteo de «stock bajo» tampoco cuentan ya los
  productos sin mínimo configurado.
- En una instalación de una sola sede, el tablero de inventario no mostraba existencias.
- En Ctrl+K, proveedores y solicitudes de compra abren su lista filtrada en vez de una pantalla que no existe.
- El PDF de cotización por omisión y el recibo de pago ya no imprimen un NIT y una ciudad de
  ejemplo: muestran la identificación y la ciudad de la empresa.
- Guardar una cotización ya no borra y vuelve a crear todas sus líneas: se conservan.
- El bloque de totales de las plantillas PDF ya no deja el total destacado sin color.
- El asistente ya no dice que «no tiene la herramienta» cuando una instrucción llega cortada. Ahora lo
  explica y te pide partirla en lotes de 10 o menos.
- Las listas largas de cambios (20 productos o más) caben en una sola tarjeta de confirmación.
- «¿Cuántos productos tenemos?» responde con el resumen del catálogo: activos, inactivos, variantes,
  categorías con más productos y nombres repetidos.

## [2.2.0] — 2026-10-13

### Agregado
- **El asistente diferencia productos que quedaron con el mismo nombre**: pídele «arregla los nombres
  repetidos de los tiradores» y busca los que se llaman igual, propone un nombre distinto para cada
  uno según su descripción o variante (por ejemplo «Tirador pasante palanca plana 121-140mm») y los
  renombra todos juntos cuando confirmas. Si no ve qué los diferencia, te pregunta, y nunca deja un
  nombre repetido nuevo.

## [2.1.0] — 2026-10-13

### Agregado
- **La oficina en vivo del Equipo de IA**, en pixel art como un juego: el nombre de tu empresa en la
  pared, la oficina del director y un cubículo por agente. Cada uno trabaja en su escritorio con un
  globo que dice qué está haciendo; cuando necesita tu aprobación camina hasta la oficina del
  director y espera con la mano levantada; si algo falla, está junto al servidor que echa humo. Un
  chat cuenta en qué va cada agente y un tablero muestra agentes activos, propuestas por aprobar,
  consumo de IA, lo vendido hoy y las órdenes en curso. Al tocar a un agente ves su ficha y lo puedes
  editar, ejecutar o seguir paso a paso. Tiene pantalla completa para dejarla en un televisor.
- **La oficina se ve más nítida y los personajes tienen más detalle**: caras con ojos que parpadean,
  cejas y gestos según cómo les va, ropa con sombras y brazos que se mueven al caminar, monitores,
  teclados y un holograma en el centro de control.
- **El agente principal aparece en la oficina coordinando al equipo**: es tu asistente del chat. Lo
  ves en el centro de control enviando señales a cada agente que trabaja; al tocarlo puedes hablarle.
- **El asistente configura a los demás agentes desde el chat**: pídele que encienda, apague o
  programe un agente, que cree uno nuevo, que lo ponga a trabajar ya o que le cambie las
  instrucciones, también a los agentes que atienden a tus clientes. Siempre te muestra el cambio y
  espera tu confirmación.
- **Recepción en vivo en Atención a clientes**: tus agentes atienden detrás de un mostrador y cada
  cliente que te escribe entra por la puerta, hace fila frente al agente de su canal, espera en la
  sala cuando ya le respondieron o pasa al asesor humano cuando pide una persona. Al tocar a un
  cliente se abre su conversación en la bandeja.

## [2.0.0] — 2026-10-13

### Agregado
- **Equipo de IA**: agentes internos que trabajan para tu equipo. Leen los datos del negocio con los
  permisos de su cargo, te avisan lo importante y dejan **propuestas** que tú apruebas o rechazas;
  ninguno cambia nada por su cuenta. Está en Agentes → pestaña «Equipo interno», y **viene apagado**:
  se enciende en Configuración → Módulos.
- Vienen tres agentes de fábrica, apagados: **Analista del día** (cómo amaneció la empresa),
  **Vigía de producción** (por qué una orden no llega a tiempo) y **Seguimiento comercial** (deja
  redactado el siguiente contacto de cotizaciones y leads parados).
- Puedes crear tus propios **cargos** y **agentes**: eliges el modelo (con un modelo de respaldo si el
  principal falla), editas las instrucciones —con vista previa del prompt final y versiones que se
  pueden restaurar—, eliges qué herramientas tiene, cuándo corre y a quién avisa. «Probar» corre el
  agente sin escribir nada ni avisar a nadie.
- **Aprobaciones**: antes de hacer algo se vuelve a revisar tu permiso, la sede y el estado del
  documento. Lo aprobado queda a tu nombre. Las propuestas vencen a las 72 horas.
- **Consumo**: la IA ahora anota cada llamada (modelo, tokens, quién la hizo). Los agentes tienen un
  **cupo diario aparte del asistente**: uno que se pasa no te deja sin asistente.
- **Pausar todo** detiene a todos los agentes de inmediato.
- **Conectar WhatsApp con un botón, sin sacar el número del celular.** La vinculación guiada de
  Meta deja el número funcionando en Briela **y** en la app WhatsApp Business del teléfono: lo que se
  escribe desde el celular aparece en la bandeja, y lo que se responde aquí sale por el mismo número.
  El sistema guarda la cuenta, el número y el permiso, y deja suscritos los avisos de Meta sin pasos
  manuales.
- **Facebook e Instagram suscriben solos sus avisos** al conectarse la página, y hay un botón para
  volver a suscribir todo cuando una conexión «salió bien» pero no llega nada.
- **Automatizaciones**: «cuando pase esto, haz aquello», conectadas a todo Briela. Arrancan con eventos de
  cotizaciones, CRM, clientes, producción, calidad, remisiones, cartera, inventario (stock bajo), compras,
  mantenimiento, RRHH, capacitación, la bandeja de mensajes y el Equipo de IA; a una hora; o revisando cada
  mañana documentos que cumplan una condición («cada cotización enviada hace 7 días…»).
- Pasos: condiciones, esperas de hasta 90 días que deciden con los datos del día, y **aprobaciones**: la
  automatización pregunta a una persona y solo sigue si dice que sí.
- Acciones: avisar, dejar comentarios o tareas en el documento, tareas y notas comerciales, mover y repartir
  leads, repartir conversaciones, pedir compras del faltante, redactar y clasificar con IA, poner a trabajar a
  un agente y enviar los datos a otros sistemas (webhook).
- Nacen apagadas, se prueban con datos de ejemplo o con un documento real sin efectos, guardan su historial y se
  pausan solas tras cinco fallas. Vienen 17 plantillas. El módulo **viene apagado**: se enciende en
  Configuración → Módulos.

### Corregido
- **Los mensajes y comentarios de Instagram y Facebook no entraban**: la dirección que recibe esos
  avisos rechazaba a Meta. Ya entran; Meta reintenta lo pendiente.
- Un aviso de «entregado» que llegaba tarde ya no deshace el «leído» de un mensaje de WhatsApp, y un
  mensaje que no se pudo entregar dice por qué.

### Para quien instala
- Hace falta el cron del servidor (`schedule:run` cada minuto): hace de *worker* de la cola. Sin él
  los agentes no corren.
- Migraciones nuevas, solo agregan: `ia_consumo`, cargos, agentes, ejecuciones, pasos y
  propuestas, y cuatro columnas nulables en `whatsapp_numeros` (cuenta, coexistencia, PIN y fecha de vinculación). El módulo del Equipo de IA queda apagado en toda instalación existente.
- El superadmin debe estar en la versión que trae el cupo de agentes
  (`X-Briela-Origen: agentes`); sin ella los agentes comparten el cupo del asistente.

## [1.6.0] — 2026-10-12

### Agregado
- **Informes con archivo desde el chat**: a la guía se le puede pedir un informe («cuántos clientes
  llegaron el último mes, cuáles cotizaron y cómo mejorar») y responde con el análisis y una tarjeta
  para descargarlo en Excel o CSV. Las cifras las calcula el sistema, no la IA, y el informe queda
  guardado en Informes.
- **Informes: seis fuentes nuevas** —leads del CRM, cotizaciones y ventas, clientes, órdenes de
  producción, cartera e inventario— con periodo por nombre («el mes pasado»), resúmenes por canal,
  vendedor, estado o mes, y cifras clave.
- **Descarga en Excel (.xlsx)** con hojas de resumen, análisis, detalle y una por cada resumen; y CSV
  que Excel en español abre bien.
- **Alistamiento parcial**: de 15 unidades vendidas se pueden alistar 10 y remisionar esas 10. La
  remisión dice cuánto queda pendiente de cada ítem, en pantalla y en el PDF.
- **Remisión «Cerrada»**: una remisión entregada se cierra sola cuando la orden queda pagada con
  pagos verificados. Mientras tanto se ve «Entregada — pendiente de pago». Los pagos en efectivo o
  cheque se pueden marcar como verificados en la cartera.
- **Foto de perfil y firma del usuario**: cada persona pone su foto y su firma (dibujada o subida) en
  Mi perfil. La firma sale sola en cotizaciones, órdenes de producción, órdenes de compra y
  remisiones.
- **Perfil de marca: Completar con IA**: la IA formula las preguntas de cada sección para tu empresa
  y las responde sola con lo que el sistema sabe de ella. «Importar documento» ahora es
  «Importar MD» y acepta archivos Markdown.
- **Chat**: los mensajes llegan en vivo con la conversación abierta, Enter envía, Ctrl+V pega una
  imagen y se puede responder a un mensaje concreto.
- **Avisos en vivo**: lo que llega a la campana aparece sin recargar, con enlace directo, y la
  pantalla abierta se actualiza sola si el aviso es suyo. El responsable de una cotización se entera
  de todo cambio de estado que no hizo él.
- **La guía de Briela gestiona procesos.** Además de responder, ahora puede generar órdenes de
  producción, alistar, remisionar, mover remisiones, registrar pagos, confirmar abonos del banco,
  marcar pasos de planta, aprobar calidad, cambiar el estado de cotizaciones y mover o registrar
  leads. Siempre muestra primero una tarjeta con lo que va a hacer y solo lo hace al tocar
  «Confirmar», con los mismos permisos de quien la usa.
- **El lead del CRM se mueve solo**: pasa a ganado cuando se aprueba su cotización, y a perdido
  —con el motivo— cuando se rechaza o se vence, si no tiene otra cotización abierta.

### Cambiado
- **Lo remisionado sale del almacén al crear la remisión.** Un producto sale tal cual (si se
  remisionan cuatro bisagras, salen cuatro bisagras) y un ensamble sale por todos sus componentes.
  Cada salida queda en los movimientos del producto, y anular o borrar la remisión lo devuelve.
  Terminar un ensamble en planta ya no mueve inventario ni pide bodegas.
- **Un producto sencillo solo se alista**: ya no pasa por planta. Los productos que se habían
  mandado a planta con la versión anterior quedan para alistar (o alistados, si ya estaban
  terminados).

### Corregido
- **Ninguna cotización ni orden de producción se procesa sin cliente**: no se guardan sin él, y las
  viejas sin cliente no se envían, aprueban, alistan ni remisionan hasta asignarle uno.
- **La firma de la remisión funciona**: el trazo cae donde está el dedo, no se guarda un recuadro en
  blanco, los errores se muestran, se guarda quién firmó y las firmas salen en el PDF.
- Las notificaciones del chat ya no dan error 404: abren la conversación.
- Las etiquetas de estado de cotizaciones y remisiones ya se leen en modo noche.
- **Un color de marca oscuro ya se lee en modo noche** (y uno muy claro, de día). Cada tema usa el
  mismo tono con la luz justa para que enlaces, menú activo y botones con borde se lean; si el color
  ya se lee, no cambia. La pantalla de Marca avisa cuando se ajustó, y los documentos conservan el
  color exacto.
- Convertir un lead en cliente ya no crea un cliente repetido: si ya existe con ese documento,
  correo o celular, se vincula. Una empresa nueva nace con su contacto.
- La cotización que se crea sola desde el CRM usa las condiciones comerciales de la empresa.
- Las novedades que reporta un cliente al agente de IA llegan al CRM con su origen y avisan a quien
  debe atenderlas.
- Si un cliente vuelve a llenar un formulario, el aviso dice que volvió a escribir.
- Un lead de otra sede ya no se abre por enlace directo.

## [1.5.0] — 2026-10-11

### Agregado
- **Plazo de entrega en la cotización** (días hábiles). Se ve en la cotización, en el enlace del
  cliente y en el PDF, y al generar la orden se convierte en su fecha de entrega, que se puede
  ajustar ahí mismo. Antes la orden nacía sin fecha.
- **Productos que pasan por planta.** En la ficha de un producto se puede prender «Se procesa en
  planta» con sus pasos y su revisión de calidad, para lo que hay que cortar, pintar o empacar antes
  de entregar. En cada orden sus unidades salen a Trabajos con su QR, y quedan listas para
  remisionar al terminarlas. Desde la orden también se puede mandar a planta cualquier producto con
  «Procesar en planta», eligiendo el flujo de pasos.

### Corregido
- **Una orden en borrador ya se puede trabajar.** Alistar un producto, remisionarlo o avanzar un
  paso la pasa sola a «En producción», con las bodegas precargadas. Antes alistar en borrador no
  movía nada, y Alistamiento y Remisiones ni siquiera mostraban la orden.
- **Un producto se puede remisionar sin alistarlo antes**: al meterlo en la remisión queda alistado.
  El remisionador marca los que todavía no lo estaban.
- «Sacar de planta» ya no borra unidades con trabajo registrado ni unidades que ya salieron.
- **Un servicio no frena la orden.** Una instalación en obra se hace después de entregar: la orden
  pasa a calidad y se despacha cuando está listo lo que se fabrica, sin esperar a que el servicio
  se marque como hecho.
- **Rechazar en calidad devuelve el trabajo a planta**, también desde el botón de la orden: las
  unidades a rehacer se reabren y pierden la aprobación, y la orden ya no vuelve sola a «Calidad»
  al abrirla. Antes las unidades rechazadas se podían seguir remisionando.
- **Un producto nuevo nace vendible e inventariable**, con su stock inicial a la vista. Antes nacía
  sin control de existencias si nadie prendía el interruptor.
- **La comisión del vendedor se confirma cuando el cliente aprueba por el enlace**, sin esperar a
  que se genere la orden.
- Al crear una remisión se abre la remisión, lista para confirmarla y despacharla.
- **Duplicar una cotización** ya no da error, y la copia nace editable.
- Confirmar una orden sin bodegas ahora **dice qué falta** en vez de no hacer nada.

## [1.4.0] — 2026-10-11

### Agregado
- **Remisiones: «Por remisionar» y «Remisiones creadas».** La pantalla abre con las órdenes que
  tienen algo listo y todavía no salieron, con cuántas unidades hay de cada cosa y un botón para
  remisionarlas con todo precargado.
- **Verificador de pagos.** Confirma con el banco que el dinero de cada cuota llegó, sin que alguien
  tenga que revisar la cuenta. Subes el extracto del banco (Excel o CSV, de cualquier banco) y
  Briela empareja cada abono con la cuota que corresponde: lo que no deja duda —la orden nombrada
  y el valor exacto, o el valor exacto con el cliente en la descripción— se registra solo como
  pago «Verificado por el banco»; lo que tiene más de una explicación queda **por decidir** con
  un clic, y el dinero que nadie reclama queda a la vista como **sin dueño**. Subir dos veces el
  mismo extracto no duplica nada. Solo lee: no mueve ni paga nada, y solo guarda los últimos
  dígitos de la cuenta. Es un módulo que se enciende y se apaga desde Configuración → Módulos. Avisa a los administradores
  cuando un extracto deja abonos por revisar, y al responsable de la orden cuando el banco confirma
  uno de sus pagos.
- **Varios países.** Cada sede tiene su país y la moneda de sus documentos; los clientes y
  proveedores, el suyo. Las cotizaciones ofrecen los impuestos del país de la sede (IVA, ITBMS…),
  el cliente elige entre los documentos de identidad de su país (NIT, RUC, RIF…) y la IA lee el
  documento fiscal de cada país. Vienen los paquetes de Colombia, Panamá y Venezuela
  —los dos últimos son valores de partida que debe confirmar un contador—, y cualquier otro país
  funciona con lo que la empresa escriba en Configuración → Países.
- **Moneda base a elección** y 20 monedas. Las que publica el Banco Central Europeo (dólar, euro,
  libra, yuan, peso mexicano, real, dólar canadiense) se traen solas; las demás se escriben a mano.
- **Importaciones** (Compras → Importaciones): se carga la factura del proveedor, el flete, el
  seguro y los gastos, y Briela calcula cuánto cuesta cada producto puesto en bodega —con arancel,
  impuesto y gastos repartidos por valor, peso o cantidad— y a cuánto quedaría en cada canal.
  Al liquidar, ese pasa a ser el costo del producto y se recalculan sus precios. Los productos
  guardan su código arancelario y su arancel habitual.

### Corregido
- **La orden de producción cambia de estado sola en todo el flujo.** Alistar un producto ya mueve
  la orden (a «En producción» con el primero, a «Calidad» con todos, y de vuelta si se desalista);
  antes solo lo hacía cerrar pasos en planta. Las órdenes que quedaron atascadas se ponen al día
  solas al actualizar.
- **Los productos alistados se pueden remisionar desde la orden.** El botón pedía una aprobación
  de calidad que una orden de productos nunca tiene, y el remisionador no los ofrecía.
- **La orden se despacha cuando la remisión sale**, no al crearla en borrador, y una orden con un
  servicio incluido ya llega a «Despachada».
- **Los productos despachados salen del inventario** al salir la remisión, y vuelven si se anula.
  Despachar, anular y volver a despachar ya no descuenta dos veces.
- **Anular una remisión devuelve solo lo suyo**: un producto repartido en dos remisiones ya no
  vuelve entero, y eliminar una remisión anulada no suelta las unidades de otra.
- **Calidad sella la orden cuando están todas las unidades revisadas**, no con la primera.
- **Una unidad que vuelve a planta deja de figurar como lista.**
- **La lista de órdenes para remisionar solo trae las que de verdad tienen algo listo.**
- Una remisión entregada o anulada ya no cambia de estado, una que salió no se elimina (se anula),
  y crear o mover remisiones pide su propio permiso.

## [1.3.0] — 2026-10-10

### Agregado
- **La orden de compra es coherente con el módulo de productos.** El buscador encuentra **todo lo
  que se puede comprar** —insumos, productos de reventa y variantes con su nombre completo—, no solo
  los marcados como insumo, y lo encuentra por el nombre, la referencia interna **o el código de
  cualquiera de sus proveedores** (`R125458` lleva a la bisagra `IC5260`). Cada línea muestra la
  referencia interna, el código del proveedor de la orden y **«También lo venden»**: los otros
  proveedores con su código, su precio, su nota y cuál es el más barato vigente. La ficha de la
  orden lo muestra igual, con enlace al producto, y la recepción trae a la vista los dos códigos
  para cotejarlos con la factura. El PDF que recibe el proveedor no dice a quién más se le compra.
  La pantalla ya no carga todos los productos al abrirse: busca en el servidor.
- **La calificación de proveedores se ajusta a lo que le importa a la empresa.** Configuración →
  Calificación de proveedores: cuánto pesa cada componente (puntualidad, cumplimiento, entregas en
  regla, precio), los días de gracia de una entrega tarde y cuántas órdenes hacen falta para dar
  nota. Las notas no se guardan, así que cambian al instante.
- **Retenciones en las órdenes de compra.** La ficha de la orden estima lo que la empresa le
  retiene al proveedor al pagar —en la fuente y de IVA— según el RUT de la empresa y el del
  proveedor, y muestra el neto a pagar. Cada retención que no aplica dice por qué. Es una
  estimación: no cambia el total de la orden.
- **Una cotización enviada aparta su stock por 24 horas.** Es una marca, no un movimiento: el
  inventario no se mueve, pero quien cotiza ve «N apartadas en otras cotizaciones» junto al
  stock (se refresca solo cada minuto) y puede cotizar igual. A las 24 horas se libera sola y se
  le avisa al vendedor; guardar de nuevo no renueva el plazo. Si una venta se aprueba y necesita
  unidades que otra cotización tenía apartadas, se le ceden —las más nuevas primero— y se avisa
  al vendedor afectado **y** a administración. Pantalla nueva «Stock apartado» en Productos y
  Existencias, y el recuadro «Stock apartado» en la ficha de cada cotización. Solo uso interno:
  el cliente no ve nada de esto, ni en el portal ni en el PDF. Se libera con la tarea programada
  del sistema (la misma del cron de siempre, cada 15 minutos): no hace falta configurar nada nuevo.
- **Calificación automática de proveedores.** Cada proveedor tiene una nota de 0 a 100 que calcula
  el sistema con sus órdenes del último año: puntualidad, cumplimiento de lo pedido, entregas que
  llegaron con factura o remisión y a tiempo, y qué tan competitivo es su precio. Se ve en la
  lista de proveedores, en su ficha y al elegirlo en una orden de compra. Con menos de tres
  órdenes evaluables dice «Sin calificar» en vez de inventar una nota. El asistente la usa junto
  al precio para recomendar a quién comprarle.
- **Cada movimiento de inventario dice de dónde vino y con qué papel.** En la ficha del producto
  ves, por cada movimiento, el stock antes y después, su origen con enlace (orden de compra, orden
  de producción, ajuste), la factura o remisión con su número y fecha, y las observaciones.
  También las remisiones en las que salió el producto, y el historial completo por tandas.
- **Al recibir una orden de compra se registra la factura o remisión del proveedor**, la fecha de
  llegada y las observaciones, en cada entrega. La ficha de la orden lista todas sus entregas.
- **El ajuste de stock acepta el papel que lo respalda** (factura, remisión u otro).
- **Cargar un proveedor desde su RUT.** En Proveedores, sube el RUT (PDF o foto) y se llenan sus
  datos y responsabilidades tributarias. Avisa si ese proveedor ya existe. En la orden de compra,
  un proveedor que según su RUT no es responsable de IVA arranca sin IVA.
- **La orden de compra lleva el código de cada proveedor.** Un mismo producto lo pueden vender
  varios proveedores con códigos distintos; la orden manda el de quien la recibe, y el PDF lo
  muestra primero. Se configura en la ficha del producto o directamente al armar la orden: si
  escribes un código nuevo, queda guardado para las próximas. Al elegir proveedor, cada línea
  se llena sola con su código y su último precio, y se avisa si ese precio tiene más de 90 días.
- **Historial de precios por proveedor.** Cada vez que una orden se envía, el precio queda
  anotado. El asistente puede comparar proveedores con esos datos y no recomienda un precio viejo.
- **Cada variante puede tener su propia imagen.** Al crear un producto con variantes, o al
  agregar una variante desde la ficha del producto principal, puedes subir su imagen. Si no
  subes ninguna, usa la del producto principal en el buscador de cotizaciones, en su ficha y
  en el catálogo; y si el principal cambia su foto, las variantes que la heredan cambian con él.

### Corregido
- **Mover el inventario exige permiso.** Ajustar el stock (desde la ficha del producto y desde
  Stock y materiales) y recibir una orden de compra no pedían ninguno: cualquiera que pudiera
  ver la pantalla podía cambiar las existencias. Ahora piden «Stock y movimientos: editar» y
  «Órdenes de compra: recibir», y los botones solo se muestran a quien los tiene.
- **La referencia de un producto eliminado queda libre.** Antes, si eliminabas un producto y
  volvías a crear otro con la misma referencia, el sistema decía que ya estaba en uso, de algo
  que ya no veías. Ahora la reutilizas sin más. Vale al crear, al editar, en las variantes y al
  importar. Lo eliminado conserva su historial: solo cambia su referencia por «REF~elim123».
- **Si la referencia sí la tiene un producto vivo, el mensaje dice cuál.** Antes decía «El campo
  variantes.0.referencia ya está en uso».

## [1.2.0] — 2026-10-10

### Agregado
- **El correo sale por Briela, firmado y sin caer en spam.** Cuando Briela activa el
  dominio de envío de la instalación, las notificaciones, las cotizaciones y los formularios
  salen desde un subdominio propio verificado, sin configurar nada en el servidor. El SMTP
  propio queda de respaldo.
- **Configuración → Correo**: por dónde sale el correo, cuánto va del mes, un envío de prueba
  y las direcciones a las que ya no se les escribe porque rebotaron o se dieron de baja.
- Las notificaciones del sistema no se cobran nunca.

### Corregido
- **Crear un producto con variantes ya no responde con error.** Un valor de variante largo
  hacía que la referencia generada no cupiera y el guardado fallaba después de crear el
  padre, dejando un producto a medias. Ahora la referencia se acorta sin perder el prefijo
  del padre, y dos variantes nunca comparten referencia.
- **Las referencias automáticas ya no saltan ni chocan.** El contador ya no cuenta las
  variantes, así que los productos nuevos salen en orden (PROD-0001, PROD-0002…), y una
  referencia escrita a mano no bloquea la siguiente.
- **Las variantes tienen los precios de todos los canales**, incluidos los que la empresa
  creó por su cuenta. Antes, una variante podía cotizarse en cero por un canal propio.
- **Agregar una variante desde la ficha de editar ya no deja sus precios en cero.** La
  variante nueva copia los precios del padre.
- **Guardar un producto con proveedor sin todos sus datos ya no falla.** La importación y la
  API mandan solo lo que tienen.

## [1.1.0] — 2026-10-04

### Agregado
- **La TRM del día, sola.** Cada mañana se trae la TRM oficial y el euro, y se ven en
  Configuración → Monedas y TRM. Si el servidor no tiene internet, se puede escribir a mano.
- **Productos que se compran en dólares o euros**: se escribe el costo en esa moneda y el
  costo en pesos —y sus precios— se recalculan solos cada día con la tasa, más un colchón
  configurable. La plantilla de importación trae las columnas nuevas.
- **Cotizaciones en dólares o euros de verdad**: toman la tasa del día y el cliente ve los
  precios en su moneda en la pantalla, en el enlace de aprobación y en el PDF, con la tasa y
  su equivalente en pesos. Se elige si la tasa queda fija o sigue a la TRM hasta la aprobación.
- **Leer el RUT con IA**: se sube el PDF o una foto y se llenan solos los datos del cliente o
  los de la empresa, incluidas sus responsabilidades tributarias.
- **Retenciones estimadas en la cotización**: retención en la fuente, de IVA y de ICA según el
  RUT del cliente y el de la empresa, con el neto a recibir. Las reglas se configuran en
  Configuración → Perfil fiscal.

## [1.0.0] — 2026-09-23

Primera versión instalable de Briela.

### Agregado
- **Eliminar productos en bloque** desde la lista: los que marques, o todos los que
  deja el filtro de una vez. Cada producto se lleva sus variantes. Pide confirmación
  y exige el permiso «Eliminar productos».
- **La plantilla para importar productos trae una columna por cada canal de
  precio** configurado en Segmentación —margen, precio, comisiones y descuento—, y
  se actualiza sola cuando se crea un canal nuevo. También trae el resumen técnico
  para cotizaciones y la referencia y el precio del proveedor.
- **Plantillas PDF con encabezado y pie de página propios** en el modo código: se
  repiten en cada hoja, con la altura que se elija, y el pie puede decir «Página 2
  de 5». También hay salto de página.
- **Generar plantillas con IA, de dos maneras**: pidiéndoselo a Briela, o copiando
  un prompt ya preparado para ChatGPT, Claude o Gemini y pegando lo que devuelvan.
- **Botón «Validar»** en el editor de plantillas: dice qué variables no existen y
  qué bloques quedaron mal cerrados, probando contra el último documento real.
- Las plantillas admiten `{{else}}`, condicionales dentro de condicionales,
  comparaciones (`{{#if op.estado == "despachada"}}`), `{{#each items}}` y más
  formatos: número, porcentaje, fecha y hora.
- **Módulo de Calidad** (`/calidad`, con su propio permiso): un tablero con
  todas las unidades ya fabricadas y sin despachar, en fichas grandes con un
  botón por punto de revisión. Se marca de un toque, y «Terminar» cierra la
  unidad completa. Un punto que exige foto abre una ventana para tomarla con
  la cámara o subirla de un archivo, y no se deja marcar sin ella. El número
  de la orden abre la ficha de verificación de esa unidad: las medidas, los
  materiales de la receta y cómo se fabricó, paso por paso y con las fotos
  que dejó el operario.
- Integración con WordPress (plugin "Briela Connect"): los leads que llegan
  por los formularios del sitio web del cliente entran solos al CRM, con el
  canal de origen (utm_source / utm_medium / utm_campaign) de la visita que
  los trajo. Se conecta desde Configuración → Integraciones → WordPress.

### Corregido
- **Los productos importados desde CSV no tenían precio para cotizar**: la
  importación los guardaba donde la cotización ya no mira. Ahora quedan en su canal,
  y un archivo hecho con la plantilla anterior sigue sirviendo.
- Eliminar un producto ya no deja sus variantes sueltas, y quien no tiene el permiso
  de eliminar productos ya no puede hacerlo.
- **Una plantilla PDF hecha en modo visual salía en blanco** al descargar la
  cotización o la OP, aunque la vista previa se veía bien. Y una con papel de
  etiqueta o ticket fallaba. Ahora el documento sale igual que la vista previa.
- Las remisiones y los recibos de pago **usan su plantilla PDF** si la empresa
  hizo una; antes se diseñaba y nunca se aplicaba.
- En las plantillas, una condición dentro de la tabla de ítems (por ejemplo,
  mostrar la imagen solo si el ítem tiene) nunca se cumplía. Ya funciona.
- Una variable mal escrita ya no aparece como `{{texto}}` en el PDF del cliente.
- El logo de la empresa no salía en los PDF de plantilla. Ya sale.
- **En Trabajos, «Terminada» no respondía** en una unidad ya completa: se tocaba y
  no pasaba nada, que se ve igual que estar roto. Ahora acusa recibo diciendo a qué
  hora salió a Calidad.
- **El botón «Terminar» de Calidad no hacía nada** en las unidades cuyo ensamble no
  tiene lista de revisión cargada — que son casi todas. Esas unidades tampoco
  aparecían en el tablero, así que no había dónde aprobarlas, y sin aprobación no
  se podía remisionar nada. Ahora el tablero muestra todo lo fabricado que falta
  por revisar, con lista o sin ella, y «Terminar» la aprueba de verdad.
- En Trabajos, «Terminar» tampoco hacía nada en una unidad sin pasos de producción:
  ahora lo dice en vez de quedarse mudo. Y si la plantilla no marcó ningún paso
  como final, el último entrega igual.
- Guardar una orden de producción **recreaba sus ítems** y se llevaba por delante
  sus unidades, sus pasos y su revisión de calidad, en silencio. Ya no.
- Se eliminó el módulo suelto de «Plantillas de trabajo», que llevaba sin enlace
  desde que los pasos se cargan en la ficha del ensamble y editaba los mismos datos
  sin las validaciones de la pantalla nueva.

### Cambiado
- **El tablero de Trabajos muestra solo lo que falta por fabricar.** Una unidad
  terminada se va en cuanto pasa a Calidad, diciendo a dónde fue, y **vuelve sola
  si calidad la devuelve a reproceso**. Lo terminado sigue a un toque, en la
  tarjeta «Terminados». Igual en Calidad: lo aprobado sale de la bandeja.
- Las fichas muestran **de qué ítem de la orden son** (`OP-0005-02`). Dos ítems de
  la misma orden daban dos fichas que solo se distinguían leyendo las medidas.
- **Las fechas del proceso se ponen solas.** Cada unidad registra cuándo arrancó
  —la primera vez que se toca uno de sus pasos— y cuándo salió de producción, que
  es la misma hora a la que llegó a Calidad. Las dos se ven en el tablero, junto
  con la hora en que calidad la firmó. Nadie las escribe.
- **Mandar una orden a reproceso ahora hace algo.** Antes solo cambiaba la
  etiqueta: las unidades seguían figurando como terminadas y volver a producción
  dependía de que alguien se acordara. Ahora reabre en Trabajos las unidades que
  calidad rechazó —solo esas—, quedan marcadas «En reproceso», y la orden vuelve
  sola a Calidad cuando planta las rehace.
- **El último paso de producción ahora pregunta las dos bodegas**: a cuál entra el
  ensamble terminado y de cuál salieron los insumos que se gastaron en él. Llegan
  ya elegidas —las de la orden, o las de la unidad anterior— así que casi siempre
  es confirmar y seguir. Y ese paso ya no se puede cerrar si quedan otros pendientes.
- **Se puede remisionar lo que ya pasó calidad, sin esperar al resto de la orden.**
  Si el cliente quiere llevarse tres de las diez puertas y esas tres están
  revisadas, se despachan hoy.
- **Cambiar la cantidad de un ítem crea o elimina las unidades correspondientes**, y
  lo avisa antes de guardar. Nunca elimina una unidad que ya tenga trabajo hecho.
- Los puntos del colaborador se otorgan al cerrar un paso **desde cualquier
  pantalla**, no solo desde el código QR.
- **Trabajos se ve como Calidad**: el listado dejó de ser una tabla y ahora es
  la misma ficha grande, con un botón por paso. Marcar un paso pasó de ocho
  toques a uno.
- **El menú se pliega** y deja solo la columna de iconos, para ganar ancho en
  las pantallas anchas; la categoría se sigue desplegando al pasar por encima.
  Además está reorganizado —RRHH y Capacitación ahora son «Personal», y
  Auditoría se fue con Informes a «Reportes»— y cada categoría tiene su propio
  icono en vez del engranaje repetido.
- Arranque del proyecto a partir de un ERP interno ya probado (2 ago 2026):
  identidad propia, configuración limpia y salida de las credenciales heredadas.
