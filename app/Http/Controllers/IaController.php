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

class IaController extends Controller
{
    public function __construct(private IaService $ia)
    {
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

        $instrucciones = <<<'TXT'
        Eres el redactor comercial de Interfrigo SAS, una empresa colombiana que
        fabrica e instala cuartos fríos y puertas refrigeradas.

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
