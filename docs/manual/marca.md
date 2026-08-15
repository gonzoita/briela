# Marca — color, favicon y título de la pestaña

**Configuración → Marca.**

Toda la identidad visual se edita desde la app. No hay colores ni logos
quemados en el código, así que el sistema se puede entregar a otra empresa
sin tocar una línea ni recompilar nada.

## Color

Se elige **un solo color**. De ahí se calculan los demás:

| Derivado | Para qué |
|---|---|
| **base** | Encabezado, menú, botones principales, enlaces |
| **oscuro** | Hover y estado presionado |
| **suave** | Fondos de iconos y tarjetas |
| **medio** | Etiquetas y badges |
| **texto** | El color de la letra que va *encima* del color base |

Se pide uno y no cuatro a propósito: pedir cuatro colores garantiza que
tarde o temprano salga una combinación ilegible.

El color de texto no es un promedio: usa **luminancia relativa** (la fórmula
de accesibilidad WCAG), que pesa el verde mucho más que el azul porque así lo
percibe el ojo. Por eso un amarillo recibe texto negro y un azul del mismo
brillo promedio recibe texto blanco.

La vista previa muestra un encabezado, una tarjeta, botones, un enlace y una
etiqueta con el color que estés probando, **antes** de guardarlo.

### Dónde se aplica

En toda la plataforma: encabezado, menú lateral, barra inferior, botones,
enlaces, etiquetas, la burbuja del asistente y la barra de carga. También en
el `theme-color` del navegador, que en Android pinta la barra de estado.

Técnicamente son variables CSS (`var(--marca)`) que Blade imprime en cada
carga. Por eso el cambio se ve al recargar, sin recompilar.

### Lo que NO cambia de color

- **Los colores de estado** — rojo para vencido, ámbar para advertencia, verde
  para aprobado. Significan algo y no deben depender de la marca.
- **Códigos QR y etiquetas impresas** — necesitan contraste alto garantizado
  para que el lector funcione.
- **Plantillas PDF** — las genera dompdf en el servidor, que no entiende
  variables CSS. Sus colores se editan en el editor de plantillas.
- **Colores de categorías de productos** — son datos que eliges tú por
  categoría.

## Los tonos de las líneas y del texto

El tema define tres grises distintos para separar cosas, y usarlos bien es lo que
hace que una tabla larga se lea sin cansar:

| Token | Para qué | Día | Noche |
|---|---|---|---|
| `border-linea` | El borde de una tarjeta o de un campo: **delimita** | `#EDEFF2` | `#2E3542` |
| `divide-separador` | La línea entre filas de una lista: solo deja **seguir el renglón** | `#F2F4F7` | `#262C37` |
| `hover:bg-realce` | El renglón donde está el puntero, o el elemento elegido | `#F4F7FE` | `#282F3B` |

**No usar `divide-gray-50`, `border-gray-50` ni `hover:bg-blue-50`.** Son colores
fijos de Tailwind que ignoran el tema: `gray-50` es `#F9FAFB`, casi blanco, y en modo
noche eso da un contraste de **15,6:1 contra la superficie** — la razón por la que las
tablas se veían con líneas encendidas. Con `separador` queda en 1,16:1, que es lo que
tiene que ser una línea: presente, no protagonista. El 15 ago 2026 se cambiaron 107
clases fijas de línea y 49 de realce.

El texto del modo noche tampoco es blanco. `#F2F4F7` daba 14,8:1, muy por encima de
lo que pide la accesibilidad, y en una tabla de cuarenta filas en negrita se lee como
un foco; ahora es `#E4E8EE`, que deja 13,3:1 —de sobra, AAA pide 7— y descansa la
vista. El texto auxiliar subió de 3,8:1 a 4,8:1, que era el que **sí** estaba por
debajo del mínimo.

## Los colores de aviso

Un aviso azul informa, uno rojo advierte, uno ámbar pide atención. Ese significado
tiene que sobrevivir al cambio de tema, y de noche **no** puede ser una caja clara.

Cada familia tiene cuatro tonos, y el sistema los cambia solo:

| | Día | Noche |
|---|---|---|
| Fondo de la caja | un pastel claro | un tinte oscuro del mismo color |
| Fondo de la insignia interior | un paso más saturado | un paso más claro |
| Borde | un pastel medio | un gris teñido |
| Texto | oscuro y saturado | claro y saturado |

En los dos temas todos los pares texto/fondo quedan **por encima de 5,4:1**, y casi
todos por encima de 7 —que es el nivel más exigente de accesibilidad—. La caja se
distingue de la superficie por poco, a propósito: un aviso tiene que llamar la atención
por su color, no por su brillo.

Hasta el 15 ago 2026 esto estaba escrito con los colores fijos de Tailwind: `bg-red-50`
con `text-red-700`, 2.063 veces. De día se veía bien; de noche cada caja de aviso era un
rectángulo claro con texto oscuro, y era la razón principal de que la pantalla «se viera
muy blanca».

## Título de la pestaña

Admite dos comodines:

- `{pagina}` — la pantalla en la que estés (Clientes, Dashboard...)
- `{empresa}` — el nombre de Ajustes → Empresa

Ejemplos:

```
Briela — {empresa}          →  Briela — Mi Empresa SAS
{pagina} · {empresa}     →  Clientes · Mi Empresa SAS
{pagina} | Mi ERP        →  Clientes | Mi ERP
```

Si la pantalla no trae título propio, el separador huérfano se recorta solo:
nunca vas a ver "— Mi Empresa SAS" con el guion suelto al principio.

Este título también es el que aparece en la vista previa de los enlaces al
compartirlos por WhatsApp.

## Favicon y logo

Los dos se suben desde **Configuración → Marca**. Se guardan **en el propio
servidor**, en `storage/app/public/marca`.

- **Favicon**: PNG cuadrado de 512×512, máximo 512 KB. Ese mismo archivo sirve
  para la pestaña del navegador y para el ícono de la app instalada en el
  celular.
- **Logo**: PNG con fondo transparente, máximo 2 MB. Sale en el encabezado, en
  el menú lateral y en la vista previa de los enlaces compartidos.

Se aplican apenas eliges el archivo; no hay que darle Guardar aparte.

### Por qué no van a Google Drive

Antes se subían a Drive y **salían rotos**: Drive entrega enlaces de *vista
previa*, que son páginas web, no imágenes. El navegador no las puede poner en
un `<img>`. Además ponía el logo de la empresa a depender de credenciales de
un servicio externo.

Al guardarlos en el servidor no hay dependencias ni credenciales, y el archivo
se sirve directo.

### Sobre la caché del navegador

Cada subida guarda el archivo con un nombre distinto (lleva la fecha y hora),
así que la URL cambia y el navegador se ve obligado a bajar la nueva. Sin eso,
el favicon anterior se quedaba pegado durante días.

El archivo viejo se borra solo al subir uno nuevo.

### Si las imágenes no cargan en el servidor

Las imágenes se sirven por el enlace `public/storage`. Si no existe, ninguna
imagen subida se ve. Se crea una sola vez con:

```
php artisan storage:link
```
