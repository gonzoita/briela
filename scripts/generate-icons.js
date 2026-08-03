import sharp from 'sharp'
import { mkdirSync, copyFileSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const outDir    = join(__dirname, '..', 'public', 'icons')
mkdirSync(outDir, { recursive: true })

const sizes = [72, 96, 128, 144, 152, 192, 384, 512]

for (const size of sizes) {
    const radius = Math.round(size * 0.18)
    const fsMain = Math.round(size * 0.3)
    const fsSub  = Math.round(size * 0.1)

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#0d52a8"/>
      <stop offset="100%" stop-color="#2563EB"/>
    </linearGradient>
  </defs>
  <rect width="${size}" height="${size}" rx="${radius}" ry="${radius}" fill="url(#bg)"/>
  <rect width="${size}" height="${Math.round(size * 0.35)}" rx="${radius}" ry="${radius}" fill="rgba(255,255,255,0.06)"/>
  <text x="${size / 2}" y="${Math.round(size * 0.5)}" font-family="Arial,sans-serif" font-size="${fsMain}" font-weight="bold" fill="white" text-anchor="middle" dominant-baseline="middle">B</text>
  <text x="${size / 2}" y="${Math.round(size * 0.72)}" font-family="Arial,sans-serif" font-size="${fsSub}" fill="rgba(255,255,255,0.5)" text-anchor="middle" dominant-baseline="middle">BRIELA</text>
  <rect x="${Math.round(size * 0.2)}" y="${Math.round(size * 0.8)}" width="${Math.round(size * 0.6)}" height="${Math.round(size * 0.04)}" fill="rgba(255,255,255,0.15)" rx="2"/>
</svg>`

    await sharp(Buffer.from(svg))
        .resize(size, size)
        .png()
        .toFile(join(outDir, `icon-${size}.png`))

    console.log(`✓ icon-${size}.png`)
}

copyFileSync(
    join(outDir, 'icon-192.png'),
    join(__dirname, '..', 'public', 'apple-touch-icon.png')
)
console.log('✓ apple-touch-icon.png')
console.log('✅ Todos los íconos generados en public/icons/')
