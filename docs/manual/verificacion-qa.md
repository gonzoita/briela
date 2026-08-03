# Checklist de verificación — cambios de julio 2026

Guía para probar en producción, en orden, todo lo que se construyó/corrigió
en esta ronda de revisión. Lo más nuevo y crítico va primero.

## 1. Control de calidad + bloqueo de remisión (lo más nuevo, sin probar aún)

1. Entra a una OP real y muévela hasta el estado **"Calidad"** (Borrador →
   Confirmada → En producción → Calidad, con el botón "Estado").
2. En el detalle de esa OP debe aparecer el bloque **"Control de calidad"**.
3. Toca el cuadro con "+" para subir una foto — en el celular debe
   preguntarte si quieres tomar la foto con la cámara o elegirla de la
   galería (no debe abrir la cámara directo). Prueba subir más de una foto
   de una vez.
4. Escribe algo en "Observaciones" y presiona **"Rechazar (reproceso)"** —
   debe pedirte el motivo antes de dejarte confirmar. Confirma el rechazo:
   la OP debe pasar a estado **"Reproceso"**.
5. Vuelve a moverla manualmente a "Calidad" y esta vez presiona
   **"Aprobar calidad"**. Debe aparecer el aviso verde "Calidad aprobada —
   ya se puede generar la remisión y despachar", y la OP debe **seguir**
   en estado "Calidad" (a propósito — el despacho real lo hace la remisión).
6. **Antes** de aprobar calidad (repite con otra OP si ya aprobaste la
   anterior), intenta generar una remisión desde el botón "Remisión" — debe
   estar deshabilitado o mostrar el error "falta la aprobación de control
   de calidad". Después de aprobar, el botón debe activarse.
7. Genera la remisión completa de esa OP (todos los ítems). Al terminar, la
   OP debe pasar sola a **"Despachada"**, y en Productos → el stock de los
   insumos usados debe verse descontado (revisa el historial de
   movimientos de algún insumo de esa receta).
8. Intenta cambiar el estado de una OP directo a "Despachada" desde el
   botón "Estado" sin haber aprobado calidad — la opción debe aparecer
   deshabilitada en el desplegable.

## 2. Dashboard

1. Entra a `/dashboard` y compara los números de las tarjetas ("En
   producción", "Por confirmar", "Ctrl. calidad", "Despachadas/mes") contra
   lo que ves en `/produccion/ops` filtrando por cada estado — deben
   coincidir exactamente.
2. La lista "OPs recientes" debe mostrar las últimas 5 OPs reales, con
   número, cliente y estado correctos, y el enlace de cada una debe llevar
   a la OP real (no a un 404).

## 3. Cotización → OP (anticipo y comisión)

1. Aprueba una cotización con "Generar OP" poniendo un valor de anticipo,
   medio de pago y fecha.
2. En la OP resultante, revisa la pestaña financiera: debe existir una
   cuota "Anticipo" ya marcada como pagada, sin que el sistema te la vuelva
   a pedir al confirmar la OP.
3. Si esa cotización tenía comisión de vendedor, revisa en Comisiones que
   haya pasado de "proyectada" a "confirmada".

## 4. Auditoría

1. Entra a `/auditoria` y confirma que las acciones que hiciste en los
   puntos anteriores (cambios de estado de OP, aprobar calidad, etc.)
   quedaron registradas con tu usuario y la hora correcta.

## 5. CRM → Cotización automática

Ya lo probaste y funcionó (lead "De la Feria"). Solo como referencia si
quieres repetirlo: mover un lead a la etapa "Cliente Nuevo" (con cliente ya
asociado) debe generar la cotización en borrador sola.

## 6. Editor de texto (plantillas de ensamble)

Ya confirmado que funciona (negrita, cursiva, viñetas, lista numerada).

## 7. Importación CSV de productos

1. Ve a Productos → Importar CSV → descarga la plantilla.
2. Llena un par de filas (una con referencia nueva, una con referencia de
   un producto que ya exista) y súbela.
3. Confirma que el producto existente se actualizó (no se duplicó) y que
   el nuevo se creó con categoría/proveedor autogenerados si no existían.

## 8. Producto padre + costo inline

1. En Productos, confirma que el botón "Editar" del producto padre (ej.
   "Puerta batiente") te lleva a su edición — el clic normal en la fila
   sigue expandiendo variantes.
2. Edita el precio de costo de una variante directo desde la lista, sin
   entrar a la página de edición completa.
