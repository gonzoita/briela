# Rendimiento — por qué el sistema se siente instantáneo

Briela se usa cambiando de pantalla todo el día. Medio segundo de más en cada cambio no
se nota una vez: se nota al final de la jornada, y se siente como que «el sistema está
pesado». Esta página explica cómo está construido para que no pase, y qué hay que
cuidar al agregar cosas.

## El punto de partida: el layout se reconstruye en cada navegación

`AppLayout.vue` **no** es un layout persistente de Inertia. Las 110 pantallas lo
escriben en su plantilla:

```vue
<AppLayout title="Clientes">
```

Inertia solo conserva un layout cuando se le asigna al componente
(`defineOptions({ layout })`); escrito en la plantilla, es un hijo más de la pantalla,
así que al cambiar de pantalla **se destruye y se construye de nuevo**, con el menú, la
campanita, el buscador y las dos burbujas adentro.

Eso no es un error por sí mismo —dibujar de nuevo es barato— pero convierte en caro
todo lo que el layout haga *al montarse*.

## Lo que se midió

El 17 sep 2026, con `php artisan serve`, un clic en «Clientes» del menú:

| | Antes | Después |
|---|---|---|
| Peticiones HTTP | 5 | **1** |
| Suma de sus tiempos | 14.235 ms | **1.111 ms** |
| La más lenta | `/notificaciones` · 5.467 ms | `/clientes` · 1.111 ms |
| Consultas en `share()` | 11 | **1** |

`/notificaciones` no tardaba cinco segundos por ser pesada —devuelve veinte filas—:
tardaba porque **las sesiones van en archivo y Laravel serializa las peticiones del
mismo usuario**. Cada una espera el candado de la sesión de la anterior. Cinco
peticiones simultáneas no son cinco veces una: son una fila.

## Las cuatro reglas que salieron de ahí

### 1. Nada dentro del layout pide datos al montarse

Un `fetch` en `onMounted` dentro del layout es un `fetch` por clic del menú. Eran seis.

Lo que **se ve siempre** —los contadores de la campanita y del botón flotante— vive en
`resources/js/composables/useAvisos.js`: refs de módulo, fuera del ciclo de vida de
cualquier componente, con **un solo** temporizador de 60 s para toda la aplicación. Al
remontarse el layout, el número ya está ahí: no parpadea y no pide nada.

Lo que **solo se ve al abrir un panel** se pide al abrirlo. El historial del asistente
(`useAsistente.js`) se traía en cada navegación para una conversación que casi nadie
abría.

Al navegar se refresca con freno (`refrescarSiHaceRato`, 30 s), no en cada clic. Lo
mismo al volver a la pestaña: `visibilitychange` se dispara cada vez que alguien
alterna con el correo.

### 2. Lo pesado que casi nadie abre se monta al abrirse

`AsistenteBurbuja` y `ChatBurbuja` suman 1.050 líneas y se construían enteras en cada
navegación. Ahora van con `v-if`, y quien las abre espera un `nextTick` antes de llamar
a su `ref` —el componente no existe hasta que Vue lo dibuja—.

Por eso el contador de no leídos (`sinLeerTotal`) vive en el módulo y no en
`ChatBurbuja`: si viviera ahí, habría que construir el chat entero en cada pantalla
solo para saber si hay un 3 sobre el botón.

### 3. Toda escucha global se quita, y con función con nombre

```js
window.addEventListener('online', alConectar)      // sí
window.addEventListener('online', () => { ... })   // no: no se puede quitar
```

`removeEventListener` necesita **la misma referencia**, así que una función anónima
queda pegada para siempre. Con el layout remontándose en cada clic, eran cuatro escuchas
nuevas por navegación, cada una reteniendo una instancia completa del layout.

Esto es lo que hacía que el sistema se pusiera más lento cuanto más rato llevaba abierta
la pestaña, y que recargar con F5 lo «arreglara» un rato.

Lo que debe ocurrir **una sola vez por sesión** se marca con `soloUnaVez()` del módulo:
un `let` dentro de `<script setup>` no sirve, porque vive dentro de `setup` y nace de
nuevo con cada instancia del componente.

### 4. En el servidor, un ajuste no es una consulta

`Configuracion::get()` lee la tabla completa —48 filas— **una vez por petición**, y
responde desde ahí. Antes cada llamada era su propia consulta, y el bloque `marca` de
`HandleInertiaRequests::share()` hace nueve: nombre, logo, si el logo es propio, logo
oscuro, favicon, favicon oscuro, color, correo y web. Más las dos del asistente.

`ImagenMarcaService` memoriza igual sus `Storage::exists()`: eran seis vistazos al disco
por navegación, y ninguno cambia dentro de la misma petición.

> **El caché de petición va en el contenedor** (`app()->instance(...)`), nunca en una
> propiedad `static`. Una estática vive lo que vive el proceso de PHP, no la petición:
> en las pruebas, donde un mismo proceso corre los 104 casos seguidos, el mapa de un
> test sobreviviría al `RefreshDatabase` del siguiente y devolvería ajustes de una base
> que ya se borró. El contenedor se reconstruye en cada petición y en cada test.

Las escrituras invalidan solo: `Configuracion` lo hace desde los eventos `saved` y
`deleted` del modelo, así que da igual si se guardó con `set()` o con un Eloquent suelto.

## Cómo se comprueba

Con el sistema abierto, en la consola del navegador: se limpia, se hace **un** clic en
el menú y se cuenta.

```js
performance.clearResourceTimings()
// … clic en una opción del menú …
performance.getEntriesByType('resource')
    .filter(e => e.initiatorType === 'fetch' || e.initiatorType === 'xmlhttprequest')
    .map(e => new URL(e.name).pathname + ' · ' + Math.round(e.duration) + 'ms')
```

Lo esperado es **una sola línea**: la pantalla que se abrió. Si aparecen más, algo
nuevo está pidiendo datos al montarse.

Del lado del servidor, `HandleInertiaRequests::share()` tiene que resolverse en una
consulta. Lo que se agregue a `share()` se paga en cada clic del menú: o se memoriza, o
se manda como `fn () => …` para que Inertia lo resuelva solo cuando toca.

## Lo que queda pendiente

Hacer `AppLayout` **persistente de verdad** —sacar `<AppLayout>` de las 110 pantallas y
asignarlo en `app.js`— ahorraría también el redibujado del menú en cada navegación. Es
mecánico (las 110 lo usan igual, solo con `title`), pero el título tendría que viajar
por `<Head>` en cada pantalla, y son 110 archivos reindentados. Con las peticiones ya
fuera, lo que queda por ganar ahí son decenas de milisegundos, no segundos.
