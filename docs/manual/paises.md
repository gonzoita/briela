# Países: impuestos, documentos de identidad y moneda de cada sede

Briela es de una sola empresa por instalación, pero esa empresa puede tener **sedes en varios
países** y venderle a clientes de otros. Lo que cambia de un país a otro es un **dato**, no
código.

## Qué cambia por país

| Qué | Dónde se decide |
|---|---|
| País de la sede, y la moneda de sus documentos | Configuración → Sedes |
| Impuestos y tarifas que se ofrecen en una cotización (IVA, ITBMS…) | Configuración → Países |
| Documentos de identidad que acepta (NIT, RUC, RIF…) y su formato | El paquete del país |
| Si se estiman retenciones | El paquete del país (hoy solo Colombia) |
| Si el impuesto de importación se recupera | Configuración → Países |
| País de un cliente o de un proveedor | Su ficha. Sin país = el país de la casa |

**La casa** es el país de la sede principal. Un cliente o proveedor sin país se entiende de la
casa, y por eso todo lo que ya estaba guardado sigue siendo Colombia sin tocar una fila.

## Los paquetes de país

Un paquete es un archivo de datos, `resources/paises/{ISO}.json`: documento fiscal, autoridad,
identificaciones (con su patrón), impuestos y tarifas, y notas. **Viene Colombia, Panamá y
Venezuela.** Un país sin paquete funciona igual, con identificaciones genéricas y los impuestos
que la empresa escriba en Configuración → Países.

- Los de **Panamá y Venezuela son valores de partida, sin revisión de un contador local**. La
  pantalla lo avisa. Confírmalos antes de facturar.
- **Lo que se ajusta en Configuración → Países se guarda aparte del paquete** (`paises_ajustes`),
  así que una actualización de Briela no lo pisa. «Volver a lo del paquete» lo deshace.
- Un paquete nuevo se agrega con un archivo; no se toca código. `tests/Feature/PaisesTest.php`
  comprueba que todos los paquetes sean válidos.

## Qué hace Briela y qué no

- **Hace:** ofrecer las tarifas del país en la cotización, validar el formato de la identificación
  (como aviso, nunca como bloqueo), leer con IA el documento fiscal de cada país, y avisar los
  tributos que conviene conocer (el IGTF de Venezuela, por ejemplo).
- **No hace todavía:** la factura electrónica legal. Cada país exige un proveedor autorizado
  (DIAN, DGI, SENIAT) y Briela prepara los datos pero no la emite. Tampoco calcula retenciones
  fuera de Colombia, ni el IGTF.
- **El dígito de verificación y el RUES son de Colombia.** Un RIF o un RUC se valida por su
  formato; no se inventa un dígito que no se conoce.

## Si Panamá o Venezuela son empresas distintas

Una instalación es una empresa. Si la sede de Panamá es una **persona jurídica aparte**, con su
contabilidad y sus impuestos propios, lo correcto es una instalación para ella: es lo que
garantiza que sus datos y sus informes no se mezclen con los de la otra empresa.

## Cómo se lee un documento fiscal de otro país

El botón «Leer documento con IA» del cliente usa el prompt del país elegido. El resultado se
revisa antes de guardar, igual que el RUT. En un país sin paquete no hay botón.

Ver también [Monedas, TRM y retenciones](./monedas-y-retenciones.md) e
[Importaciones](./importaciones.md).
