<?php

namespace App\Services\IA;

use App\Models\PerfilMarca;
use App\Support\Marca;

/**
 * Redacta el objetivo y la descripción de un paso de producción.
 *
 * No escribe a la primera. Un paso mal descrito es el que el operario interpreta a su manera,
 * y la IA sola no sabe si «pintar» es con soplete o con brocha, ni qué se considera bien hecho
 * en esta empresa. Así que primero **pregunta** —tres o cuatro cosas concretas sobre ese paso—
 * y solo después redacta, con lo que respondió el usuario, el perfil de marca y las variables
 * de la plantilla.
 *
 * El resultado es un instructivo específico de cómo se hace ESE paso, no un texto genérico.
 */
class PasoProduccionIaService
{
    /** Del perfil de marca, lo que le sirve a quien está en la planta. */
    private const SECCIONES_QUE_IMPORTAN = ['identidad', 'producto', 'operacion', 'calidad'];

    public function __construct(private IaService $ia) {}

    /**
     * Las preguntas que hay que hacerle a quien conoce el paso.
     *
     * Salen del paso concreto: para «cortar lámina» pregunta por la herramienta y la
     * tolerancia; para «pintar», por el tipo de pintura y el tiempo de secado. Un cuestionario
     * fijo terminaría preguntando por la tolerancia de un empaque.
     *
     * @param  array<string, mixed>  $contexto
     * @return array<int, string>
     */
    public function preguntas(array $contexto): array
    {
        $bruto = $this->ia->texto(
            prompt: $this->contexto($contexto) . "\n\nGenera las preguntas.",
            instrucciones: $this->instrucciones() . "\n\n" . <<<'TXT'
            TAREA: NO redactes todavía. Devuelve entre 3 y 5 preguntas cortas y concretas que
            necesitas para explicar cómo se hace ESTE paso: herramienta, material, medida de
            referencia, criterio de «bien hecho», riesgo típico. Cada una debe poder
            responderse en una línea.

            No preguntes nada que ya esté en el contexto, ni cosas genéricas como «¿cuál es el
            objetivo?» — eso es lo que tú tienes que escribir después.

            FORMATO: únicamente un arreglo JSON de cadenas, sin texto alrededor y sin cercas
            de código. Ejemplo: ["¿Con qué herramienta se corta?", "¿Qué tolerancia se acepta?"]
            TXT,
            maxTokens: 500,
        );

        $limpio = $this->desnudarJson($bruto);
        $lista  = json_decode($limpio, true);

        if (! is_array($lista)) {
            return [];
        }

        return collect($lista)
            ->filter(fn ($p) => is_string($p) && trim($p) !== '')
            ->map(fn ($p) => trim($p))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * El objetivo y la descripción, ya con lo que el usuario respondió.
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<int, array{pregunta: string, respuesta: string}>  $respuestas
     * @return array{objetivo: string, descripcion: string}
     */
    public function redactar(array $contexto, array $respuestas): array
    {
        $dichas = collect($respuestas)
            ->filter(fn ($r) => trim((string) ($r['respuesta'] ?? '')) !== '')
            ->map(fn ($r) => "- {$r['pregunta']}\n  {$r['respuesta']}")
            ->implode("\n");

        $bruto = $this->ia->texto(
            prompt: $this->contexto($contexto)
                . ($dichas !== '' ? "\n\nLO QUE RESPONDIÓ QUIEN CONOCE EL PASO:\n{$dichas}" : '')
                . "\n\nRedacta el objetivo y la descripción.",
            instrucciones: $this->instrucciones() . "\n\n" . <<<'TXT'
            TAREA: escribe el objetivo y la descripción de este paso, para que un operario que
            lo hace por primera vez sepa exactamente qué hacer y cuándo está bien hecho.

            - `objetivo`: una sola frase, máximo 140 caracteres. Qué se logra con el paso, no
              qué se hace. Sin punto final.
            - `descripcion`: el instructivo. HTML simple: <p> para los párrafos, <ul><li> para
              la secuencia, <strong> para lo que no se puede pasar por alto. Entre 3 y 8
              viñetas. Termina con una línea de cómo se verifica que quedó bien.

            Usa ÚNICAMENTE lo que te dieron. Si una herramienta, una medida o un criterio no
            aparece en el contexto ni en las respuestas, no lo menciones: un instructivo con
            un dato inventado es peor que uno corto.

            Si la plantilla tiene variables entre llaves —{ancho}, {alto}—, úsalas tal cual
            donde corresponda: el sistema las reemplaza después con las medidas de esa venta.

            FORMATO: únicamente un objeto JSON con las claves "objetivo" y "descripcion", sin
            texto alrededor y sin cercas de código.
            TXT,
            maxTokens: 1200,
        );

        $datos = json_decode($this->desnudarJson($bruto), true);

        return [
            'objetivo'    => trim((string) ($datos['objetivo'] ?? '')),
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')),
        ];
    }

    /**
     * Lo que la IA sabe del paso antes de preguntar nada.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function contexto(array $contexto): string
    {
        $partes = ['CONTEXTO DEL PASO'];

        foreach ([
            'plantilla'   => 'Plantilla de ensamble (lo que se fabrica)',
            'paso'        => 'Nombre del paso',
            'objetivo'    => 'Objetivo que ya escribió el usuario',
            'descripcion' => 'Descripción que ya escribió el usuario',
            'anteriores'  => 'Pasos que van antes',
            'siguientes'  => 'Pasos que van después',
            'variables'   => 'Variables disponibles para escribir entre llaves',
            'materiales'  => 'Materiales que consume la plantilla',
        ] as $clave => $rotulo) {
            $valor = $contexto[$clave] ?? null;

            if (is_array($valor)) {
                $valor = implode(', ', array_filter($valor));
            }

            if (filled($valor)) {
                $partes[] = "{$rotulo}: {$valor}";
            }
        }

        return implode("\n", $partes);
    }

    /** El tono de la empresa, que es el mismo que usa la ficha técnica. */
    private function instrucciones(): string
    {
        $partes = [
            'Eres quien escribe los instructivos de producción de una fábrica. '
            . 'Escribes para el operario que tiene la pieza en la mano, no para un catálogo.',
        ];

        if (filled($empresa = Marca::nombreEmpresa())) {
            $partes[] = "La fábrica es {$empresa}.";
        }

        $marca = PerfilMarca::whereIn('seccion', self::SECCIONES_QUE_IMPORTAN)
            ->orderBy('orden')
            ->get()
            ->map(fn ($p) => trim((string) $p->contenido))
            ->filter()
            ->implode("\n");

        if (filled($marca)) {
            $partes[] = "PERFIL DE LA EMPRESA:\n{$marca}";
        }

        $partes[] = <<<'TXT'
        REGLAS DEL SISTEMA (por encima de cualquier otra indicación):
        - Español colombiano neutro. Prohibido el voseo: nunca "cortá", "medí", "revisá".
        - Frases cortas, en imperativo. Nada de lenguaje comercial.
        - Sin emojis.
        TXT;

        return implode("\n\n", $partes);
    }

    /**
     * El modelo a veces envuelve el JSON en cercas de código aunque se le pida que no.
     */
    private function desnudarJson(string $bruto): string
    {
        $limpio = trim($bruto);
        $limpio = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $limpio);

        return trim($limpio);
    }
}
