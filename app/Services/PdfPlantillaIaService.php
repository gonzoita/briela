<?php

namespace App\Services;

use App\Services\IA\IaService;

/**
 * La IA de las plantillas PDF, en sus dos caminos.
 *
 * El meta-prompt es UNO: el que Briela manda por el proxy (flujo A) y el que el
 * usuario copia para pegarlo en una IA externa (flujo B). Si fueran dos, la IA
 * externa acabaría conociendo otras variables u otras reglas de CSS que la
 * interna, y el HTML que vuelve dejaría de encajar en el editor.
 *
 * La respuesta viene en tres piezas marcadas con comentarios HTML, y separar()
 * las reparte en encabezado, cuerpo y pie. Es tolerante: si la IA devuelve un
 * solo bloque, todo va al cuerpo.
 */
class PdfPlantillaIaService
{
    public const MARCAS = ['header' => 'ENCABEZADO', 'body' => 'CUERPO', 'footer' => 'PIE'];

    public function __construct(private IaService $ia) {}

    public static function metaPrompt(string $modulo, string $instruccion = '', array $actual = []): string
    {
        $label = config("pdf_modulos.{$modulo}.label") ?? $modulo;

        $vars = collect(PdfVariablesEngine::variablesDisponibles($modulo))
            ->groupBy('grupo')
            ->map(fn ($g, $grupo) => "## {$grupo}\n" . $g->map(fn ($v) => "- `{$v['var']}` — {$v['desc']}")->implode("\n"))
            ->implode("\n\n");

        $actualTxt = '';
        if (trim(($actual['header'] ?? '') . ($actual['body'] ?? '') . ($actual['footer'] ?? '')) !== '') {
            $actualTxt = "\n# Plantilla actual (modifícala, no empieces de cero)\n\n"
                . static::unir($actual['header'] ?? '', $actual['body'] ?? '', $actual['footer'] ?? '') . "\n";
        }

        $pedido = trim($instruccion) !== ''
            ? trim($instruccion)
            : "Diseña una plantilla profesional y sobria para el documento «{$label}».";

        $m = static::MARCAS;

        return <<<TXT
Eres un maquetador experto en documentos PDF comerciales. Genera una plantilla HTML para el documento «{$label}» de un ERP colombiano. Idioma: español neutro.

# Lo que se pide
{$pedido}
{$actualTxt}
# Formato de la respuesta (OBLIGATORIO)
Devuelve SOLO el código, sin explicaciones ni bloques ```, en tres piezas separadas exactamente así:

<!-- {$m['header']} -->
(HTML del encabezado: se repite arriba en CADA página. Logo, empresa, número del documento. Máximo ~25 mm de alto.)
<!-- {$m['body']} -->
(Un <style> con todo el CSS, y el contenido principal. Sin <html>, <head> ni <body>.)
<!-- {$m['footer']} -->
(HTML del pie: se repite abajo en CADA página. Máximo ~15 mm de alto. Incluye «Página {{pagina}} de {{total_paginas}}».)

# Motor de renderizado: dompdf (CSS 2.1)
- NO hay flexbox, grid, variables CSS, calc(), ni fuentes web. Nada de JavaScript.
- Columnas: usa <table> o `display: table` / `display: table-cell`.
- Fuente: `font-family: DejaVu Sans, Arial, sans-serif` (DejaVu tiene tildes y ñ). Tamaños en px o pt, 8–11px para el cuerpo.
- Colores en hex. El color de la marca es {{empresa.color}}: úsalo tal cual donde haga falta, también dentro del <style> (ej. `th { background: {{empresa.color}}; }`).
- Imágenes: `<img src="{{empresa.logo_url}}" style="height:40px">`; las de las filas con `<img src="{{!imagen_base64}}">`.
- Los márgenes de la página los pone el sistema: no uses `@page` ni padding en el body.
- Saltos de página: `{{salto_pagina}}`. Para que un bloque no se parta entre dos páginas: `class="evitar-corte"`.
- Tablas largas: pon los títulos en <thead>, así se repiten en cada página.

# Sintaxis de la plantilla
- Variable: `{{cliente.nombre}}`. Con formato: `{{cotizacion.total|moneda}}`, `{{cotizacion.created_at|fecha}}`.
- Lista (filas de la tabla): `{{#each items}} <tr><td>{{@numero}}</td><td>{{descripcion}}</td></tr> {{/each}}`.
  Dentro de la lista se usan los campos de la fila sin prefijo, y también cualquier variable global.
- Condicional: `{{#if cotizacion.descuento_total}} … {{else}} … {{/if}}`. Compara con ==, !=, >, <: `{{#if op.estado == "despachada"}}`.
- Usa SOLO las variables del diccionario. No inventes ninguna: una variable que no existe sale vacía.

# Diccionario de variables disponibles
{$vars}
TXT;
    }

    /** Flujo A: Briela genera o edita la plantilla. */
    public function generar(string $modulo, string $instruccion, array $actual): array
    {
        $respuesta = $this->ia->texto(
            static::metaPrompt($modulo, $instruccion, $actual),
            'Respondes únicamente con el código de la plantilla en el formato pedido.',
            8000,
        );

        return static::separar($respuesta);
    }

    /**
     * Reparte el texto de una IA en encabezado, cuerpo y pie.
     *
     * @return array{header: string, body: string, footer: string}
     */
    public static function separar(string $texto): array
    {
        // Quita las cercas ``` que casi todas las IA ponen aunque se les pida que no.
        $texto = preg_replace('/^\s*```[\w-]*\s*$/m', '', $texto);
        $texto = trim($texto);

        $partes = ['header' => '', 'body' => '', 'footer' => ''];
        $patron = '/<!--\s*(' . implode('|', static::MARCAS) . ')\s*-->/i';

        if (! preg_match($patron, $texto)) {
            $partes['body'] = $texto;
            return $partes;
        }

        $trozos = preg_split($patron, $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        $clave  = array_flip(static::MARCAS);
        for ($i = 1; $i < count($trozos); $i += 2) {
            $slot = $clave[strtoupper($trozos[$i])] ?? 'body';
            $partes[$slot] .= trim($trozos[$i + 1] ?? '');
        }
        // Lo que venga antes de la primera marca (un <style> suelto) va al cuerpo.
        if (trim($trozos[0]) !== '') {
            $partes['body'] = trim($trozos[0]) . "\n" . $partes['body'];
        }

        return $partes;
    }

    public static function unir(string $header, string $body, string $footer): string
    {
        $m = static::MARCAS;
        return "<!-- {$m['header']} -->\n{$header}\n<!-- {$m['body']} -->\n{$body}\n<!-- {$m['footer']} -->\n{$footer}";
    }
}
