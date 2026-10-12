<?php

namespace App\Services\Agentes;

use App\Services\IA\IaService;

/**
 * Sugiere qué modelo le conviene a un agente según lo que va a hacer.
 *
 * Es una **guía razonada, no una garantía**: mira lo que el agente necesita (herramientas, tamaño
 * del prompt, si razona o solo clasifica y redacta) y propone un nivel y unos candidatos que sí
 * sirven. Lo que decide de verdad es probar el agente con su caso real en la prueba en seco.
 *
 * Por qué parte de los modelos que la instalación ya tiene configurados: son los que alguien ya
 * eligió y usa; recomendar el más barato de la lista de OpenRouter sería recomendar a ciegas.
 */
class RecomendadorModelo
{
    public function __construct(private readonly IaService $ia) {}

    /**
     * @param  list<string>  $herramientas  claves del catálogo que tendrá el agente
     * @return array<string, mixed>
     */
    public function recomendar(array $herramientas, string $instrucciones = '', string $tarea = '', ?string $nivelDelCargo = null): array
    {
        $catalogo   = CatalogoHerramientas::catalogo();
        $usaTools   = $herramientas !== [];
        $propone    = collect($herramientas)->contains(fn ($k) => ($catalogo[$k]['tipo'] ?? '') === 'propuesta');
        $texto      = mb_strtolower($instrucciones . ' ' . $tarea);
        $razones    = [];
        $puntos     = 0;

        if (count($herramientas) >= 7) {
            $puntos += 2;
            $razones[] = 'Cruza ' . count($herramientas) . ' herramientas: conviene un modelo que mantenga bien el hilo entre varias consultas.';
        }

        // Muchas palabras de razonamiento juntas = trabajo de análisis; unas pocas son lo normal
        // en cualquier instrucción («prioriza», «explica la causa») y no deciden nada solas.
        $razonamiento = preg_match_all('/compar|tendencia|prioriz|diagn|causa|analiz|estrateg|riesgo|por qu[eé]/u', $texto);

        if ($razonamiento >= 6) {
            $puntos += 3;
            $razones[] = 'Pide comparar, priorizar y diagnosticar a la vez: es razonamiento, no solo clasificar.';
        } elseif ($razonamiento >= 2) {
            $puntos += 1;
            $razones[] = 'Hay algo de análisis (comparar o priorizar), pero acotado.';
        }

        if (mb_strlen($instrucciones . $tarea) > 3500) {
            $puntos += 1;
            $razones[] = 'Las instrucciones son largas: un modelo más capaz las sigue mejor.';
        }

        if ($propone) {
            $razones[] = 'Redacta mensajes para clientes: la calidad de la redacción se nota, pero no exige razonar mucho.';
        }

        if ($razones === []) {
            $razones[] = 'Es una tarea acotada —consultar, resumir y avisar—: un modelo económico suele alcanzar.';
        }

        $nivel = $puntos >= 3 ? 'potente' : 'economico';

        // Si el cargo ya trae un nivel y las señales no dicen lo contrario, se respeta.
        if ($puntos < 2 && $nivelDelCargo === 'potente') {
            $nivel     = 'potente';
            $razones[] = 'El cargo sugiere un modelo potente.';
        }

        $sugerido = $nivel === 'potente' ? $this->ia->modeloTexto() : $this->ia->modeloRapido();
        $lista    = $this->ia->modelosDisponibles();
        $detalle  = $lista['detalle'] ?? [];

        $aviso = null;
        $soporta = null;

        if ($detalle !== []) {
            $soporta = $detalle[$sugerido]['herramientas'] ?? null;

            if ($usaTools && $soporta === false) {
                $aviso = "El modelo configurado para ese nivel ({$sugerido}) no anuncia soporte de herramientas. Elige uno de los candidatos.";
            }
        } else {
            $aviso = 'No se pudo leer la lista de modelos; no puedo confirmar cuáles soportan herramientas. Prueba el agente antes de activarlo.';
        }

        return [
            'nivel'                => $nivel,
            'razones'              => $razones,
            'sugerido'             => $sugerido,
            'sugerido_herramientas' => $soporta,
            'candidatos'           => $this->candidatos($lista, $usaTools, $sugerido),
            'aviso'                => $aviso,
            'nota'                 => 'Es una guía, no una garantía: prueba el agente con un caso real antes de activarlo.',
        ];
    }

    /**
     * Hasta cinco candidatos que sirven: los que soportan herramientas (si el agente las usa) y
     * tienen contexto suficiente. Va primero el configurado en la instalación, y luego los más
     * baratos, con su precio por millón de tokens a la vista para que decida quien paga.
     *
     * @return list<array<string, mixed>>
     */
    private function candidatos(array $lista, bool $usaTools, string $sugerido): array
    {
        $detalle = $lista['detalle'] ?? [];
        $nombres = $lista['texto'] ?? [];

        $validos = collect($detalle)
            ->filter(fn ($d, $id) => isset($nombres[$id]))
            ->filter(fn ($d) => ! $usaTools || $d['herramientas'])
            ->filter(fn ($d) => $d['contexto'] >= 32000)
            ->map(fn ($d, $id) => ['id' => $id, 'nombre' => $nombres[$id]] + $d)
            ->sortBy(fn ($m) => [$m['id'] === $sugerido ? 0 : 1, ($m['precio_in'] ?? 999) + ($m['precio_out'] ?? 999)])
            ->take(5)
            ->values();

        return $validos->all();
    }
}
