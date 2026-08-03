# Identificación de clientes — NIT, dígito de verificación y RUES

Al crear o editar un cliente, cuando sales del campo **Número** (o presionas
Enter) el sistema revisa tres cosas de una sola pasada.

## 1. Dígito de verificación del NIT

El DV se calcula solo. Es matemática pura sobre el número, así que no depende
de internet ni de ningún servicio externo: **siempre funciona**.

- Si escribes el NIT sin DV, el sistema lo calcula y lo pone en la casilla de
  al lado.
- Si escribes el NIT con DV (`900123456-7`) y el DV está mal, sale un aviso en
  amarillo con el correcto y un botón **Corregir**.

Esto atrapa la mayoría de los NIT mal digitados en el momento, que es cuando
cuesta barato arreglarlos. Un NIT malo en un cliente se propaga después a
cotizaciones, remisiones y facturas.

El número base y el DV se guardan en campos separados. En pantalla se muestran
juntos como `900123456-7`.

## 2. ¿Este cliente ya existe?

Busca el número entre los clientes ya registrados. Si lo encuentra, muestra un
aviso rojo con el nombre, la sede y un enlace para abrir el cliente existente.

**Busca en todas las sedes a propósito.** Si el cliente ya está creado en Cali
y lo vas a crear en Bogotá, ese es justamente el duplicado que hay que evitar.
El aviso no bloquea nada: si de verdad necesitas crearlo, puedes seguir.

La comparación ignora puntos, guiones y espacios, así que `900.123.456` y
`900123456` se reconocen como el mismo.

## 3. Datos desde el registro mercantil

Si el tipo de identificación es **NIT** o **RUT**, el sistema consulta el
registro mercantil y muestra:

- Razón social y sigla
- Tipo de organización jurídica (SAS, S.A., etc.)
- Cámara de comercio
- Representante legal
- **Estado de la matrícula** y último año renovado

Al oprimir **Usar la razón social** se llena el nombre; el resto queda guardado
como referencia. Nada se aplica solo.

El estado de la matrícula es el dato más útil de todos para el negocio: si sale
distinto de *ACTIVA*, se resalta en amarillo. Una matrícula cancelada o sin
renovar es una señal a mirar antes de despachar a crédito.

### De dónde salen los datos

De **datos.gov.co**, conjunto `c82u-588k`, que publica **Confecámaras** con el
Registro Mercantil de todas las cámaras del país.

No se usa la API del portal del RUES porque esa exige un token que Confecámaras
no entrega públicamente. Datos abiertos, en cambio, es una API documentada
(Socrata/SODA), **gratuita, oficial y sin credenciales**.

Se puede configurar un token opcional, que solo sube el límite de consultas por
hora. Sin token funciona igual.

### Lo que no trae

- **Correo, teléfono y dirección.** No se publican. Eso siempre se escribe a
  mano — Siigo tampoco los saca de aquí.
- **La ciudad.** Publica la *cámara de comercio*, cuya jurisdicción cubre
  varios municipios. Por eso la ciudad no se llena sola: adivinarla sería peor
  que dejarla en blanco.
- **Cédulas de personas naturales.** Están protegidas por la Ley 1581 de
  Habeas Data y no hay fuente pública. Con tipo CC, CE o PA el sistema solo
  valida duplicados — ni siquiera intenta consultar.

### Al día, pero no al segundo

Los datos se actualizan **una vez al mes**. Una empresa matriculada hace pocos
días puede no aparecer todavía; eso no es una falla.

### Sigue siendo una conveniencia, no una dependencia

- Timeout de 6 segundos: nunca deja el formulario colgado.
- Los resultados se guardan en caché 30 días.
- Si no responde, sale un mensaje gris y **sigues escribiendo a mano**.

El DV y la detección de duplicados son código nuestro y no se ven afectados si
el servicio se cae.

## Dónde se configura

**Configuración → Identificación de clientes.**

Ahí se puede:

- **Prender o apagar** la consulta con un interruptor. Apagarla no afecta el DV
  ni el aviso de duplicados: esos siguen igual.
- **Cambiar la dirección del servicio**, por si cambia algún día. El botón
  *Restaurar* devuelve la que viene de fábrica.
- **Poner un token** de datos.gov.co (opcional, solo sube el límite de
  consultas por hora).
- **Ajustar el tiempo de espera** (entre 2 y 30 segundos).
- **Probar**: consulta un NIT real, sin usar caché, y muestra si respondió,
  qué devolvió y en cuántos milisegundos. Viene con el NIT de Interfrigo
  precargado — si falla con ese, el problema es del servicio y no del número.

El dígito de verificación y la detección de duplicados no aparecen como
opciones configurables porque no dependen de nada externo y no tienen forma
de fallar.

Al guardar un cambio de dirección se invalida la caché, para que la siguiente
consulta salga por el camino nuevo y no devuelva algo viejo.

### También se puede desde el .env

Lo de Configuración manda sobre el `.env`. Si en la app no hay nada guardado,
se usan estos valores:

```
RUES_ACTIVO=true
RUES_URL=https://www.datos.gov.co/resource/c82u-588k.json
RUES_APP_TOKEN=
RUES_TIMEOUT=6
```

Cuando el RUES no responde queda una línea en `storage/logs/laravel.log` con
nivel *info* (no *error*): que no conteste no es una falla del sistema.
