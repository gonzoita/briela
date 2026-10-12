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
                // Con serial, el asistente sale por el proxy de Briela y la llave
                // propia se ignora. La pantalla esconde el campo en ese caso: dejarlo
                // visible invita a pegar una credencial que no se va a usar, y hace
                // creer que el consumo se paga por fuera cuando no es así.
                'por_proxy'     => $this->ia->porProxy(),
                'modelo_texto'  => $this->ia->modeloTexto(),
                'modelo_imagen' => $this->ia->modeloImagen(),
                'modelo_rapido' => Configuracion::get('ia_modelo_rapido', ''),
                'priorizar_velocidad' => Configuracion::get('ia_priorizar_velocidad', '1') === '1',
                'max_tokens' => (int) (Configuracion::get('ia_max_tokens', 0) ?: 3000),
            ],
            // El prompt con el que la IA redacta las fichas técnicas de productos y
            // ensambles. Editable porque cada rubro describe distinto: si viviera en el
            // código, cambiar la estructura de la ficha sería parchear la instalación.
            'fichaTecnica' => [
                'prompt'           => app(\App\Services\IA\FichaTecnicaService::class)->prompt(),
                'prompt_fabrica'   => \App\Services\IA\FichaTecnicaService::promptPorOmision(),
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

        // Guardar sin cambiar el texto que dejó la IA no le quita la marca «IA»: solo editarlo.
        $actual = PerfilMarca::where('seccion', $data['seccion'])->first();
        $igual  = $actual && trim((string) $actual->contenido) === trim((string) $data['contenido']);

        PerfilMarca::updateOrCreate(
            ['seccion' => $data['seccion']],
            ['contenido' => $data['contenido'], 'generado_ia_at' => $igual ? $actual->generado_ia_at : null]
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
     * La IA arma el perfil sola: para cada sección formula la pregunta que le haría a ESTA
     * empresa y la responde con lo que el sistema ya sabe de ella —sus datos, lo que vende, a
     * quién le vende, lo que ya está en el perfil—.
     *
     * Por omisión solo llena las secciones vacías: lo que alguien escribió a mano no se pisa. Lo
     * que no se puede sacar de los datos sale como propuesta marcada para revisar, nunca como un
     * hecho inventado. Devuelve las preguntas y respuestas para mostrarlas.
     */
    public function completar(Request $request): JsonResponse
    {
        $data = $request->validate(['reescribir' => 'boolean']);

        $catalogo  = PerfilMarca::catalogo();
        $actuales  = PerfilMarca::pluck('contenido', 'seccion');
        $pendientes = collect($catalogo)
            ->filter(fn ($cfg, $clave) => ($data['reescribir'] ?? false) || blank($actuales[$clave] ?? null));

        if ($pendientes->isEmpty()) {
            return response()->json(['secciones' => 0, 'resultado' => [], 'mensaje' => 'Todas las secciones ya tienen contenido.']);
        }

        $lista = $pendientes->map(fn ($cfg, $clave) => "- {$clave}: {$cfg['label']} ({$cfg['ayuda']})")->implode("\n");

        $instrucciones = <<<TXT
        Eres consultor de marca. Vas a construir el perfil de marca de una empresa tú solo, a
        partir de los datos reales que el sistema tiene de ella.

        Para cada sección pedida:
        1. Formula la pregunta concreta que le harías a ESTA empresa para escribir esa sección
           (no una pregunta genérica: usa lo que sabes de su negocio).
        2. Respóndela tú mismo con base en los datos. Escribe como habla la empresa, en primera
           persona del plural, claro y concreto.

        Reglas:
        - Español colombiano neutro. Nunca uses voseo.
        - No inventes hechos (años, cifras, premios, clientes con nombre). Lo que sí se deduce de
          los datos —qué vende, a quién, dónde— úsalo con confianza.
        - Si una sección no se puede sacar de los datos (por ejemplo, la historia), escribe una
          propuesta razonable para ese tipo de negocio y empiézala con «[Propuesta para revisar]».
        - Sin emojis, sin exageraciones publicitarias, sin signos de exclamación.

        Devuelve ÚNICAMENTE un objeto JSON válido, sin explicaciones ni bloques de código, con
        esta forma: {"clave": {"pregunta": "…", "respuesta": "…"}}. Usa exactamente las claves
        pedidas.
        TXT;

        $prompt = "Secciones que debes llenar:\n{$lista}\n\n"
            ."Lo que el sistema sabe de la empresa:\n".$this->datosDeLaEmpresa()."\n\n"
            .(($contexto = PerfilMarca::comoContexto()) !== ''
                ? "Lo que ya está escrito en el perfil (respétalo y no lo contradigas):\n{$contexto}\n"
                : '');

        try {
            $respuesta = $this->ia->texto($prompt, $instrucciones, maxTokens: 8000);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $limpio    = trim(preg_replace('/^```(?:json)?|```$/m', '', $respuesta));
        $secciones = json_decode($limpio, true);

        if (! is_array($secciones)) {
            return response()->json(['error' => 'La IA no devolvió un resultado que se pueda leer. Intenta otra vez.'], 422);
        }

        $resultado = [];

        foreach ($secciones as $clave => $par) {
            if (! $pendientes->has($clave)) {
                continue;
            }

            $texto = is_array($par) ? ($par['respuesta'] ?? '') : (string) $par;
            if (is_array($texto)) {
                $texto = implode("\n", $texto);
            }
            if (blank($texto)) {
                continue;
            }

            PerfilMarca::updateOrCreate(['seccion' => $clave], ['contenido' => trim($texto), 'generado_ia_at' => now()]);

            $resultado[] = [
                'seccion'   => $clave,
                'label'     => $catalogo[$clave]['label'],
                'pregunta'  => is_array($par) ? ($par['pregunta'] ?? $catalogo[$clave]['pregunta']) : $catalogo[$clave]['pregunta'],
                'respuesta' => trim($texto),
            ];
        }

        return response()->json(['secciones' => count($resultado), 'resultado' => $resultado]);
    }

    /**
     * Lo que el sistema sabe de la empresa, en pocas líneas: la base para que la IA escriba el
     * perfil sin inventar. Solo agregados y nombres —nada de datos de personas—.
     */
    private function datosDeLaEmpresa(): string
    {
        $c = fn (string $k) => trim((string) \App\Models\Configuracion::get($k, ''));

        $lineas = array_filter([
            'Nombre: '.\App\Support\Marca::nombreEmpresa(),
            $c('empresa_ciudad') ? 'Ciudad: '.$c('empresa_ciudad') : null,
            ($web = $c('empresa_web') ?: $c('empresa_sitio_web')) ? "Sitio web: {$web}" : null,
            $c('empresa_horario') ? 'Horario: '.$c('empresa_horario') : null,
        ]);

        $categorias = \App\Models\CategoriaProducto::query()->limit(25)->pluck('nombre')->filter()->implode(', ');
        if ($categorias) {
            $lineas[] = "Categorías de lo que vende: {$categorias}";
        }

        $productos = \App\Models\Producto::where('activo', true)->latest('id')->limit(30)->pluck('nombre')->filter()->implode(', ');
        if ($productos) {
            $lineas[] = "Algunos productos: {$productos}";
        }

        if (class_exists(\App\Models\PlantillaEnsamble::class)) {
            $ensambles = \App\Models\PlantillaEnsamble::query()->limit(20)->pluck('nombre')->filter()->implode(', ');
            if ($ensambles) {
                $lineas[] = "Lo que fabrica a la medida (plantillas): {$ensambles}";
            }
        }

        $clientes = \App\Models\Cliente::count();
        if ($clientes) {
            $empresas = \App\Models\Cliente::where('tipo', 'empresa')->count();
            $ciudades = \App\Models\Cliente::whereNotNull('ciudad')->where('ciudad', '!=', '')
                ->selectRaw('ciudad, COUNT(*) as n')->groupBy('ciudad')->orderByDesc('n')->limit(5)->pluck('ciudad')->implode(', ');
            $lineas[] = "Clientes registrados: {$clientes} ({$empresas} empresas, ".($clientes - $empresas).' personas)'
                .($ciudades ? ". Ciudades principales: {$ciudades}" : '');
        }

        $cotizaciones = \App\Models\Cotizacion::count();
        if ($cotizaciones) {
            $aprobadas = \App\Models\Cotizacion::where('estado', 'aprobada')->orWhere('en_produccion', true)->count();
            $lineas[] = "Cotizaciones hechas: {$cotizaciones}, aprobadas: {$aprobadas}";
        }

        return implode("\n", $lineas);
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
        \Cache::forget('ia_modelos_openrouter_v4');

        return back()->with('success', 'Configuración de IA guardada.');
    }

    /**
     * Guarda el prompt de la ficha técnica.
     *
     * Vacío significa «volver al de fábrica», no «ficha sin instrucciones»: dejar el campo
     * en blanco y quedarse sin prompt daría fichas al azar sin que nadie entienda por qué.
     */
    public function guardarPromptFicha(Request $request, \App\Services\IA\FichaTecnicaService $fichas)
    {
        $data = $request->validate([
            'prompt' => 'nullable|string|max:20000',
        ]);

        $fichas->guardarPrompt($data['prompt'] ?? '');

        return back()->with('success', filled($data['prompt'] ?? '')
            ? 'Prompt de la ficha técnica guardado.'
            : 'Prompt restablecido al de fábrica.');
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
        \Cache::forget('ia_modelos_openrouter_v4');

        return back()->with('success', 'Asistente actualizado.');
    }
}
