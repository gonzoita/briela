# Roles y permisos configurables

## Lo primero: el rol define QUÉ, el usuario define DÓNDE

Es la duda más común al entrar por primera vez. En **Configuración → Roles y
permisos** no hay dónde elegir la sede, y no es un olvido.

| Dónde | Qué se configura |
|---|---|
| **Roles y permisos** | Qué puede hacer: ver clientes, crear cotizaciones, aprobar OPs |
| **Usuarios** → ficha de cada persona | En qué **sedes** y **almacenes** lo puede hacer |

Si la sede estuviera en el rol, habría que crear "Comercial Bogotá",
"Comercial Cali" y "Comercial Cúcuta" — tres roles idénticos que hay que
mantener sincronizados a mano. Y al abrir una sede nueva tocaría duplicarlos
todos otra vez.

Con la separación, un solo rol **Comercial** sirve para las tres: a cada
vendedor le marcas sus sedes en su ficha de usuario.

### La excepción: "Acceso a todas las sedes"

Ese interruptor sí vive en el rol. Marcado, quien lo tenga ve las tres sedes y
puede cambiar entre ellas con el selector del encabezado, sin importar qué se
le haya marcado en Usuarios. Es para gerencia y administración.

Sin marcar, la persona ve solo las sedes de su ficha; si no le marcaste
ninguna, queda con su sede principal para que no se quede sin acceso a nada.
 *(nuevo, 26 jul 2026)*

Segunda fase del multi-sede. Permite crear roles propios y definir con detalle
qué puede hacer cada uno, y en qué sedes y almacenes.

Acceso: **Configuración → Organización → Roles y permisos** (solo
administrador).

## Cómo funciona

Un rol tiene tres partes:

1. **Rol base** — uno de los cuatro roles históricos (administrador, jefe de
   producción, vendedor, operario). Define el comportamiento de fondo del
   usuario en el sistema.
2. **Permisos** — casillas por módulo y acción (ver, crear, editar, eliminar,
   y acciones especiales como aprobar, despachar o control de calidad).
3. **Alcance** — a qué sedes y almacenes accede.

### ¿Por qué existe el "rol base"?

Los cuatro roles originales están escritos dentro del código en decenas de
sitios (rutas y controladores). Reemplazarlos de un solo golpe era la forma más
fácil de dejar a alguien sin acceso sin darse cuenta. El rol base mantiene todo
eso funcionando, mientras los permisos finos controlan lo que el usuario ve y
puede hacer. En los roles originales el rol base no se puede cambiar.

**Ejemplo — rol "Comercial":** rol base *vendedor*; permisos completos en
Clientes y Cotizaciones; en Órdenes de Producción y Trabajos solo *Ver*.

## Qué cambió el día de la migración

- Se crearon automáticamente los 4 roles originales como **roles de sistema**,
  con los permisos equivalentes a lo que cada uno podía hacer antes.
- Cada usuario quedó enlazado al rol que le correspondía. **Nadie perdió
  acceso.**
- Cada usuario quedó con acceso a su propia sede.

Los roles de sistema se pueden editar (ajustar sus permisos) pero no eliminar.

## Sedes y almacenes por usuario

Al crear o editar un usuario se define:

- **Sede principal** — a la que pertenece.
- **Sedes a las que accede** — puede ser más de una. Si no se marca ninguna,
  queda solo con la principal.
- **Almacenes permitidos** — para casos como "ve la sede Cúcuta pero solo el
  Almacén 1". Si no se marca ninguno, accede a todos los de sus sedes.

Si el rol tiene marcado **"Acceso a todas las sedes"**, no hace falta elegirlas
una por una.

## El menú se adapta solo

El menú lateral ya no se dibuja según una lista fija por rol, sino según los
permisos reales del usuario: si un rol no tiene ningún permiso de Compras, esa
sección no aparece. Los grupos vacíos se ocultan por completo.

## Las rutas también están protegidas

Todas las rutas del sistema (~40 grupos) se protegen ya por permiso y no por
rol. Escribir la dirección a mano no sirve para saltarse el menú: si el rol no
tiene el permiso, la respuesta es "No tienes permiso para acceder a esta
sección".

Dos ajustes que se hicieron al cerrar las rutas, para que nadie perdiera
acceso respecto a como estaba antes:

- El **vendedor** conserva crear y editar solicitudes de compra.
- El **operario** conserva ver Clientes y Órdenes de Producción en solo
  lectura.

### Nivel de detalle actual

La protección es **por módulo**, no por cada acción. Por ejemplo, un rol con
`usuarios.ver` entra al módulo de Usuarios completo; el permiso de `crear` o
`eliminar` todavía no se valida ruta por ruta dentro del módulo. Las acciones
sensibles sí están separadas: control de calidad, aprobar compras, liquidar
comisiones, despachar, eliminar pagos y eliminar archivos.

## Catálogo de permisos

Los módulos disponibles están agrupados en: Ventas, Producción, Inventario y
Compras, Logística y Financiero, Personas, Otros módulos y Administración. El
catálogo vive en `app/Support/Permisos.php` — si se agrega un módulo nuevo al
sistema, se agrega ahí y aparece solo en la pantalla de configuración.
