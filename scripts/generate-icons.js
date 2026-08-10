import sharp from 'sharp'
import { mkdirSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

/**
 * Genera los iconos de la aplicación a partir del mismo monograma del favicon.
 *
 * Antes dibujaba las letras "B" y "BRIELA" con un degradado y unos adornos: a 72
 * píxeles el texto pequeño se convertía en una mancha. El monograma es una sola
 * forma, que es lo que aguanta cualquier tamaño.
 *
 * Se generan tres variantes, y no es capricho:
 *
 *   - Normales: con las esquinas redondeadas, como se ven en el navegador.
 *   - Maskable: fondo que llena el cuadrado y el logo al 60% centrado. Android
 *     recorta el icono con su propia máscara; si ya viene redondeado, el recorte
 *     deja un doble borde o se come el logo.
 *   - apple-touch-icon: cuadrado, SIN esquinas redondeadas. iOS las redondea él
 *     mismo, y un icono que ya viene redondeado termina con el borde sucio.
 */

const __dirname = dirname(fileURLToPath(import.meta.url))
const outDir = join(__dirname, '..', 'public', 'icons')
mkdirSync(outDir, { recursive: true })

const COLOR = '#2563EB'
const TINTA = '#FFFFFF'

// El mismo trazado del favicon, en un lienzo de 100×100.
const MONOGRAMA = 'M34 24h18.5c10 0 17.7 6.6 17.7 15.4 0 5-2.6 9.3-6.7 11.9 5.4 2.5 8.9 7.4 8.9 13.2 0 9.2-8 15.5-18.6 15.5H34V24Zm10 9.6v12.2h8.2c4.3 0 7.4-2.6 7.4-6.1 0-3.6-3.1-6.1-7.4-6.1H44Zm0 21v12.8h9.4c4.7 0 8-2.7 8-6.4s-3.3-6.4-8-6.4H44Z'

/**
 * @param {number} radio     esquinas, en unidades del lienzo de 100
 * @param {number} escala    tamaño del monograma (1 = como el favicon)
 */
function svg(radio, escala = 1) {
    const desplazamiento = (100 - 100 * escala) / 2

    return `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
  <rect width="100" height="100" rx="${radio}" fill="${COLOR}"/>
  <g transform="translate(${desplazamiento},${desplazamiento}) scale(${escala})">
    <path fill-rule="evenodd" clip-rule="evenodd" fill="${TINTA}" d="${MONOGRAMA}"/>
  </g>
</svg>`
}

const tamanos = [72, 96, 128, 144, 152, 192, 384, 512]

// Normales
for (const size of tamanos) {
    await sharp(Buffer.from(svg(26)))
        .resize(size, size)
        .png()
        .toFile(join(outDir, `icon-${size}.png`))
    console.log(`✓ icon-${size}.png`)
}

// Maskable: sin esquinas y con el logo dentro de la zona segura
for (const size of [192, 512]) {
    await sharp(Buffer.from(svg(0, 0.6)))
        .resize(size, size)
        .png()
        .toFile(join(outDir, `icon-${size}-maskable.png`))
    console.log(`✓ icon-${size}-maskable.png`)
}

// apple-touch-icon: cuadrado, que iOS ya se encarga de redondear
await sharp(Buffer.from(svg(0)))
    .resize(180, 180)
    .png()
    .toFile(join(__dirname, '..', 'public', 'apple-touch-icon.png'))
console.log('✓ apple-touch-icon.png')

console.log('Listo: iconos generados desde el monograma de la marca.')
