<?php

namespace App\Http\Controllers;

use App\Exceptions\IaException;
use App\Models\Configuracion;
use App\Models\PerfilMarca;
use App\Services\IA\IaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Support\Marca;

class PerfilMarcaController extends Controller
{
    public function __construct(private IaService $ia)
    {
    }

    public function index()
    {
        $catalogo  = PerfilMarca::catalogo();
        $guardadas = PerfilMarca::orderBy('orden')->get()->keyBy('seccion');

        // Se listan todas las secciones del catálogo, tenga o no contenido,
        // para que se vea claro qué falta por llenar.
        $secciones = collect($catalogo)->map(fn ($cfg, $clave) => [
            'seccion'        => $clave,
            'label'          => $cfg['label'],
            'ayuda'          => $cfg['ayuda'],
            'pregunta'       => $cfg['pregunta'],
            // ->get() y no [$clave]: en una instalación nueva la tabla está
            // vacía, y el acceso con corchetes lanza "Undefined array key" antes
            // de que el ?? alcance a actuar.
            'contenido'      => $guardadas->get($clave)?->contenido ?? '',
            'generado_ia_at' => $guardadas->get($clave)?->generado_ia_at?->format('d/m/Y'),
        ])->values();

        return Inertia::render('Configuracion/PerfilMarca', [
            'secciones' => $secciones,
            'asistente' => [
                'nombre'       => Configuracion::get('ia_asistente_nombre', 'Asistente'),
                'rol'          => Configuracion::get('ia_asistente_rol', ''),
                'personalidad' => Configuracion::get('ia_asistente_personalidad', ''),
                // La voz es parte de su identidad, como el nombre.
                'voz_natural'  => $this->ia->vozNaturalActiva(),
                'voz'          => $this->ia->nombreVoz(),
                'modelo_voz'   => $this->ia->modeloVoz(),
                'voz_instrucciones' => $this->ia->instruccionesVoz(),
            ],
            // La clave nunca se manda completa al navegador: solo se muestra
            // el final para reconocer cuál está puesta.
            'ia' => [
                'configurada'   => $this->ia->configurado(),
                'clave_parcial' => $this->claveParcial(),
                'modelo_texto'  => $this->ia->modeloTexto(),
                'modelo_imagen' => $this->ia->modeloImagen(),
                'modelo_rapido' => Configuracion::get('ia_modelo_rapido', ''),
                'priorizar_velocidad' => Configuracion::get('ia_priorizar_velocidad', '1') === '1',
                'max_tokens' => (int) (Configuracion::get('ia_max_tokens', 0) ?: 3000),
            ],
            // Voces del catálogo de OpenAI (las que acepta el endpoint de voz).
            'vocesSugeridas' => [
                'nova'    => 'Nova — mujer, cálida y cercana (recomendada)',
                'shimmer' => 'Shimmer — mujer, suave',
                'coral'   => 'Coral — mujer, expresiva',
                'sage'    => 'Sage — mujer, serena',
                'alloy'   => 'Alloy — neutra',
                'echo'    => 'Echo — hombre, grave',
                'onyx'    => 'Onyx — hombre, profunda',
                'fable'   => 'Fable — hombre, narrativa',
            ],
            // Lista real traída de OpenRouter. Si no se pudo cargar (sin
            // credencial o sin conexión), la pantalla cae a escribir a mano.
            'modelosSugeridos' => $this->ia->modelosDisponibles(),
            'rolesSugeridos' => [
                'Analista de datos: interpretas cifras del negocio, señalas tendencias y alertas, y siempre indicas de dónde salen los números.',
                'Asesor comercial: ayudas al equipo de ventas con argumentos, manejo de objeciones y enfoque en el cliente ideal.',
                'Jefe de producción: te enfocas en avance de OPs, cumplimiento de entregas y cuellos de botella.',
                'Asistente administrativo: resuelves dudas del día a día sobre procesos, cartera y compras.',
            ],
        ]);
    }

    /**
     * Guarda el contenido escrito a mano de una sección.
     */
    public function guardar(Request $request)
    {
        $data = $request->validate([
            'seccion'   => 'required|string|max:40',
            'contenido' => 'nullable|string|max:20000',
        ]);

        abort_unless(array_key_exists($data['seccion'], PerfilMarca::catalogo()), 422);

        PerfilMarca::updateOrCreate(
            ['seccion' => $data['seccion']],
            ['contenido' => $data['contenido'], 'generado_ia_at' => null]
        );

        return back()->with('success', 'Sección guardada.');
    }

