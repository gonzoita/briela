<?php

namespace App\Http\Controllers;

use App\Exceptions\IaException;
use App\Models\Archivo;
use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\IA\IaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Support\Marca;

class IaController extends Controller
{
    public function __construct(private IaService $ia)
    {
    }

    /**
     * Redacta la ficha técnica completa: la introducción para la descripción corta y las
     * especificaciones, ventajas, beneficios y componentes para la larga.
     *
     * **Recibe los datos del formulario, no un id.** El redactor simple de al lado exige un
     * ítem ya guardado, y por eso solo existía al editar: justo donde menos se necesita.
     * Así se puede generar la ficha mientras se está creando el producto.
     *
     * Devuelve el texto para que el usuario lo revise; no guarda nada.
     */
    /**
     * El asistente que redacta un paso de producción.
     *
     * Dos pasadas y a propósito: primero pregunta lo que hace falta saber de ESE paso
     * —herramienta, tolerancia, criterio de bien hecho— y solo después redacta. Un
     * instructivo escrito sin preguntar es un texto genérico, y el operario que lo lee ya
     * sabe menos de lo que sabía.
     */
    public function pasoProduccion(Request $request, \App\Services\IA\PasoProduccionIaService $pasos): JsonResponse
    {
        // La piden las pantallas de plantillas y de ensambles, con permisos distintos.
        $permisos = $request->user()->permisos();

        if (array_intersect(['plantillas.crear', 'plantillas.editar', 'ensambles.crear', 'ensambles.editar'], $permisos) === []) {
            return response()->json(['error' => 'No tienes permiso para usar el asistente de pasos.'], 403);
        }

        $datos = $request->validate([
            'accion'                  => 'required|in:preguntar,redactar',
            'paso'                    => 'required|string|max:150',
            'plantilla'               => 'nullable|string|max:200',
            'objetivo'                => 'nullable|string|max:500',
            'descripcion'             => 'nullable|string|max:4000',
            'anteriores'              => 'nullable|array',
            'anteriores.*'            => 'string|max:150',
            'siguientes'              => 'nullable|array',
            'siguientes.*'            => 'string|max:150',
            'variables'               => 'nullable|array',
            'variables.*'             => 'string|max:80',
            'materiales'              => 'nullable|array',
            'materiales.*'            => 'string|max:150',
            'respuestas'              => 'nullable|array',
            'respuestas.*.pregunta'   => 'required_with:respuestas|string|max:300',
            'respuestas.*.respuesta'  => 'nullable|string|max:600',
        ]);

        $contexto = collect($datos)->except(['accion', 'respuestas'])->all();

        try {
            return response()->json(
                $datos['accion'] === 'preguntar'
                    ? ['preguntas' => $pasos->preguntas($contexto)]
                    : $pasos->redactar($contexto, $datos['respuestas'] ?? [])
            );
        } catch (\App\Exceptions\IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function fichaTecnica(Request $request, \App\Services\IA\FichaTecnicaService $fichas): JsonResponse
    {
        // La piden cuatro pantallas con permisos distintos —crear y editar, producto y
        // ensamble—, así que basta con cualquiera de los cuatro. Pero alguno hace falta:
        // cada ficha cuesta tokens.
        $permisos = $request->user()->permisos();
        $puede    = array_intersect(
            ['productos.crear', 'productos.editar', 'ensambles.crear', 'ensambles.editar'],
            $permisos
        );

        if ($puede === []) {
            return response()->json(['error' => 'No tienes permiso para generar fichas.'], 403);
        }

        $datos = $request->validate([
            'tipo'              => 'required|in:producto,servicio,ensamble',
            'nombre'            => 'required|string|max:200',
            'referencia'        => 'nullable|string|max:80',
            'categoria'         => 'nullable|string|max:120',
            'unidad'            => 'nullable|string|max:30',
            'descripcion_corta' => 'nullable|string|max:1000',
            // Lo que aporta el usuario, un campo por bloque de la ficha. Ninguno es
            // obligatorio por separado; que venga alguno se comprueba abajo.
            'aporte_descripcion'     => 'nullable|string|max:4000',
            'aporte_caracteristicas' => 'nullable|string|max:8000',
            'aporte_ventajas'        => 'nullable|string|max:4000',
            'aporte_beneficios'      => 'nullable|string|max:4000',
            'aporte_componentes'     => 'nullable|string|max:4000',
            // Cajón de sastre, por si una pantalla manda todo junto.
            'datos_brutos'      => 'nullable|string|max:12000',
            // Para un ensamble, su receta ya calculada.
            'ensamble_id'       => 'nullable|integer',
        ]);

        // Las medidas y los componentes de un ensamble no se piden escritos a mano: ya están
        // calculados en la base y son datos técnicos de verdad.
        if (filled($datos['ensamble_id'] ?? null)) {
            $ensamble = Ensamble::find($datos['ensamble_id']);

            if ($ensamble) {
                $datos['variables']   = (array) $ensamble->variables;
                $datos['componentes'] = (array) $ensamble->componentes_resultado;
            }
        }

        // Sin ningún dato del producto —ni lo que aporte el usuario ni la receta de un
        // ensamble— lo único que puede hacer el modelo es inventar, que es justo lo que el
        // prompt prohíbe. Vale más decirlo que cobrar una llamada y devolver ficción.
        $fuentes = ['aporte_descripcion', 'aporte_caracteristicas', 'aporte_ventajas',
            'aporte_beneficios', 'aporte_componentes', 'datos_brutos', 'variables', 'componentes'];

        $hayFuente = collect($fuentes)->contains(fn ($campo) => filled($datos[$campo] ?? null));

        if (! $hayFuente) {
            return response()->json([
                'error' => 'Escribe al menos una de las casillas: sin datos del producto, la ficha '
                    .'saldría inventada.',
            ], 422);
        }

        try {
            return response()->json($fichas->generar($datos));
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Redacta la descripción comercial de un producto o ensamble a partir de
     * sus datos técnicos. Devuelve el texto para que el usuario lo revise y
     * decida si lo usa — nunca guarda nada por su cuenta.
     */
    public function descripcion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo'    => 'required|in:producto,ensamble',
            'id'      => 'required|integer',
            'formato' => 'nullable|in:corta,larga',
        ]);

        $formato = $data['formato'] ?? 'corta';

        try {
            $ficha = $data['tipo'] === 'producto'
                ? $this->fichaProducto((int) $data['id'])
                : $this->fichaEnsamble((int) $data['id']);
        } catch (\Throwable) {
            return response()->json(['error' => 'No se encontró el elemento.'], 404);
        }

        // Qué fabrica la empresa no se escribe aquí: sale de su perfil de marca,
        // porque cada instalación es de una empresa distinta.
        $empresa = Marca::nombreEmpresa();

        $instrucciones = <<<TXT
        Eres el redactor comercial de {$empresa}.

        Reglas de redacción:
        - Español colombiano neutro. Nunca uses voseo (nada de "tomá", "mirá").
        - Tono profesional y claro, sin exageraciones publicitarias.
        - No inventes datos técnicos: usa solo los que te den.
        - Si un dato no está, simplemente no lo menciones.
        - No uses emojis ni signos de exclamación.
        TXT;

        // Si hay tono y voz definidos en el perfil de marca, mandan ellos:
        // así no quedan dos voces distintas en el sistema.
        if ($tono = \App\Models\PerfilMarca::tonoVoz()) {
            $instrucciones .= "\n\nTono y voz de la marca (respétalo):\n{$tono}";
        }

        $largo = $formato === 'corta'
            ? 'Escribe UNA sola frase de máximo 160 caracteres, sin punto final.'
            : 'Escribe entre 2 y 4 párrafos cortos, en texto plano y sin títulos.';

        $prompt = "Redacta la descripción comercial de este ítem.\n\n{$ficha}\n\n{$largo}";

        try {
            $texto = $this->ia->texto(
                prompt: $prompt,
                instrucciones: $instrucciones,
                maxTokens: $formato === 'corta' ? 200 : 900,
            );
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['texto' => $texto]);
    }

    /**
     * Genera una imagen y la guarda en Multimedia, para poder reutilizarla en
     * publicaciones de redes, banners o material de capacitación.
     */
    public function imagen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'descripcion' => 'required|string|max:1000',
            'estilo'      => 'nullable|in:fotografico,ilustracion,minimalista,3d',
            'mejorar'     => 'nullable|boolean',
        ]);

        $estilo      = $data['estilo'] ?? 'fotografico';
        $descripcion = $data['descripcion'];

        // Los chats como ChatGPT no le mandan al generador el texto del usuario
        // tal cual: primero lo expanden con detalles de composición, luz y
        // encuadre. Por API eso no pasa, y por eso la misma idea puede dar
        // peor resultado. Aquí se replica ese paso.
        if ($data['mejorar'] ?? true) {
            $descripcion = $this->enriquecerDescripcion($descripcion, $estilo);
        }

        $prompt = $this->promptImagen($descripcion, $estilo);

        try {
            $imagen = $this->ia->imagen($prompt);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Se guarda con la descripción original del usuario, no con el prompt
        // expandido: es lo que él reconoce al buscar el archivo después.
        $archivo = $this->guardarEnMultimedia($imagen, $data['descripcion']);

        return response()->json([
            'archivo_id' => $archivo->id,
            'url'        => $archivo->url,
            'nombre'     => $archivo->nombre_original,
        ]);
    }

    /**
     * Expande una descripción corta en un prompt visual detallado.
     *
     * Si algo falla, se devuelve la descripción original: nunca se deja al
     * usuario sin imagen por culpa de este paso opcional.
     */
    private function enriquecerDescripcion(string $descripcion, string $estilo): string
    {
        $instrucciones = <<<'TXT'
        Conviertes ideas cortas en prompts para un generador de imágenes.

        Reglas:
        - Parte SIEMPRE de la idea del usuario: no la cambies por otra cosa ni agregues
          objetos o escenas que él no pidió.
        - Agrega solo detalles visuales: composición, encuadre, ángulo de cámara,
          tipo de iluminación, materiales, profundidad de campo, ambiente.
        - Contexto: empresa de refrigeración industrial, imagen seria y profesional.
        - No incluyas texto, letras, logotipos ni marcas de agua.
        - Responde en inglés, en un solo párrafo, sin comillas ni explicaciones.
        TXT;

        try {
            $texto = $this->ia->texto(
                "Idea del usuario: {$descripcion}\nEstilo deseado: {$estilo}",
                $instrucciones,
                maxTokens: 400,
            );
        } catch (\Throwable) {
            return $descripcion;
        }

        return $texto !== '' ? $texto : $descripcion;
    }

    /**
     * Arma el prompt de imagen con el estilo elegido y las reglas de marca.
     */
    private function promptImagen(string $descripcion, string $estilo): string
    {
        $estilos = [
            'fotografico' => 'Estilo fotográfico realista, iluminación natural, alta calidad.',
            'ilustracion' => 'Estilo ilustración vectorial plana, formas limpias.',
            'minimalista' => 'Estilo minimalista, mucho espacio en blanco, composición simple.',
            '3d'          => 'Render 3D limpio, materiales realistas, fondo neutro.',
        ];

        return implode(' ', [
            $descripcion . '.',
            $estilos[$estilo] ?? $estilos['fotografico'],
            'Contexto: marca de refrigeración industrial, seria y técnica.',
            'Paleta acorde a una marca industrial seria, con azul marino oscuro como color principal.',
            'Sin texto, sin letras, sin logotipos y sin marcas de agua dentro de la imagen.',
        ]);
    }

    /**
     * Guarda la imagen generada como un archivo más de Multimedia.
     */
    private function guardarEnMultimedia(array $imagen, string $descripcion): Archivo
    {
        $nombreArchivo = Str::uuid() . '.' . $imagen['extension'];
        $ruta          = 'ia/' . $nombreArchivo;

        \Storage::disk('public')->put($ruta, $imagen['contenido']);

        return Archivo::create([
            'nombre_original' => Str::limit('IA - ' . $descripcion, 80, '') . '.' . $imagen['extension'],
            'nombre_archivo'  => $nombreArchivo,
            'ruta'            => \Storage::url($ruta),
            'storage'         => 'local',
            'tipo_mime'       => 'image/' . $imagen['extension'],
            'extension'       => $imagen['extension'],
            'tamano'          => strlen($imagen['contenido']),
            'categoria'       => 'ia',
            'descripcion'     => Str::limit($descripcion, 200),
            'archivable_type' => 'App\Models\User',
            'archivable_id'   => auth()->id(),
            'subido_por'      => auth()->id(),
        ]);
    }

    /**
     * Datos técnicos del producto, en texto, para dárselos a la IA.
     */
    private function fichaProducto(int $id): string
    {
        $p = Producto::with('categoria:id,nombre')->findOrFail($id);

        return $this->comoLista([
            'Nombre'             => $p->nombre,
            'Referencia'         => $p->referencia,
            'Categoría'          => $p->categoria?->nombre,
            'Tipo'               => $p->tipo,
            'Unidad'             => $p->unidad_medida,
            'Descripción actual' => $p->descripcion_corta,
        ]);
    }

    private function fichaEnsamble(int $id): string
    {
        $e = Ensamble::with('plantilla:id,nombre')->findOrFail($id);

        return $this->comoLista([
            'Nombre'             => $e->nombre,
            'Referencia'         => $e->referencia ?? null,
            'Plantilla'          => $e->plantilla?->nombre,
            'Descripción actual' => $e->descripcion_corta ?? null,
        ]);
    }

    /**
     * Convierte los datos en una lista legible, omitiendo los vacíos para no
     * mandarle ruido (ni pagar tokens de más).
     */
    private function comoLista(array $datos): string
    {
        return collect($datos)
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v, $k) => "- {$k}: {$v}")
            ->implode("\n");
    }
}
