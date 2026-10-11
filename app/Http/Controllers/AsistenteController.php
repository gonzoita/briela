<?php

namespace App\Http\Controllers;

use App\Exceptions\IaException;
use App\Models\Configuracion;
use App\Models\MensajeAsistente;
use App\Models\PerfilMarca;
use App\Services\IA\AccionesIaService;
use App\Services\IA\ConsultasDatosService;
use App\Services\IA\IaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Marca;

class AsistenteController extends Controller
{
    /** Tiempos de cada etapa, para poder diagnosticar la lentitud sin adivinar. */
    private int $msDecision = 0;
    private int $msConsultas = 0;
    private int $msRedaccion = 0;

    /** La transacción que la guía propuso en esta respuesta, si propuso alguna: va a la tarjeta de confirmar. */
    private ?array $propuesta = null;

    /** El informe que la guía generó en esta respuesta: va a la tarjeta con las descargas. */
    private ?array $informe = null;
    private ?int $informeId = null;

    public function __construct(
        private IaService $ia,
        private ConsultasDatosService $consultas,
        private AccionesIaService $acciones,
        private \App\Services\Informes\MotorInformes $motor,
    ) {
    }

    public function index()
    {
        return Inertia::render('Asistente/Index', [
            'nombre'        => Configuracion::get('ia_asistente_nombre', 'Asistente'),
            'tienePerfil'   => PerfilMarca::whereNotNull('contenido')->exists(),
            'iaConfigurada' => $this->ia->configurado(),
            // Para mostrarle al usuario sobre qué puede preguntar.
            'temas'         => array_keys($this->consultas->disponibles()),
            'puedeCotizar'  => array_key_exists('crear_cotizacion', $this->acciones->disponibles()),
        ]);
    }

