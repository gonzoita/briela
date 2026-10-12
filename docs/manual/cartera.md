# Cartera — lo que deben los clientes

Ruta: `/financiero/cartera` · Permiso: `cartera.ver`

Lo que está pendiente de cobro, por cliente y por orden de producción.

## De dónde sale el dinero de una OP

Una orden puede llevar **anticipo**, **cuotas** con su fecha de vencimiento, y **pagos** que se
van registrando contra esas cuotas. El saldo es lo que falta.

Las cuotas y los pagos se manejan desde la ficha de la OP, en su bloque financiero; la cartera es
la vista de conjunto: todo lo pendiente en un solo lugar, para saber a quién hay que cobrarle.

## Confirmar los pagos con el banco

Un pago anotado a mano es lo que alguien dijo que entró. El **verificador de pagos**
([verificador-pagos.md](./verificador-pagos.md)) lo confirma con el extracto del banco y lo marca
«Verificado por el banco».

## Verificar un pago a mano *(15 oct 2026)*

Un pago anotado no está verificado hasta que alguien confirma que el dinero entró: el verificador
de pagos con el extracto del banco, o una persona de cartera con «Marcar verificado» (efectivo,
cheque). Marcar exige `cartera.editar`; deshacer, `cartera.eliminar`, y solo la verificación hecha
a mano (la del banco se deshace en el verificador). Con la orden pagada y todo verificado, sus
remisiones entregadas se **cierran** solas (ver [Logística](./logistica.md)).

## Permisos

| Permiso | Para qué |
|---|---|
| `cartera.ver` | Ver la cartera y el bloque financiero de una OP |
| `cartera.editar` | Registrar cuotas y pagos |
| `cartera.eliminar` | Borrar un pago mal registrado |

Borrar un pago es su propio permiso a propósito: es la operación que puede tapar un descuadre.

## Lo que este módulo NO hace todavía

No emite factura electrónica ni se conecta con la DIAN. Registra lo que se cobró y lo que falta;
la factura sale por fuera.
