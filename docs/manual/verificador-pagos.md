# Verificador de pagos — confirmar con el banco que el dinero llegó

Ruta: `/financiero/verificador-pagos` · Permisos: `verificador.ver` y `verificador.editar` ·
Módulo: `verificador_pagos` (depende de Cartera).

**Para qué sirve.** En una venta de montos altos alguien tiene que abrir el banco a comprobar que
cada pago entró. El verificador hace esa revisión: recibe los abonos de la cuenta de la empresa,
los empareja con las cuotas que se esperan cobrar y deja el pago anotado y marcado
**«Verificado por el banco»**.

**Solo lee.** No mueve plata, no paga, no toca el banco. Y solo mira lo que **entra**: las
salidas de dinero se descartan al subir el extracto.

## Cómo entra el dinero a Briela

Todas las fuentes terminan en un solo punto, `VerificadorPagosService::importar()`, con los mismos
campos: fecha, valor, descripción, referencia, quién pagó y el identificador del banco. Lo que
cambia entre fuentes es de dónde salen las filas; el emparejamiento se escribe una sola vez.

| Fuente | Estado | Cobertura |
|---|---|---|
| **Extracto subido** (`.xlsx` o `.csv`) | Hecha | Cualquier banco |
| **Banca abierta** (Belvo) | Pendiente: ver abajo | Varios bancos, casi en vivo |
| **Finanzas abiertas reguladas** (Decreto 0368 de 2026) | A futuro | Todo banco vigilado |

### El extracto

- Se sube desde la pantalla: cuenta + archivo. Acepta `.xlsx` y `.csv` (UTF-8 o ISO-8859-1; coma,
  punto y coma, tabulador o barra). **No** lee `.xls` antiguo ni PDF, y lo dice: un PDF no se lee con
  seguridad.
- Reconoce solo las columnas —fecha, valor o crédito/débito, descripción, referencia— por sus
  encabezados, sin importar el banco. Si no las reconoce **no adivina**: muestra las primeras filas
  y deja que la persona indique cuál es la fecha y cuál el valor.
- Entiende el dinero como lo escribe cada banco (`1.234.567,89`, `$ 1,234,567.89`, `250.000`
  —doscientos cincuenta mil—, `(1.500)`) y las fechas con el día primero (`04/05/2026` es el 4 de
  mayo), también los números de fecha de Excel. Una fecha inexistente (`31/02`) se descarta.
- **Subir dos veces el mismo extracto no duplica nada**, y uno que se traslapa con el anterior solo
  suma lo nuevo. Dos pagos idénticos el mismo día son dos pagos, no uno repetido.
- Solo se guardan los **últimos dígitos** de la cuenta. El número completo no hace falta.

## El emparejamiento

Cada abono sale en uno de cuatro estados:

| Estado | Qué significa |
|---|---|
| **Verificado** (conciliado) | Hubo una sola explicación posible: se registró el pago y quedó confirmado por el banco |
| **Por decidir** (dudoso) | Hay candidatas y una persona decide con un clic |
| **Sin dueño** (pendiente) | Ninguna cuota lo reclama todavía: dinero sin explicar, visible en la bandeja |
| **Ignorado** | No es de la empresa (un traslado propio, un error) |

**Se concilia solo únicamente lo que no deja duda.** En montos altos, equivocarse en silencio es
peor que preguntar:

1. Si el abono **nombra la orden** (`OP-2026-001`, `OP 2026 001` o `OP2026001`) y hay una cuota de
   esa orden por ese valor exacto → es esa. Con cuotas iguales, la primera: se pagan en orden.
   `OP-2026-001` nunca se confunde con `OP-2026-0012`.
2. Si no nombra ninguna orden, se busca por **valor exacto**. Se concilia solo si hay una única
   cuota de ese valor cuyo **cliente aparece en la descripción** (se ignoran palabras como SAS,
   LTDA o INVERSIONES). Si el valor coincide pero nadie lo reclama, o lo reclaman dos clientes, se
   pregunta.
3. Un abono que nombra la orden con otro valor (un pago parcial) se pregunta, con las cuotas de esa
   orden como candidatas.

**Por eso conviene que el cliente ponga la referencia de la orden al transferir.** Sin ella, el
emparejamiento depende solo del valor y del nombre.

El pago que se registra es **el mismo que se anota a mano** en la orden —recibo, saldo de la cuota,
saldo de la orden—, con una diferencia: lleva la fecha en que el banco lo confirmó y se ve el
«✓ Verificado por el banco» en el historial de pagos de la orden.

## Deshacer y borrar

- **Deshacer** una conciliación borra el pago y la cuota vuelve a deber su valor. El abono queda
  pendiente y **no se vuelve a emparejar solo** (si se deshizo es porque estaba mal). Pide
  `cartera.eliminar`, igual que borrar un pago, porque es la misma operación.
- Si alguien **borra el pago a mano** desde la cartera, el abono vuelve a la bandeja: el dinero
  sigue en la cuenta aunque el pago ya no esté anotado.

## Lo que falta: la conexión con el banco

La **banca abierta** da los abonos casi en vivo, sin subir nada. Está decidido hacerla con
**Belvo**, contratado por Briela (no por cada cliente) y a través del superadmin, igual que la
IA: la llave del proveedor vive en el superadmin y nunca en la instalación; la instalación pide
los movimientos con su serial y recoge los avisos en su siguiente consulta.

Falta, y no depende del código:

- Que Belvo confirme **cobertura de cuentas empresariales** y la lista de bancos.
- Si **avisa** de cada movimiento nuevo o solo permite consultarlo, y el costo por conexión.
- El contrato y las credenciales.
- Tratar con un abogado los datos bancarios que pasarían por el superadmin (Ley 1581): guardarlos
  solo el tiempo necesario.

Cuando eso esté, la conexión es **otra fuente** que llama a `importar()`; nada del emparejamiento
cambia.

### Preguntas para Belvo (o para cualquier agregador)

Antes de firmar nada, las mismas a cada proveedor, para poder comparar:

1. ¿Funciona con **cuentas empresariales** de los bancos que usan los clientes? ¿Cuáles exactamente?
2. ¿**Avisa** cuando entra un movimiento nuevo (webhook) o solo se puede consultar? ¿Cada cuánto
   se actualizan los movimientos?
3. ¿Cada movimiento trae un **identificador único**, la **referencia** de la transferencia y el
   **nombre de quien pagó**? Sin eso, el emparejamiento depende solo del valor.
4. ¿Cuánto cuesta por conexión y por consulta? ¿Se puede contratar **una vez y conectar muchas
   empresas**, que es lo que haría Briela?
5. ¿Cómo autoriza la empresa la conexión a su cuenta, y cada cuánto hay que renovarla?
6. ¿Dónde se guardan los datos y por cuánto tiempo? ¿Cumplen la Ley 1581?
7. ¿Hay un ambiente de pruebas con movimientos de ejemplo?

## Avisos

- **Un extracto que deja abonos por decidir o sin dueño** avisa a los administradores
  (`abonos_por_revisar`), menos a quien lo subió.
- **Cuando el banco confirma un pago**, el responsable de la orden se entera (`pago_verificado`),
  si no fue él quien lo asignó.
- Los dos se pueden apagar desde Configuración → Notificaciones, y desaparecen con el módulo.