    /**
     * Devuelve el audio de un texto, con la voz natural configurada.
     *
     * Si falla, el frontend cae solo a la voz del navegador: nunca se queda
     * mudo por un problema del servicio de voz.
     */
    public function voz(Request $request)
    {
        $data = $request->validate([
            'texto' => 'required|string|max:4000',
            'voz'   => 'nullable|string|max:50',
        ]);

        try {
            $audio = $this->ia->voz($data['texto'], $data['voz'] ?? null);
        } catch (IaException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response($audio, 200, [
            'Content-Type'  => $this->ia->mimeVoz(),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Responde una pregunta usando el perfil de marca y los datos del sistema.
     *
     * Funciona en dos pasos:
     *   1. Se le muestra a la IA el catálogo de consultas disponibles y decide
     *      si necesita datos y cuál consulta usar.
     *   2. El sistema ejecuta esa consulta (código PHP, no SQL de la IA) y la
     *      IA redacta la respuesta con las cifras reales.
     *
     * La IA nunca toca la base de datos: solo elige del catálogo, y ese
     * catálogo ya viene filtrado por los permisos y la sede del usuario.
     */
    public function preguntar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mensaje' => 'required|string|max:2000',
        ]);

        $userId = auth()->id();
        $nombre = Configuracion::get('ia_asistente_nombre', 'Asistente');

        // El contexto sale de la base y no de lo que mande el navegador: así
        // la conversación sigue igual aunque se recargue la página, y de paso
        // nadie puede inventarse un historial falso desde el cliente.
        $conversacion = MensajeAsistente::historial($userId, MensajeAsistente::CONTEXTO)
            ->map(fn ($m) => ($m['rol'] === 'usuario' ? 'Usuario' : $nombre) . ': ' . $m['contenido'])
            ->implode("\n");

        $inicioTotal = microtime(true);

        try {
            $datos = $this->resolverDatos($data['mensaje'], $conversacion);
            $texto = $this->redactarRespuesta($data['mensaje'], $conversacion, $datos, $nombre);
        } catch (IaException $e) {
            // La pregunta se guarda igual: si falla la IA, al recargar se ve
            // lo que se preguntó en vez de perderse.
            MensajeAsistente::registrar($userId, 'usuario', $data['mensaje']);

            return response()->json(['error' => $e->getMessage()], 422);
        }

        $consulta = empty($datos) ? null : implode(', ', array_keys($datos));

        MensajeAsistente::registrar($userId, 'usuario', $data['mensaje']);
        MensajeAsistente::registrar($userId, 'asistente', $texto, $consulta, $this->informe);
        $this->guardarAnalisis($texto);

        return response()->json([
            'respuesta' => $texto,
            'propuesta' => $this->propuesta,
            'informe'   => $this->informe,
            // Se le dice al usuario de dónde salieron las cifras, para que
            // pueda verificarlas en la pantalla correspondiente.
            'consulta'  => $consulta,
            // Desglose del tiempo. Sin esto, "se demora" es una sensación y no
            // se sabe si la culpa es del modelo que decide, de las consultas a
            // la base o del que redacta.
            'tiempos'   => [
                'decision'  => $this->msDecision,
                'consultas' => $this->msConsultas,
                'redaccion' => $this->msRedaccion,
                'total'     => (int) ((microtime(true) - $inicioTotal) * 1000),
            ],
        ]);
    }

    /**
     * La persona tocó «Confirmar» en la tarjeta de una transacción propuesta por la guía.
     *
     * Se vuelven a revisar el permiso, la sede y el estado: entre la propuesta y el clic pudo
     * cambiar algo, y los parámetros llegan del navegador. El resultado queda en la conversación.
     */
    public function confirmar(Request $request, \App\Services\IA\TransaccionesIaService $transacciones): JsonResponse
    {
        $data = $request->validate([
            'accion'     => 'required|string|max:60',
            'parametros' => 'nullable|array',
        ]);

        $resultado = $transacciones->ejecutar($data['accion'], $data['parametros'] ?? []);

        MensajeAsistente::registrar(auth()->id(), 'asistente', ($resultado['exito'] ? '✓ ' : '✗ ') . $resultado['mensaje'], 'transaccion:' . $data['accion']);

        return response()->json($resultado, $resultado['exito'] ? 200 : 422);
    }

    /** Historial guardado del usuario, para pintar el chat al abrirlo. */
    public function historial(): JsonResponse
    {
        return response()->json([
            'mensajes' => MensajeAsistente::historial(auth()->id(), 30),
        ]);
    }

    /** Borra la conversación del usuario. */
    public function limpiarHistorial(): JsonResponse
    {
        MensajeAsistente::where('user_id', auth()->id())->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Paso 1: ¿necesita datos? ¿cuáles?
     *
     * Puede devolver varias consultas: una pregunta como "cómo va producción y
     * qué cotizaciones hay" necesita dos.
     *
     * @return array<string, array> resultados por nombre de consulta
     */
    private function resolverDatos(string $pregunta, string $conversacion): array
    {
        $disponibles = $this->consultas->disponibles();

        if (empty($disponibles)) {
            return [];
        }

        $describir = fn ($lista) => collect($lista)
            ->map(function ($cfg, $clave) {
                $params = empty($cfg['parametros'])
                    ? 'sin parámetros'
                    : collect($cfg['parametros'])->map(fn ($d, $p) => "{$p} ({$d})")->implode(', ');

                return "- {$clave}: {$cfg['descripcion']} | Parámetros: {$params}";
            })
            ->implode("\n");

        $catalogo = $describir($disponibles);

        $acciones = $this->acciones->disponibles();
        $catalogoAcciones = empty($acciones)
            ? ''
            : "\n\nAcciones que puedes EJECUTAR (hacen cosas en el sistema):\n" . $describir($acciones)
              . "\n\nUsa una acción SOLO si el usuario claramente pide hacerla "
              . "(\"hazme una cotización\", \"remisiona la OP 12\", \"registra el pago\", \"marca el corte como hecho\"). "
              . "Nunca por iniciativa propia. Si falta un dato que el usuario sí dio antes en la conversación, tómalo de ahí.";

        $catalogoInformes = $this->catalogoInformes();

        $instrucciones = <<<TXT
        Tu tarea es decidir si una pregunta necesita datos del sistema y cuál consulta usar.

        Consultas disponibles (solo leen):
        {$catalogo}{$catalogoAcciones}{$catalogoInformes}

        Responde ÚNICAMENTE con un objeto JSON válido, sin explicaciones ni bloques de código.

        Formato: {"consultas": [{"consulta": "nombre_exacto", "parametros": {"dias": 30}}],
                  "accion": {"accion": "nombre_exacto", "parametros": {}},
                  "informe": {"fuente": "...", "periodo": "...", "columnas": [], "agrupar_por": [], "filtros": {}, "nombre": "..."}}

        - Si la pregunta toca VARIOS temas, incluye una consulta por cada tema.
          Ejemplo: "cómo va la producción y qué cotizaciones hay" necesita DOS consultas.
        - Si no necesita datos (saludos, preguntas sobre la marca o la empresa):
          responde {"consultas": []}
        - "accion" solo si el usuario pide explícitamente ejecutarla. Si no, omítela.
        - "informe" solo si piden un informe, un listado, un análisis con detalle, una tabla o un archivo
          (Excel, CSV). Si no, omítelo.

        Usa los nombres exactos de la lista. Máximo 3 consultas.
        TXT;

        // Para DECIDIR qué consultar basta el final de la conversación: solo
        // hace falta entender a qué se refiere un "¿y en Cali?". Mandar el
        // historial completo aquí hacía el prompt más largo sin cambiar la
        // decisión, y cada token de entrada también cuesta tiempo.
        $contextoCorto = $conversacion === ''
            ? ''
            : implode("\n", array_slice(explode("\n", $conversacion), -4));

        $prompt = ($contextoCorto !== '' ? "Conversación previa:\n{$contextoCorto}\n\n" : '')
            . "Pregunta: {$pregunta}";

        // Este paso solo elige una consulta de una lista: no necesita el modelo
        // bueno, necesita el rápido.
        // Un plan de informe pide más espacio que elegir una consulta.
        $respuesta = $this->ia->texto($prompt, $instrucciones, maxTokens: $catalogoInformes !== '' ? 700 : 300, rapido: true);

        $this->msDecision = $this->ia->ultimaDuracionMs;

        $limpio   = trim(preg_replace('/^```(?:json)?|```$/m', '', $respuesta));
        $decidido = json_decode($limpio, true);

        // Se acepta también el formato viejo de una sola consulta, por si el
        // modelo responde así.
        $pedidas = $decidido['consultas'] ?? null;

        if (! is_array($pedidas)) {
            $pedidas = isset($decidido['consulta']) && $decidido['consulta']
                ? [['consulta' => $decidido['consulta'], 'parametros' => $decidido['parametros'] ?? []]]
                : [];
        }

        $resultados     = [];
        $inicioConsultas = microtime(true);

        // Acción solicitada (crear una cotización, por ejemplo). Se ejecuta
        // primero para que la respuesta pueda contar el resultado.
        $accion = $decidido['accion'] ?? null;

        if (is_array($accion) && ! empty($accion['accion'])) {
            $hecho = $this->acciones->ejecutar($accion['accion'], $accion['parametros'] ?? []);

            if ($hecho !== null) {
                $resultados[$accion['accion']] = $hecho;

                // Una transacción no se hizo: se propone, y la pantalla muestra el botón.
                if (! empty($hecho['propuesta'])) {
                    $this->propuesta = $hecho['propuesta'];
                }
            }
        }

        // Un informe: el servidor lo calcula y lo guarda; a la redacción solo le llegan los totales.
        $plan = $decidido['informe'] ?? null;

        if (is_array($plan) && ! empty($plan['fuente']) && $catalogoInformes !== '') {
            $resultados['informe'] = $this->generarInforme($plan, $pregunta);
        }

        foreach (array_slice($pedidas, 0, 3) as $pedida) {
            $nombre = is_array($pedida) ? ($pedida['consulta'] ?? null) : $pedida;

            if (! $nombre) {
                continue;
            }

            $resultado = $this->consultas->ejecutar($nombre, $pedida['parametros'] ?? []);

            if ($resultado !== null) {
                $resultados[$nombre] = $resultado;
            }
        }

        $this->msConsultas = (int) ((microtime(true) - $inicioConsultas) * 1000);

        return $resultados;
    }

    /**
     * Paso 2: redactar la respuesta con el perfil de marca y, si los hay, los
     * datos reales.
     */
    private function redactarRespuesta(
        string $pregunta,
        string $conversacion,
        array $datos,
        string $nombre,
        ?callable $alRecibir = null,
    ): string
    {
        $rol          = Configuracion::get('ia_asistente_rol', '');
        $personalidad = Configuracion::get('ia_asistente_personalidad', '');
        $perfil       = PerfilMarca::comoContexto();

        // Nombre de pila de quien pregunta, para que lo trate como una persona
        // y no como "usuario".
        $usuario   = trim(explode(' ', (string) auth()->user()?->name)[0] ?? '');
        $esInicio  = $conversacion === '';

        $empresa = Marca::nombreEmpresa();

        $instrucciones = "Te llamas {$nombre}. Eres parte del equipo de {$empresa} "
            . "y trabajas dentro del sistema de gestión de la empresa.\n\n";

        $instrucciones .= <<<TXT
        Quién eres y cómo te comportas:
        - Hablas como una persona, no como un manual. Natural, con frases normales.
        - Te llamas {$nombre} y respondes a ese nombre. Si te llaman "{$nombre}" al inicio de
          una frase, es a ti: sigue la conversación con naturalidad, sin repetir tu nombre
          en cada respuesta.
        - Si te preguntan quién eres, te presentas por tu nombre y dices en una frase en qué
          puedes ayudar.
        - La persona con la que hablas se llama {$usuario}. Trátala por su nombre de pila de
          vez en cuando, cuando quede natural. No en cada frase.
        - {$usuario} es SIEMPRE tu interlocutor, pase lo que pase en la conversación. Los
          nombres que aparezcan mientras hablan (clientes, contactos, proveedores) son DATOS,
          no la identidad de quien te escribe. Si te mencionan a "Diego González" como
          cliente, no empieces a llamar Diego a {$usuario}.
        - Nunca digas que eres un modelo de lenguaje ni una inteligencia artificial genérica:
          eres {$nombre}, la asistente del sistema de {$empresa}.
        - Evita las muletillas de robot: nada de "¡Claro!", "Por supuesto", "Aquí tienes",
          "Espero que esto te ayude". Ve al punto.
        - Si algo va mal, está atrasado o falta información, lo dices directo, como lo haría
          una colega de confianza. Sin adornar.
        - Puedes tener opinión sobre lo que ves en los datos, siempre separándola de las
          cifras: primero el dato, después tu lectura.

        TXT;

        if ($esInicio) {
            $instrucciones .= "Es el primer mensaje de la conversación: salúdala brevemente por su nombre antes de responder.\n\n";
        }

        if ($rol !== '') {
            $instrucciones .= "Tu rol: {$rol}\n\n";
        }

        if ($personalidad !== '') {
            $instrucciones .= "Tu personalidad: {$personalidad}\n\n";
        }

        $instrucciones .= <<<'TXT'
        Reglas que no se rompen:
        - Español colombiano neutro. NUNCA uses voseo: se dice "dime" (no "decime"),
          "cópialo" (no "copialo"), "mira" (no "mirá"), "toma" (no "tomá"), "tienes" (no
          "tenés"). Revisa cada verbo en imperativo antes de mandarlo.
        - NO INVENTES CIFRAS. Usa únicamente los números que vengan en la sección de datos.
          Si no hay datos, dilo con claridad en vez de estimar.
        - NO INVENTES MENSAJES DE ERROR NI COMPORTAMIENTOS DEL SISTEMA. Nunca escribas
          'el error que arrojó fue "..."' si ese texto no vino literalmente en el resultado
          de la acción. Nunca afirmes cómo busca el sistema por dentro (si distingue
          mayúsculas, si exige coincidencia exacta, en qué campos mira): no lo sabes. Si una
          acción falló, repite EXACTAMENTE lo que dice su campo "falta" y nada más.
        - No prometas búsquedas que no puedes hacer. Solo puedes pedir los datos que la
          acción acepta como parámetros; no ofrezcas buscar por criterios que no están ahí.
        - Si los datos vienen vacíos o en cero, dilo tal cual; no maquilles el resultado.
        - Menciona el alcance (sede o todas las sedes) cuando des cifras, para que no haya
          confusión sobre a qué corresponden.
        - Los montos están en pesos colombianos. Formatea con separador de miles.
        - Sé breve y concreto. Sin emojis.
        - Si ejecutaste una acción y trae "exito": false, NO digas que la hiciste. Explica
          qué falta usando el texto de "falta" y pide lo que se necesita.
        - Cuando pediste un dato que faltaba y la persona te responde con ese dato (por
          ejemplo el nombre del cliente), VUELVE A EJECUTAR LA MISMA ACCIÓN con el dato
          nuevo. No cambies a otra herramienta ni te quedes conversando: la respuesta corta
          es la que completa lo que ya estabas haciendo.
        - No repitas la misma pregunta más de dos veces. Si a la segunda sigue fallando,
          di textualmente qué devolvió el sistema y sugiere revisarlo en el módulo
          correspondiente, en vez de seguir pidiendo el dato de otra forma.
        - Si una acción salió bien, di qué quedó creado, su número y que está EN BORRADOR
          pendiente de revisión. Si trae "sin_precio", avisa que esos productos quedaron
          sin precio y hay que ponerlo antes de enviar.

        Formato de la respuesta:
        - Para preguntas simples o conversación: una o dos frases, sin títulos ni viñetas.
          Habla, no formatees.
        - Solo cuando te pidan un informe o haya varias cifras que mostrar, usa estructura:
          **negrita** para títulos y cifras clave, viñetas con guion para los desgloses.
          No anides viñetas.
        - En un informe: primero la cifra principal, luego el desglose, y al final una
          observación corta si aporta algo.
        TXT;

        if ($perfil !== '') {
            $instrucciones .= "\n\n# Perfil de marca de {$empresa}\n\n{$perfil}";
        }

        $prompt = ($conversacion !== '' ? "Conversación previa:\n{$conversacion}\n\n" : '')
            . "Pregunta del usuario: {$pregunta}";

        if (! empty($datos)) {
            $prompt .= "\n\n# Datos reales del sistema";

            foreach ($datos as $nombre => $resultado) {
                $json = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $prompt .= "\n\n## {$nombre}\n{$json}";
            }
        } else {
            $prompt .= "\n\n(No se consultaron datos del sistema para esta pregunta.)";
        }

        // Un informe generado: la respuesta es el análisis, sobre los totales que llegaron.
        if ($this->informe) {
            $prompt .= "\n\nGENERASTE EL INFORME «{$this->informe['nombre']}» ({$this->informe['total_filas']} filas). "
                .'Tu respuesta es su análisis: primero la cifra principal; luego de dos a cuatro hallazgos con números '
                .'que vengan en "cifras" y "resumenes" (comparando canales, vendedores, meses o lo que haya); después '
                .'recomendaciones concretas y accionables para mejorar, que salgan de esos números; y al final una frase '
                .'diciendo que el detalle completo está en la tarjeta de abajo para descargar en Excel o CSV. '
                .'No muestres el detalle fila por fila ni inventes cifras que no estén.';
        }

        // Una transacción propuesta todavía NO se hizo: la guía no puede decir «listo».
        if ($this->propuesta) {
            $prompt .= "\n\nIMPORTANTE: propusiste esta acción y TODAVÍA NO SE HA HECHO: «{$this->propuesta['resumen']}». "
                . 'Dilo en una o dos frases y pide que toque «Confirmar» en la tarjeta que aparece debajo. '
                . 'No digas que ya quedó hecha.';
        }

        // Con $alRecibir la respuesta se va entregando por pedazos; sin él, de
        // una sola vez. El prompt es idéntico en ambos casos.
        $texto = $alRecibir
            ? $this->ia->textoStream($prompt, $instrucciones, $this->ia->maxTokensRespuesta(), $alRecibir)
            : $this->ia->texto($prompt, $instrucciones, maxTokens: $this->ia->maxTokensRespuesta());

        $this->msRedaccion = $this->ia->ultimaDuracionMs;

        return $texto;
    }

    /**
     * Igual que preguntar(), pero devolviendo la respuesta a medida que se
     * genera (Server-Sent Events).
     *
     * El primer paso —decidir qué consultar y consultarlo— no se puede
     * transmitir: hay que esperarlo. Lo que se transmite es la redacción, que
     * es donde están el 80% de los segundos.
     *
     * Si algo falla, el navegador cae solo al endpoint normal: nunca se queda
     * sin respuesta por culpa del streaming.
     */
    public function preguntarStream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'mensaje' => 'required|string|max:2000',
        ]);

