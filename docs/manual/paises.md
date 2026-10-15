# Países: impuestos, documentos de identidad y moneda de cada sede

Briela es de una sola empresa por instalación, pero esa empresa puede tener **sedes en varios
países** y venderle a clientes de otros. Lo que cambia de un país a otro es un **dato**, no
código.

## Qué cambia por país

| Qué | Dónde se decide |
|---|---|
| País de la sede, y la moneda de sus documentos | Configuración → Sedes |
| Impuestos y tarifas que se ofrecen en una cotización (IVA, ITBMS…) | Configuración → Países |
| Documentos de identidad que acepta (NIT, RFC, RUC, RIF, EIN…) y su formato | El paquete del país |
| País y identificación fiscal de la empresa | Configuración → Perfil fiscal (cambia el país de la sede principal) |
| Si se estiman retenciones | El paquete del país (hoy solo Colombia) |
| Si el impuesto de importación se recupera | Configuración → Países |
| País de un cliente o de un proveedor | Su ficha. Sin país = el país de la casa |

**La casa** es el país de la sede principal. Un cliente o proveedor sin país se entiende de la
casa, y por eso todo lo que ya estaba guardado sigue siendo Colombia sin tocar una fila.

## Los paquetes de país

Un paquete es un archivo de datos, `resources/paises/{ISO}.json`: documento fiscal, autoridad,
identificaciones (con su patrón), impuestos y tarifas, y notas. **Vienen Colombia, Panamá,
Venezuela, México, Ecuador, Perú, Chile y Estados Unidos.** Un país sin paquete funciona igual,
con identificaciones genéricas y los impuestos que la empresa escriba en Configuración → Países.

- **Solo el de Colombia lo revisó un contador.** Los de Panamá, Venezuela, México, Ecuador, Perú,
  Chile y Estados Unidos son valores de partida (`"revisado": false`). La pantalla lo avisa.
  Confírmalos antes de facturar.
- **Lo que se ajusta en Configuración → Países se guarda aparte del paquete** (`paises_ajustes`),
  así que una actualización de Briela no lo pisa. «Volver a lo del paquete» lo deshace.
- Un paquete nuevo se agrega con un archivo; no se toca código. `tests/Feature/PaisesTest.php`
  comprueba que todos los paquetes sean válidos.

## La identificación del exterior *(15 oct 2026)*

Todo país, con paquete o sin él, acepta al final de su lista la **identificación tributaria del
exterior** (`EXT`): formato libre y sin dígito de verificación. Es para el cliente o proveedor de
afuera cuyo Tax ID, VAT o número fiscal el paquete no conoce, o trae un formato que el patrón no
previó. El paquete propone primero lo suyo; esta es la salida, para que nunca se rechace una
identificación real.

## El dígito de verificación

Lo calcula `Paises::calcularDv()` según el paquete: `nit_co` es el NIT de Colombia (DIAN) y
`rut_cl` el RUT de Chile (SII, módulo 11, donde 10 es «K»). Las demás identificaciones se revisan
solo por su formato.

## La empresa de otro país

En **Configuración → Perfil fiscal** se elige el país de la empresa —es el de la sede principal,
y cambiarlo allí lo cambia acá— y su identificación fiscal con los tipos de ese país. El número
sigue en `empresa_nit` (la clave que ya leen los PDF) y el tipo en `empresa_tipo_identificacion`;
los PDF dicen «RFC:», «RUC:» o «EIN:» en vez de «NIT:» (`{{empresa.tipo_identificacion}}`). Las
responsabilidades y las reglas de retención solo se muestran y se guardan si el país las tiene
en su paquete; las de Colombia no se pisan al cambiar de país.

## Qué hace Briela y qué no

- **Hace:** ofrecer las tarifas del país en la cotización, validar el formato de la identificación
  (como aviso, nunca como bloqueo), leer con IA el documento fiscal de cada país, y avisar los
  tributos que conviene conocer (el IGTF de Venezuela, por ejemplo).
- **No hace todavía:** la factura electrónica legal. Cada país exige un proveedor autorizado
  (DIAN, DGI, SENIAT) y Briela prepara los datos pero no la emite. Tampoco calcula retenciones
  fuera de Colombia, ni el IGTF.
- **El RUES es de Colombia.** Un RIF, un RUC o un RFC se valida por su formato; el dígito de
  verificación solo se calcula donde el paquete dice cómo (Colombia y Chile).

## Si Panamá o Venezuela son empresas distintas

Una instalación es una empresa. Si la sede de Panamá es una **persona jurídica aparte**, con su
contabilidad y sus impuestos propios, lo correcto es una instalación para ella: es lo que
garantiza que sus datos y sus informes no se mezclen con los de la otra empresa.

## Cómo se lee un documento fiscal de otro país

El botón «Leer identificación fiscal con IA» del cliente, del proveedor y del perfil fiscal usa
el prompt del país elegido. El resultado se revisa antes de guardar. En un país sin paquete no hay
botón. En las pantallas ya no se dice «RUT» sino **identificación fiscal** o **documento
fiscal**: el RUT es el de Colombia, y la empresa o el tercero pueden ser de cualquier país.

Ver también [Monedas, TRM y retenciones](./monedas-y-retenciones.md) e
[Importaciones](./importaciones.md).
