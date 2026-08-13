<?php

namespace App\Services\IA;

use App\Models\Configuracion;
use App\Models\PerfilMarca;
use App\Support\Marca;

/**
 * Redacta la ficha técnica de un producto o un ensamble con IA.
 *
 * Tres decisiones de fondo:
 *
 * 1. **El prompt es configurable.** Vive en `configuraciones` (clave `ia_prompt_ficha`), con
 *    el texto de abajo como valor por omisión. Si estuviera incrustado en el código, el día
 *    que un cliente quiera otra estructura de ficha habría que parchear su instalación —y
 *    esa es la primera regla del producto instalable.
 * 2. **El tono lo pone el perfil de marca**, no este archivo. Se manda solo lo que afecta
 *    la redacción: tono y voz, identidad, propuesta de valor y mensaje clave. La DOFA y los
 *    KPIs no ayudan a escribir una ficha y se pagan en tokens.
 * 3. **La respuesta se pide en JSON**, no en Markdown. El campo de descripción larga es un
 *    editor de texto enriquecido que espera HTML; convertir Markdown a mano es una fuente
 *    inagotable de basura en el campo.
 */
class FichaTecnicaService
{
    private const CLAVE_PROMPT = 'ia_prompt_ficha';

    /** Las secciones del perfil que de verdad cambian cómo se escribe. */
    private const SECCIONES_QUE_IMPORTAN = ['tono_voz', 'identidad', 'propuesta_valor', 'mensaje_clave', 'promesa'];