    /**
     * La IA redacta una sección a partir de lo que el usuario cuenta, usando
     * el resto del perfil como contexto para que no se contradiga.
     *
     * Devuelve el texto propuesto: no lo guarda hasta que el usuario acepte.
     */
    public function generar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seccion'   => 'required|string|max:40',
            'respuesta' => 'required|string|max:5000',
        ]);

        $catalogo = PerfilMarca::catalogo();
        abort_unless(array_key_exists($data['seccion'], $catalogo), 422);

        $cfg      = $catalogo[$data['seccion']];
        $contexto = PerfilMarca::comoContexto();

        $instrucciones = <<<'TXT'
        Eres consultor de marca y estás ayudando a construir el perfil de marca de una empresa.

        Reglas:
        - Español colombiano neutro. Nunca uses voseo.
        - Escribe en primera persona del plural, como habla la empresa.
        - No inventes hechos: usa solo lo que te cuenta el usuario y lo que ya está en el perfil.
        - Si falta información, escribe lo que puedas y no rellenes con suposiciones.
        - Sin emojis, sin exageraciones publicitarias, sin signos de exclamación.
        - Devuelve solo el texto de la sección, sin títulos ni comillas.
        TXT;

        $prompt = "Redacta la sección \"{$cfg['label']}\" del perfil de marca.\n\n"
            . "Lo que cuenta el usuario:\n{$data['respuesta']}\n\n"
            . ($contexto !== ''
                ? "Perfil de marca actual (para mantener coherencia, no lo repitas):\n{$contexto}\n\n"
                : '')
            . 'Escribe la sección de forma clara y concreta.';

        try {
            $texto = $this->ia->texto($prompt, $instrucciones, maxTokens: 1200);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['texto' => $texto]);
    }

    /**
     * Importa un perfil de marca existente: el usuario pega el texto (por
     * ejemplo, de un documento) y la IA lo reparte en las secciones.
     */
    public function importar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'texto' => 'required|string|max:60000',
        ]);

        $claves = implode(', ', array_keys(PerfilMarca::catalogo()));

        $instrucciones = <<<TXT
        Recibes el texto de un documento de perfil de marca y debes repartirlo en secciones.

        Devuelve ÚNICAMENTE un objeto JSON válido, sin explicaciones ni bloques de código.
        Las claves posibles son exactamente estas: {$claves}.
        Incluye solo las claves para las que el texto tenga contenido real.
        No inventes nada: reorganiza y limpia lo que ya está en el texto.
        Conserva el idioma original.
        TXT;

        try {
            $respuesta = $this->ia->texto(
                "Reparte este documento en las secciones del perfil de marca:\n\n{$data['texto']}",
                $instrucciones,
                maxTokens: 8000,
            );
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Por si el modelo devuelve el JSON envuelto en ```json ... ```
        $limpio = trim(preg_replace('/^```(?:json)?|```$/m', '', $respuesta));
        $secciones = json_decode($limpio, true);

        if (! is_array($secciones)) {
            return response()->json([
                'error' => 'La IA no devolvió un resultado que se pueda leer. Intenta con un texto más corto.',
            ], 422);
        }

        $validas = array_keys(PerfilMarca::catalogo());
        $guardadas = 0;

        foreach ($secciones as $clave => $contenido) {
            if (! in_array($clave, $validas, true) || blank($contenido)) {
                continue;
            }

            PerfilMarca::updateOrCreate(
                ['seccion' => $clave],
                ['contenido' => is_array($contenido) ? implode("\n", $contenido) : $contenido,
                 'generado_ia_at' => now()]
            );
            $guardadas++;
        }

        return response()->json(['secciones' => $guardadas]);
    }

    /**
     * Muestra solo los últimos caracteres de la credencial, para reconocerla
     * sin exponerla en el navegador.
     */
    private function claveParcial(): ?string
    {
        $clave = $this->ia->apiKey();

        return $clave === '' ? null : '···' . substr($clave, -6);
    }

    /**
     * Guarda la credencial y los modelos de IA en Ajustes, para no tener que
     * entrar al servidor a editar el .env.
     */
    public function guardarIa(Request $request)
    {
        $data = $request->validate([
            'api_key'       => 'nullable|string|max:200',
            'modelo_texto'  => 'required|string|max:100',
            'modelo_imagen' => 'required|string|max:100',
            'modelo_rapido' => 'nullable|string|max:100',
            'priorizar_velocidad' => 'nullable|boolean',
            'max_tokens' => 'nullable|integer|min:500|max:16000',
        ]);

        // Si el campo llega vacío es que no la quiso cambiar: se conserva.
        if (filled($data['api_key'])) {
            Configuracion::set('ia_api_key', trim($data['api_key']));
        }

        Configuracion::set('ia_modelo_texto', $data['modelo_texto']);
        Configuracion::set('ia_modelo_imagen', $data['modelo_imagen']);
        Configuracion::set('ia_modelo_rapido', $data['modelo_rapido'] ?? '');
        Configuracion::set('ia_priorizar_velocidad', ! empty($data['priorizar_velocidad']) ? '1' : '0');
        Configuracion::set('ia_max_tokens', (string) ($data['max_tokens'] ?? 3000));

        // Si cambió la credencial, la lista de modelos puede ser otra.
        \Cache::forget('ia_modelos_openrouter_v3');

        return back()->with('success', 'Configuración de IA guardada.');
    }

    /**
     * Genera una muestra de audio con la configuración de voz actual, para
     * poder escucharla mientras se ajusta el acento sin salir de la pantalla.
     */
    public function probarVoz(Request $request): JsonResponse
    {
        $request->validate([
            'voz'          => 'nullable|string|max:50',
            'modelo_voz'   => 'nullable|string|max:100',
            'instrucciones'=> 'nullable|string|max:1000',
        ]);

        // Se guarda antes de probar para que la muestra use exactamente lo que
        // se ve en pantalla, aunque todavía no se haya dado a "Guardar".
        if (filled($request->voz))           Configuracion::set('ia_voz', $request->voz);
        if (filled($request->modelo_voz))    Configuracion::set('ia_modelo_voz', $request->modelo_voz);
        Configuracion::set('ia_voz_instrucciones', $request->instrucciones ?? '');

        $nombre = Configuracion::get('ia_asistente_nombre', 'Asistente');

        try {
            $audio = $this->ia->voz(
                "Hola, soy {$nombre}, tu asistente en el sistema de " . Marca::nombreEmpresa() . ". "
                . 'Puedo contarte cómo va la producción, las ventas o el inventario. ¿En qué te ayudo?'
            );
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'audio' => 'data:' . $this->ia->mimeVoz() . ';base64,' . base64_encode($audio),
        ]);
    }

    /**
     * Prueba la credencial con una llamada mínima, para saber si quedó bien
     * puesta sin tener que adivinar.
     */
    public function probarIa(): JsonResponse
    {
        try {
            $this->ia->texto('Responde únicamente con la palabra: listo', maxTokens: 20);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Nombre y personalidad del asistente. Bautizarlo es puramente cosmético
     * pero hace que el equipo lo sienta propio.
     */
    public function guardarAsistente(Request $request)
    {
        $data = $request->validate([
            'nombre'       => 'required|string|max:40',
            'rol'          => 'nullable|string|max:1000',
            'personalidad' => 'nullable|string|max:500',
            'voz_natural'  => 'boolean',
            'voz'          => 'nullable|string|max:50',
            'modelo_voz'   => 'nullable|string|max:100',
            'voz_instrucciones' => 'nullable|string|max:1000',
        ]);

        Configuracion::set('ia_asistente_nombre', $data['nombre']);
        Configuracion::set('ia_asistente_rol', $data['rol'] ?? '');
        Configuracion::set('ia_asistente_personalidad', $data['personalidad'] ?? '');

        // La voz es parte de la identidad del asistente.
        Configuracion::set('ia_voz_natural', ($data['voz_natural'] ?? false) ? '1' : '0');

        if (filled($data['voz'])) {
            Configuracion::set('ia_voz', $data['voz']);
        }

        if (filled($data['modelo_voz'])) {
            Configuracion::set('ia_modelo_voz', $data['modelo_voz']);
        }

        Configuracion::set('ia_voz_instrucciones', $data['voz_instrucciones'] ?? '');

        // La lista de modelos se recarga por si cambió algo en la cuenta.
        \Cache::forget('ia_modelos_openrouter_v3');

        return back()->with('success', 'Asistente actualizado.');
    }
}
