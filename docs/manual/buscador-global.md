# Buscador global y encadenamiento

## El buscador

Está en el encabezado, siempre visible. En computador es una barra; en celular,
una lupa que abre pantalla completa. También se abre con **Ctrl+K** (o Cmd+K)
desde cualquier pantalla.

Busca **mientras escribes**, sin oprimir nada. Con las flechas ↑↓ te mueves
entre resultados, con Enter abres el que esté resaltado y con Esc lo cierras.

### Qué encuentra

| Tipo | Buscando por |
|---|---|
| Clientes | Nombre, NIT o cédula, correo, teléfono |
| Órdenes de producción | Número de OP, o nombre/NIT del cliente |
| Cotizaciones | Número, o nombre/NIT del cliente |
| Remisiones | Número, transportista, placa, cliente |
| **Números de serie** | La serie completa o parte (`IF-2026-045-P-001`) → lleva a su OP |
| Productos | Nombre, referencia, descripción |
| Proveedores | Nombre, NIT, contacto |
| Órdenes de compra | Número, proveedor |
| Solicitudes de compra | Número, justificación |
| Leads del CRM | Título, contacto, empresa, correo |
| Usuarios | Nombre, correo |

### Dos reglas que lo hacen confiable

**Solo aparece lo que puedes ver.** El buscador respeta los permisos de tu rol.
Un vendedor sin acceso a compras no ve órdenes de compra ahí, igual que no las
ve en el menú. No es una puerta trasera.

**Solo aparece lo de la sede activa.** Si el encabezado dice Bogotá, no salen
OPs de Cali. Coincide con lo que ves en los listados.

Eso también explica un resultado que puede sorprender: si buscas un cliente y
no aparece, revisa la sede seleccionada antes de concluir que no existe.

### Cuando no encuentra nada

Sale el botón **Preguntarle al asistente**, que le pasa lo que escribiste a Ofe
y abre el chat.

La división es a propósito: la búsqueda literal es instantánea y sirve para
encontrar *un registro concreto*. La IA tarda varios segundos pero entiende
preguntas ("¿qué cotizaciones de Cali están sin respuesta?"). Cada una hace lo
que la otra no puede.

### Los buscadores de cada módulo siguen ahí

Los selectores dentro de formularios —elegir producto en una cotización,
elegir OP en una remisión— siguen funcionando igual. El buscador global no los
reemplaza: ellos filtran para un campo, este navega por todo el sistema.

## Encadenamiento desde el cliente

Al entrar a un cliente, debajo de sus datos ahora aparece todo lo que tiene en
el sistema:

- **Cotizaciones** con estado y total
- **Órdenes de producción** con estado, avance y total
- **Remisiones** con estado y fecha
- **Oportunidades del CRM**

Se muestran las 10 más recientes de cada tipo, con enlace a "Ver todas" para
el listado completo. La ficha es para tener el panorama; el detalle sigue en
cada módulo.

Cada bloque respeta permisos: quien no puede ver logística no ve el bloque de
remisiones.

### Nota técnica

Estas relaciones **no existían** en el modelo. Las llaves foráneas siempre
estuvieron en las tablas hijas (`cotizaciones.cliente_id`, `ops.cliente_id`,
etc.), pero desde el cliente no había forma de llegar a ellas: para saber qué
se le había vendido a alguien tocaba ir módulo por módulo filtrando a mano.

---

## El buscador de cada módulo

Desde el 21 de agosto de 2026 el campo de búsqueda de cada listado **sugiere mientras
escribes**, en vez de esperar a que termines y presiones Enter. Al escribir dos letras aparece
una lista debajo del campo, y desde ahí se salta directo al registro.

Está en Productos, Clientes, Cotizaciones, Órdenes de producción, Proveedores y el pipeline
del CRM. Cada uno busca **solo lo suyo**: en Productos no salen clientes.

### Enter hace dos cosas, y esa es la idea

- **Con una sugerencia resaltada** —flechas ↑ ↓, o un clic— abre ese registro directo. Es el
  caso frecuente: ya sabes cuál quieres.
- **Sin nada resaltado**, Enter filtra el listado con lo que escribiste, como funcionó siempre.
  Es el caso de «muéstrame todos los que digan bisagra».

Ninguna de las dos rutas se perdió, y ninguna estorba a la otra.

### Es el mismo motor del buscador global

No es un buscador nuevo por módulo: es el buscador global de Ctrl+K apuntado a un solo tipo.
Eso no se hizo por ahorrar código, sino porque **es lo que garantiza que la sugerencia respete
los mismos permisos y la misma sede que el listado que está debajo**. Un buscador propio por
módulo terminaría, tarde o temprano, mostrando algo que el listado esconde.

El filtro por tipo se aplica en el servidor, no en la pantalla: pedir un solo módulo ahorra las
consultas de los otros diez, no solo las esconde.

### Agregar el buscador a un listado

Dos piezas:

1. La fuente tiene que existir en `BuscadorGlobalService::fuentes()`. Si el módulo no está en
   el catálogo del buscador global, tampoco puede tener sugerencias — y agregarlo al catálogo
   lo habilita en los dos lados a la vez.
2. En la pantalla, reemplazar el `<input>` por `BuscadorModulo.vue`:

```vue
<BuscadorModulo
    v-model="form.buscar"
    tipos="producto"
    placeholder="Buscar por nombre o referencia..."
    @filtrar="filtrar"
/>
```

`@filtrar` se omite en los listados que ya filtran solos con un `watch` sobre los filtros: ahí
Enter sin nada resaltado solo cierra la sugerencia, porque la lista de abajo ya se filtró.