        $userId = auth()->id();
        $nombre = Configuracion::get('ia_asistente_nombre', 'Asistente');

        return response()->stream(function () use ($data, $userId, $nombre) {
            $inicioTotal = microtime(true);

            $enviar = function (string $evento, array $carga) {
                echo "event: {$evento}\n";
                echo 'data: ' . json_encode($carga, JSON_UNESCAPED_UNICODE) . "\n\n";

                // Sin esto el servidor guarda todo y lo manda junto al final,
                // que es exactamente lo que estamos tratando de evitar.
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $conversacion = MensajeAsistente::historial($userId, MensajeAsistente::CONTEXTO)
                    ->map(fn ($m) => ($m['rol'] === 'usuario' ? 'Usuario' : $nombre) . ': ' . $m['contenido'])
                    ->implode("\n");

                $datos    = $this->resolverDatos($data['mensaje'], $conversacion);
                $consulta = empty($datos) ? null : implode(', ', array_keys($datos));

                // Se avisa de una qué se consultó, para que la interfaz pueda
                // mostrar la fuente mientras todavía se está escribiendo.
                $enviar('inicio', ['consulta' => $consulta]);

                $texto = $this->redactarRespuesta(
                    $data['mensaje'],
                    $conversacion,
                    $datos,
                    $nombre,
                    fn (string $trozo) => $enviar('trozo', ['t' => $trozo]),
                );

                MensajeAsistente::registrar($userId, 'usuario', $data['mensaje']);
                MensajeAsistente::registrar($userId, 'asistente', $texto, $consulta, $this->informe);
                $this->guardarAnalisis($texto);

                $enviar('fin', [
                    'respuesta' => $texto,
                    'propuesta' => $this->propuesta,
                    'informe'   => $this->informe,
                    'consulta'  => $consulta,
                    'tiempos'   => [
                        'decision'  => $this->msDecision,
                        'consultas' => $this->msConsultas,
                        'redaccion' => $this->msRedaccion,
                        'total'     => (int) ((microtime(true) - $inicioTotal) * 1000),
                    ],
                ]);
            } catch (\Throwable $e) {
                MensajeAsistente::registrar($userId, 'usuario', $data['mensaje']);
                $enviar('error', ['mensaje' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            // Nginx guarda las respuestas por defecto y anula el streaming.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ─── Informes desde el chat ──────────────────────────────────────────────

    /** El catálogo de informes para la decisión, o vacío si el módulo está apagado o no hay fuentes. */
    private function catalogoInformes(): string
    {
        if (! \App\Support\Modulos::activo('informes')) {
            return '';
        }

        $catalogo = $this->motor->catalogoParaIa(auth()->user());

        return $catalogo === '' ? '' : "\n\nInformes que puedes GENERAR (el servidor arma la tabla completa, "
            ."descargable en Excel y CSV, con resúmenes; tú solo eliges):\n{$catalogo}\n\n"
            .'Para un informe elige la fuente, el periodo que pidieron (si no dicen, ultimos_30), las columnas '
            .'útiles para la pregunta (si dudas, omítelas y salen las de siempre), de una a tres agrupaciones que '
            .'sirvan para analizar (agrupar_por) y un nombre corto.';
    }

    /**
     * Ejecuta el plan de la IA, guarda el informe y prepara la tarjeta. Devuelve lo que ve la
     * redacción: totales y resúmenes, nunca las filas.
     */
    private function generarInforme(array $plan, string $pregunta): array
    {
        $usuario = auth()->user();
        $norm    = $this->motor->normalizarPlan($plan, $usuario);

        if (! $norm) {
            return ['exito' => false, 'falta' => 'Ese informe no está disponible para tu usuario: sale de una pantalla a la que no tienes acceso.'];
        }

        try {
            $resultado = $this->motor->ejecutarConfig($norm['fuente'], $norm['campos'], $norm['filtros'], $norm['agrupar'], $usuario);
        } catch (\Throwable $e) {
            report($e);

            return ['exito' => false, 'falta' => 'No se pudo armar el informe. Intenta con otro periodo o pídelo de otra forma.'];
        }

        $informe = \App\Models\Informe::create([
            'nombre'       => $norm['nombre'],
            'descripcion'  => 'Generado por la guía desde el chat.',
            'fuente'       => $norm['fuente'],
            'campos'       => $norm['campos'],
            'filtros'      => $norm['filtros'],
            'agrupar'      => $norm['agrupar'],
            'tipo_grafica' => 'ninguna',
            'publico'      => false,
            'origen'       => 'ia',
            'pregunta'     => \Illuminate\Support\Str::limit($pregunta, 2000, ''),
            'created_by'   => $usuario->id,
        ]);

        $this->informeId = $informe->id;
        $this->informe   = $this->tarjetaInforme($informe, $resultado);

        return ['exito' => true] + $this->motor->paraIa($resultado);
    }

    /** Lo que muestra la tarjeta del chat: cifras clave, un resumen, unas filas y las descargas. */
    private function tarjetaInforme(\App\Models\Informe $informe, array $r): array
    {
        $resumen = $r['resumenes'][0] ?? null;

        return [
            'id'          => $informe->id,
            'nombre'      => $informe->nombre,
            'fuente'      => $r['fuente_etiqueta'],
            'periodo'     => $r['periodo'],
            'total_filas' => $r['total_filas'],
            'metricas'    => array_slice(array_values(array_filter($r['metricas'], fn ($m) => $m['valor'] !== null)), 0, 6),
            'resumen'     => $resumen ? [
                'titulo'   => $resumen['titulo'],
                'columnas' => array_slice($resumen['columnas'], 0, 4),
                'filas'    => array_slice($resumen['filas'], 0, 6),
            ] : null,
            'columnas'    => array_slice($r['columnas'], 0, 5),
            'muestra'     => array_slice($r['filas'], 0, 5),
            'xlsx'        => \App\Services\Informes\EscritorXlsx::disponible(),
            'urls'        => [
                'xlsx' => "/informes/{$informe->id}/xlsx",
                'csv'  => "/informes/{$informe->id}/csv",
                'ver'  => "/informes/{$informe->id}",
            ],
        ];
    }

    /** El texto de la guía queda guardado como el análisis del informe. */
    private function guardarAnalisis(string $texto): void
    {
        if ($this->informeId) {
            \App\Models\Informe::whereKey($this->informeId)->update(['analisis' => $texto, 'analizado_at' => now()]);
        }
    }
}