    /** Las etiquetas que el editor de descripción larga sabe mostrar. */
    private const ETIQUETAS_PERMITIDAS = ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'h3', 'h4'];

    /**
     * Lo que aporta el usuario, un campo por bloque de la ficha.
     *
     * El rótulo viaja en el prompt: es lo que le dice al modelo que ese texto es la fuente
     * de **ese** bloque y no de otro.
     */
    private const APORTES = [
        'aporte_descripcion'     => 'QUÉ ES Y PARA QUÉ SIRVE (lo escribió el usuario; es la base del bloque 3, la introducción)',
        'aporte_caracteristicas' => 'CARACTERÍSTICAS TÉCNICAS EN BRUTO (fuente del bloque 4; agrúpalas tú en subtítulos)',
        'aporte_ventajas'        => 'VENTAJAS QUE SEÑALA EL USUARIO (fuente del bloque 5)',
        'aporte_beneficios'      => 'BENEFICIOS QUE SEÑALA EL USUARIO (fuente del bloque 6)',
        'aporte_componentes'     => 'COMPONENTES, ACCESORIOS O MÓDULOS QUE INDICA EL USUARIO (fuente del bloque 7)',
    ];

    public function __construct(private IaService $ia) {}

    /**
     * El prompt vigente: el que la empresa haya guardado, o el de fábrica.
     */
    public function prompt(): string
    {
        $guardado = Configuracion::get(self::CLAVE_PROMPT, '');

        return filled($guardado) ? $guardado : self::promptPorOmision();
    }

    public function guardarPrompt(?string $texto): void
    {
        // Vacío significa «volver al de fábrica», no «ficha sin instrucciones».
        Configuracion::set(self::CLAVE_PROMPT, trim((string) $texto));
    }

    /**
     * Genera la ficha.
     *
     * @param  array<string, mixed>  $datos  nombre, referencia, categoria, unidad, tipo,
     *                                       datos_brutos y, para ensambles, variables y
     *                                       componentes.
     * @return array{descripcion_corta: string, descripcion_cotizacion: string, ficha_html: string, aviso: ?string}
     */
    public function generar(array $datos): array
    {
        $bruto = $this->ia->texto(
            prompt: $this->armarPeticion($datos),
            instrucciones: $this->armarInstrucciones(),
            maxTokens: 2600,
        );

        return $this->interpretar($bruto);
    }

    /**
     * Las instrucciones del sistema: el prompt de la empresa, el tono de la marca y el
     * contrato de salida.
     */
    private function armarInstrucciones(): string
    {
        $partes = [$this->prompt()];

        $empresa = Marca::nombreEmpresa();

        if (filled($empresa)) {
            $partes[] = "La empresa que fabrica o vende este producto es {$empresa}.";
        }

        if ($marca = $this->contextoDeMarca()) {
            $partes[] = "VOZ DE LA MARCA (es la que manda sobre el tono):\n{$marca}";
        }

        // Estas tres no son negociables por configuración: son del sistema, no del gusto de
        // la empresa. El voseo está prohibido en todo Briela, y el formato de salida lo
        // necesita la pantalla para llenar los dos campos.
        $partes[] = <<<'TXT'
        REGLAS DEL SISTEMA (por encima de cualquier otra indicación):
        - Español colombiano neutro. Prohibido el voseo: nunca "tomá", "mirá", "elegí".
        - No inventes especificaciones. Si un dato no te lo dieron, no lo menciones.
        - Sin emojis.

        CÓMO LEER LO QUE APORTA EL USUARIO
        Los datos llegan separados por bloque y con su rótulo. Cada bloque es la fuente de
        SU sección: lo que está bajo "características" no se convierte en un beneficio, y lo
        que el usuario puso como ventaja no se repite entre las especificaciones.
        - Un bloque que llegue vacío no se omite: dedúcelo de las características técnicas
          que sí tengas, sin inventar datos nuevos. Un beneficio o una ventaja son
          interpretaciones de un dato técnico, y eso sí puedes hacerlo.
        - Si el usuario escribió poco en un bloque, respétalo y ordénalo; no lo infles.
        - No repitas la misma idea en dos bloques con otras palabras.
        - Los componentes que el sistema ya calculó (la receta de un ensamble) son la fuente
          más confiable del bloque 7: úsalos tal como están y agrega los que indique el
          usuario.

        FORMATO DE RESPUESTA: devuelve **únicamente** un objeto JSON válido, sin texto antes
        ni después y sin cercas de código, con exactamente estas tres claves:

        {
          "descripcion_corta": "El bloque 3 (introducción persuasiva) en texto plano, máximo 380 caracteres, sin títulos ni viñetas.",
          "descripcion_cotizacion": "El resumen TÉCNICO para cotizaciones y órdenes de producción: máximo 400 caracteres, texto plano, sin títulos ni viñetas. Solo los datos que un cliente necesita leer al lado del precio —medidas, material, potencia, capacidad— separados por · (punto medio). Nada de lenguaje comercial ni de beneficios: eso ya está en la introducción. Ejemplo: «2400 x 2600 mm · lámina galvanizada cal. 22 · aislamiento poliuretano 40 mm · motor 1.5 kW 220V trifásico · rango -25 °C a 40 °C · IP65».",
          "ficha_html": "Del bloque 4 al 7 en HTML. Usa <h4> para el título de cada bloque y para tus subtítulos, <ul><li> para las viñetas, <p> para párrafos y <strong> para resaltar. Nada de <div>, <span>, <style>, atributos ni clases."
        }

        No incluyas en `ficha_html` los bloques 1 y 2 (nombre y referencia): esos campos ya
        existen en el sistema y repetirlos ensucia la ficha.
        TXT;

        return implode("\n\n", $partes);
    }

    /** Lo que se le manda como datos en bruto. */
    private function armarPeticion(array $datos): string
    {
        $lista = collect([
            'Nombre'             => $datos['nombre'] ?? null,
            'Referencia'         => $datos['referencia'] ?? null,
            'Categoría'          => $datos['categoria'] ?? null,
            'Unidad de medida'   => $datos['unidad'] ?? null,
            'Tipo'               => $datos['tipo'] ?? null,
            'Descripción actual' => $datos['descripcion_corta'] ?? null,
        ])->filter(fn ($v) => filled($v))
          ->map(fn ($v, $k) => "- {$k}: {$v}")
          ->implode("\n");

        $peticion = "DATOS DEL PRODUCTO\n{$lista}";

        // Los ensambles traen su receta ya calculada: son datos técnicos de verdad —medidas
        // y materiales— y nadie tiene que volver a escribirlos a mano.
        if (filled($datos['variables'] ?? null)) {
            $peticion .= "\n\nMEDIDAS Y VARIABLES DE ESTA CONFIGURACIÓN\n".$this->comoLineas($datos['variables']);
        }

        if (filled($datos['componentes'] ?? null)) {
            $peticion .= "\n\nCOMPONENTES DE LA RECETA (calculados por el sistema)\n"
                .$this->comoLineas($datos['componentes']);
        }

        // Lo que aporta el usuario, **separado por bloque**.
        //
        // Antes era una sola caja de «datos en bruto» y el modelo tenía que adivinar qué
        // parte de ese texto era una característica, qué era una ventaja y qué un beneficio.
        // Adivinaba mal: mezclaba beneficios entre las especificaciones y repetía la misma
        // idea en tres bloques. Si el usuario ya sabe a qué bloque pertenece cada cosa,
        // decirlo cuesta un rótulo y mejora toda la ficha.
        foreach (self::APORTES as $campo => $rotulo) {
            if (filled($datos[$campo] ?? null)) {
                $peticion .= "\n\n{$rotulo}\n".trim($datos[$campo]);
            }
        }

        if (filled($datos['datos_brutos'] ?? null)) {
            $peticion .= "\n\nOTROS DATOS SUELTOS\n".trim($datos['datos_brutos']);
        }

        return $peticion;
    }

    /** @param  array<int|string, mixed>  $filas */
    private function comoLineas(array $filas): string
    {
        return collect($filas)
            ->map(function ($valor, $clave) {
                if (is_array($valor)) {
                    // Un componente de la receta se lee mejor como «Lámina cal 22 — 12 m2»
                    // que como «nombre: Lámina cal 22, cantidad: 12, unidad: m2».
                    if (filled($valor['nombre'] ?? null)) {
                        $cantidad = trim(($valor['cantidad'] ?? '').' '.($valor['unidad'] ?? ''));

                        return '- '.$valor['nombre'].($cantidad !== '' ? " — {$cantidad}" : '');
                    }

                    $valor = collect($valor)
                        ->filter(fn ($v) => is_scalar($v) && filled($v))
                        ->map(fn ($v, $k) => is_int($k) ? $v : "{$k}: {$v}")
                        ->implode(', ');
                }

                return is_int($clave) ? "- {$valor}" : "- {$clave}: {$valor}";
            })
            ->filter(fn ($linea) => trim($linea) !== '-')
            ->take(60)
            ->implode("\n");
    }

    /** Solo las secciones del perfil que afectan la redacción. */
    private function contextoDeMarca(): ?string
    {
        $catalogo = PerfilMarca::catalogo();

        $texto = PerfilMarca::whereIn('seccion', self::SECCIONES_QUE_IMPORTAN)
            ->orderBy('orden')
            ->get()
            ->filter(fn ($s) => filled($s->contenido))
            ->map(fn ($s) => ($catalogo[$s->seccion]['label'] ?? $s->seccion).": {$s->contenido}")
            ->implode("\n\n");

        return filled($texto) ? $texto : null;
    }

    /**
     * Del texto del modelo a los dos campos.
     *
     * Un modelo devuelve JSON casi siempre, pero «casi siempre» no alcanza cuando el
     * resultado va a un campo del formulario: si no viene JSON, se aprovecha lo que vino
     * como descripción larga en vez de perder la respuesta y hacer pagar otra llamada.
     *
     * @return array{descripcion_corta: string, descripcion_cotizacion: string, ficha_html: string, aviso: ?string}
     */
    private function interpretar(string $bruto): array
    {
        $limpio = trim($bruto);

        // Cercas de código, que aparecen aunque se pidan expresamente.
        $limpio = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $limpio) ?? $limpio;

        $json = null;

        if (preg_match('/\{.*\}/s', $limpio, $coincidencias)) {
            $json = json_decode($coincidencias[0], true);
        }

        if (is_array($json) && (isset($json['ficha_html']) || isset($json['descripcion_corta']))) {
            return [
                'descripcion_corta'      => $this->recortar((string) ($json['descripcion_corta'] ?? ''), 380),
                // 400 y no 600 —el tope de la columna— para que quepa en una línea o dos
                // del PDF sin desarmar la tabla de ítems.
                'descripcion_cotizacion' => $this->recortar((string) ($json['descripcion_cotizacion'] ?? ''), 400),
                'ficha_html'             => $this->limpiarHtml((string) ($json['ficha_html'] ?? '')),
                'aviso'                  => null,
            ];
        }

        return [
            'descripcion_corta'      => '',
            'descripcion_cotizacion' => '',
            'ficha_html'             => $this->comoParrafos($limpio),
            'aviso'             => 'La IA no respondió en el formato esperado, así que la ficha quedó '
                .'completa en la descripción larga y la corta quedó vacía. Revisa el resultado '
                .'antes de guardar.',
        ];
    }

    /** Corta en el último espacio antes del límite: cortar a media palabra se nota. */
    private function recortar(string $texto, int $limite): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', strip_tags($texto)) ?? '');

        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        $corte = mb_substr($texto, 0, $limite);
        $ultimo = mb_strrpos($corte, ' ');

        return rtrim($ultimo ? mb_substr($corte, 0, $ultimo) : $corte, " ,;:").'.';
    }

    /**
     * Deja solo las etiquetas que el editor entiende, y **sin atributos**.
     *
     * `strip_tags` con lista blanca conserva los atributos —incluido un `onclick`—, así que
     * hace falta la segunda pasada. El texto lo genera un modelo a partir de datos que
     * escribe el usuario, y ese texto termina publicado en el sitio web del cliente.
     */
    private function limpiarHtml(string $html): string
    {
        // Primero el contenido de script y style, no solo sus etiquetas: `strip_tags` quita
        // `<script>` pero deja el `alert(1)` de adentro como texto suelto en la descripción.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;

        $permitidas = '<'.implode('><', self::ETIQUETAS_PERMITIDAS).'>';
        $html = strip_tags($html, $permitidas);

        $lista = implode('|', self::ETIQUETAS_PERMITIDAS);

        return trim(preg_replace('#<(/?)('.$lista.')\b[^>]*>#i', '<$1$2>', $html) ?? $html);
    }

    /** Para el caso en que no vino JSON: cada línea suelta, un párrafo. */
    private function comoParrafos(string $texto): string
    {
        return collect(preg_split('/\n{1,}/', strip_tags($texto)) ?: [])
            ->map(fn ($linea) => trim($linea))
            ->filter()
            ->map(fn ($linea) => '<p>'.e($linea).'</p>')
            ->implode('');
    }

    /**
     * El prompt de fábrica.
     *
     * Es el punto de partida y la empresa lo puede reescribir completo desde
     * Configuración → IA. Las reglas del sistema (español sin voseo, no inventar datos,
     * formato de salida) se agregan aparte y no se pueden quitar desde ahí.
     */
    public static function promptPorOmision(): string
    {
        return <<<'TXT'
        ROL Y OBJETIVO
        Eres un Copywriter Técnico y Especialista en Fichas Técnicas de Productos. Tu objetivo es
        recibir datos técnicos en bruto proporcionados por el usuario y transformarlos en fichas
        técnicas exhaustivas, detalladas, precisas y profesionalmente estructuradas.

        TONO Y VOZ
        De acuerdo a la marca.

        REGLAS DE ORO (GUARDRAILS)
        1. ESTRUCTURA FIJA, CONTENIDO FLEXIBLE: mantén estrictamente los bloques numerados del
           esquema, pero adapta dinámicamente el contenido interno (especialmente en la sección 4)
           según los datos específicos proporcionados.
        2. NUNCA inventes especificaciones técnicas. Si la información entregada es abundante, sé
           extremadamente detallado; si es concisa, organízala de forma impecable sin agregar datos
           ficticios.
        3. NUNCA incluyas campos ni menciones sobre "Disponibilidad de inventario", "Precios" ni
           "Garantía".
        4. FORMATO: utiliza párrafos cortos y listas con viñetas simples. No utilices viñetas
           anidadas ni sub-listas.

        ESTRUCTURA EXACTA DE SALIDA

        1. Nombre del producto:
        Nombre limpio y específico del producto, alineado a su estándar industrial.

        2. Referencia del producto:
        Únicamente la referencia, modelo o código comercial proporcionado. Si no se provee, omite
        este campo manteniendo la numeración general.

        3. Introducción persuasiva y comercial del producto (máximo 380 caracteres):
        Redacta un solo párrafo potente. Debe incluir: 1) la solución al problema funcional; 2) el
        respaldo técnico o de fabricación; 3) mención a la confiabilidad operacional. Usa un tono
        técnico-comercial que conecte ingeniería y beneficios.

        4. Especificaciones técnicas del producto:
        Esta sección debe ser 100% FLEXIBLE y DETALLADA. Analiza los datos suministrados y
        agrúpalos lógicamente creando TUS PROPIOS SUBTÍTULOS según corresponda al producto (por
        ejemplo: si hay dimensiones, crea "Dimensiones y peso"; si hay datos eléctricos, crea
        "Especificaciones eléctricas"; si hay materiales, crea "Materiales y acabados").
        - Sé exhaustivo al organizar la información.
        - Transforma los datos sueltos en viñetas técnicas bien redactadas, claras y completas.
        - Incluye siempre un subtítulo de "Origen y respaldo" con viñetas que destaquen la calidad
          de fabricación, la integración de componentes certificados o los estándares de calidad
          aplicables, según la información dada.

        5. Ventajas del producto (máximo 5):
        Hasta 5 viñetas enfocadas en las características técnicas superiores del producto y en la
        solidez del soporte o ingeniería frente a alternativas estándar del mercado.

        6. Beneficios para el usuario final (máximo 5):
        Hasta 5 viñetas enfocadas en el valor operacional real que obtiene el cliente (eficiencia
        energética, reducción de mermas, durabilidad, optimización de tiempos, seguridad
        operativa, etc.).

        7. Componentes:
        Viñetas con los componentes, accesorios o módulos principales necesarios y relacionados
        con el equipo, de manera específica y detallada.

        INSTRUCCIÓN DE PROCESAMIENTO
        Analiza minuciosamente los datos recibidos, categorízalos y genera la ficha técnica
        completa siguiendo exactamente la estructura solicitada. Entrega únicamente la ficha
        técnica final, sin saludos, sin textos introductorios, sin confirmaciones de recepción y
        sin preguntas al final.
        TXT;
    }
}
