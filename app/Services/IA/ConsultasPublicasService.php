<?php

namespace App\Services\IA;

use App\Models\Configuracion;
use App\Models\Ensamble;
use App\Models\PerfilMarca;
use App\Models\Producto;

/**
 * Lo que puede consultar el agente cuando atiende a alguien que TODAVÍA NO
 * SABEMOS QUIÉN ES: quien escribe por WhatsApp, por el chat de la web, por
 * donde sea, antes de que se le asigne un vendedor.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  REGLA QUE NO SE ROMPE: aquí NO entra ni un solo dato de un cliente.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Es deliberadamente un catálogo aparte de ConsultasDatosService y no una
 * versión recortada de aquel. Aquel filtra por los permisos del usuario
 * interno; un desconocido no tiene usuario ni permisos, así que reutilizarlo
 * sería confiar en que el filtro nunca falle. Con dos catálogos separados, el
 * agente público **no tiene forma** de llegar a los datos internos: no los
 * conoce.
 *
 * Todo lo que hay acá ya es público por otro lado (el catálogo en /catalogo y
 * los datos de contacto de la empresa), así que responderlo por chat no expone
 * nada nuevo.
 */
class ConsultasPublicasService
{
    /**
     * Catálogo del agente público. Sin campo 'permiso' a propósito: no hay
     * permisos que evaluar porque no hay usuario.
     */
    public static function catalogo(): array
    {
        return [
            'empresa' => [
                'descripcion' => 'Quién es la empresa, a qué se dedica y cómo trabaja. Úsala para presentarte o para responder "¿qué hacen?".',
            ],
            'contacto' => [
                'descripcion' => 'Datos de contacto: dirección, teléfono, correo y horario de atención.',
            ],
            'productos' => [
                'descripcion' => 'Qué productos y servicios se ofrecen, con su descripción. Úsala cuando pregunten qué venden o si tienen algo en particular.',
            ],
        ];
    }

    public function disponibles(): array
    {
        return static::catalogo();
    }

    public function ejecutar(string $consulta, array $parametros = []): ?array
    {
        return match ($consulta) {
            'empresa'   => $this->empresa(),
            'contacto'  => $this->contacto(),
            'productos' => $this->productos($parametros['buscar'] ?? null),
            default     => null,
        };
    }

    // ─── Consultas ────────────────────────────────────────────────────────────

    private function empresa(): array
    {
        $secciones = PerfilMarca::whereNotNull('contenido')
            ->where('contenido', '!=', '')
            ->orderBy('orden')
            ->pluck('contenido', 'seccion')
            ->all();

        return [
            'nombre'   => Configuracion::get('empresa_nombre', ''),
            'perfil'   => $secciones,
            'aviso'    => empty($secciones)
                ? 'Todavía no se ha escrito el perfil de la empresa. Responde solo lo que sepas con certeza y ofrece pasar con un asesor.'
                : null,
        ];
    }

    private function contacto(): array
    {
        return [
            'direccion' => Configuracion::get('empresa_direccion', ''),
            'telefono'  => Configuracion::get('empresa_telefono', ''),
            'email'     => Configuracion::get('empresa_email', ''),
            'horario'   => Configuracion::get('empresa_horario', ''),
            'sitio_web' => Configuracion::get('empresa_sitio_web', ''),
        ];
    }

    /**
     * Solo lo vendible y activo — lo mismo que ya se ve en /catalogo.
     * NO se devuelven costos, márgenes ni existencias: eso es interno.
     */
    private function productos(?string $buscar): array
    {
        $productos = Producto::where('activo', true)
            ->where('es_vendible', true)
            ->when($buscar, fn ($q) => $q->where(function ($s) use ($buscar) {
                $s->where('nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion_corta', 'like', "%{$buscar}%");
            }))
            ->orderBy('nombre')
            ->limit(25)
            ->get(['id', 'nombre', 'descripcion_corta', 'unidad_medida'])
            ->map(fn ($p) => [
                'nombre'      => $p->nombre,
                'descripcion' => $p->descripcion_corta,
                'unidad'      => $p->unidad_medida,
                'url'         => url("/catalogo/productos/{$p->id}"),
            ]);

        $ensambles = Ensamble::when($buscar, fn ($q) => $q->where('nombre', 'like', "%{$buscar}%"))
            ->orderBy('nombre')
            ->limit(25)
            ->get(['id', 'nombre', 'descripcion_corta'])
            ->map(fn ($e) => [
                'nombre'      => $e->nombre,
                'descripcion' => $e->descripcion_corta,
                'a_la_medida' => true,
                'url'         => url("/catalogo/ensambles/{$e->id}"),
            ]);

        return [
            'productos' => $productos->all(),
            'a_medida'  => $ensambles->all(),
            'nota'      => 'Los productos a la medida se cotizan según las dimensiones. Para dar un precio hay que pasar con un asesor.',
        ];
    }
}
