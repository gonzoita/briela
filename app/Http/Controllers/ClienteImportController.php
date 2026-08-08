<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ContactoCliente;
use App\Models\Sede;
use App\Models\SegmentacionOpcion;
use App\Services\ConsultaNitService;
use App\Support\ContextoSede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importación de clientes desde CSV.
 *
 * Sigue el mismo patrón que la de productos: se descarga una plantilla, se
 * sube el archivo, y el resultado dice cuántos se crearon, cuántos se
 * actualizaron y qué filas fallaron con el motivo.
 *
 * La identificación es la llave: si el número ya existe, ese cliente se
 * actualiza en vez de crearse duplicado.
 */
class ClienteImportController extends Controller
{
    /** Columnas del CSV, en el orden exacto de la plantilla. */
    private const COLUMNAS = [
        'tipo', 'tipo_identificacion', 'numero_identificacion',
        'nombre', 'apellido',
        'email', 'telefono', 'celular',
        'ciudad', 'direccion',
        'sede', 'activo', 'requiere_anticipo',
        // Segmentación: los cuatro campos, no solo dos. `tipos_contacto` es el
        // que decide si alguien es cliente directo, distribuidor, mayorista o
        // prospecto — y faltaba.
        'tipos_contacto', 'industrias', 'proceso_seguimiento', 'fuentes_contacto',
        'notas',
        'contacto_nombre', 'contacto_apellido', 'contacto_cargo',
        'contacto_email', 'contacto_telefono', 'contacto_celular',
    ];

    public function index(): Response
    {
        return Inertia::render('Clientes/Importar', [
            'columnas' => self::COLUMNAS,
            'sedes'    => Sede::where('activa', true)->orderBy('nombre')->pluck('nombre'),
            // Las opciones válidas de segmentación, para que nadie tenga que
            // adivinar qué escribir en esas columnas.
            'segmentacion' => SegmentacionOpcion::orderBy('tipo')->orderBy('orden')
                ->get(['tipo', 'etiqueta'])
                ->groupBy('tipo')
                ->map(fn ($g) => $g->pluck('etiqueta')),
        ]);
    }

    public function plantilla(): HttpResponse
    {
        $filas = [
            self::COLUMNAS,
            [
                'empresa', 'NIT', '901195995',
                'INTERFRIGO SAS', '',
                'contacto@interfrigo.com.co', '6011234567', '3001234567',
                'Bogotá', 'Calle 1 # 2-3',
                'Bogotá', 'Si', 'No',
                'Alimentos,Retail', 'Referido', 'Cliente desde 2018',
                'Renier', 'Domínguez', 'Gerente',
                'renier@interfrigo.com.co', '6011234567', '3009876543',
            ],
            [
                'persona', 'CC', '1094370680',
                'Juan', 'Pérez',
                'juan@correo.com', '', '3151234567',
                'Cali', 'Carrera 5 # 6-7',
                'Cali', 'Si', 'Si',
                '', 'Página web', '',
                '', '', '',
                '', '', '',
            ],
        ];

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM — para que Excel abra los acentos bien
        foreach ($filas as $fila) {
            fputcsv($handle, $fila, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-clientes.csv"',
        ]);
    }

    public function importar(Request $request, ConsultaNitService $nit): JsonResponse
    {
        $request->validate(['archivo' => 'required|file|max:10240']);

        $contenido = file_get_contents($request->file('archivo')->getRealPath());
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        // Excel en español guarda con punto y coma; otras herramientas con coma.
        $delimitador = substr_count($contenido, ';') > substr_count($contenido, ',') ? ';' : ',';

        $lineas = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contenido),
            fn ($l) => trim($l) !== ''
        ));

        if (count($lineas) < 2) {
            return response()->json(['message' => 'El archivo no tiene filas de datos.'], 422);
        }

        $header = array_map(fn ($h) => trim(mb_strtolower($h)), str_getcsv(array_shift($lineas), $delimitador));

        $resultado = [
            'creados'      => 0,
            'actualizados' => 0,
            'errores'      => [],
            'sin_contacto' => [],
            // Cosas que no impidieron importar la fila pero conviene saber:
            // por ejemplo una segmentación escrita con un nombre que no existe.
            'avisos'       => [],
        ];

        // Las sedes se resuelven por nombre una sola vez, no una por fila.
        $sedes = Sede::pluck('id', 'nombre')
            ->mapWithKeys(fn ($id, $nombre) => [mb_strtolower($nombre) => $id]);

        foreach ($lineas as $i => $linea) {
            $valores = str_getcsv($linea, $delimitador);
            $fila    = [];
            foreach ($header as $idx => $col) {
                $fila[$col] = isset($valores[$idx]) ? trim($valores[$idx]) : '';
            }

            $this->procesarFila($fila, $i + 2, $resultado, $sedes, $nit); // fila 1 = encabezado
        }

        return response()->json($resultado);
    }

    private function esSi(?string $valor, bool $default): bool
    {
        if ($valor === null || trim($valor) === '') {
            return $default;
        }

        return in_array(mb_strtolower(trim($valor)), ['si', 'sí', '1', 'true', 'x'], true);
    }

    private function texto(?string $valor, $existente = null)
    {
        return ($valor !== null && trim($valor) !== '') ? trim($valor) : $existente;
    }

    /** "Alimentos, Retail" → ['Alimentos', 'Retail'] */
    private function lista(?string $valor): ?array
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $valor))));
    }

    /**
     * Igual que lista(), pero traduce cada valor al que guarda la base.
     *
     * En pantalla se lee "Cliente directo" y en la base se guarda
     * `cliente_directo`. Quien llena el CSV escribe lo que ve, así que hay que
     * aceptar la etiqueta, el valor interno o cualquier variación de mayúsculas
     * y tildes. Sin esto se guardaba el texto tal cual y la segmentación
     * quedaba rota: el cliente no aparecía en ningún filtro.
     *
     * Lo que no coincide con ninguna opción se descarta y se avisa, en vez de
     * guardar basura silenciosamente.
     */
    private function listaSegmentacion(?string $valor, string $tipo, int $numero, array &$resultado): ?array
    {
        $partes = $this->lista($valor);

        if ($partes === null) {
            return null;
        }

        $opciones = static::$catalogoSegmentacion[$tipo] ??= SegmentacionOpcion::where('tipo', $tipo)
            ->get(['valor', 'etiqueta']);

        $encontrados = [];

        foreach ($partes as $parte) {
            $buscado = $this->normalizar($parte);

            $opcion = $opciones->first(fn ($o) =>
                $this->normalizar($o->valor) === $buscado || $this->normalizar($o->etiqueta) === $buscado
            );

            if ($opcion) {
                $encontrados[] = $opcion->valor;
            } else {
                $resultado['avisos'][] = "Fila {$numero}: «{$parte}» no es una opción válida de {$tipo}; se omitió.";
            }
        }

        return $encontrados ?: null;
    }

    /** Catálogo cacheado por tipo: se consulta una vez, no una por fila. */
    private static array $catalogoSegmentacion = [];

    /** Sin tildes, sin mayúsculas y con guiones bajos como espacios. */
    private function normalizar(?string $texto): string
    {
        $t = mb_strtolower(trim((string) $texto));
        $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);

        return str_replace([' ', '-'], '_', $t);
    }

    private function procesarFila(array $d, int $numero, array &$resultado, $sedes, ConsultaNitService $nit): void
    {
        try {
            DB::transaction(function () use ($d, $numero, &$resultado, $sedes, $nit) {
                $nombre = trim($d['nombre'] ?? '');
                if ($nombre === '') {
                    throw new \RuntimeException('Falta el nombre o razón social (columna obligatoria).');
                }

                $tipo = mb_strtolower(trim($d['tipo'] ?? ''));
                if (! in_array($tipo, ['empresa', 'persona'], true)) {
                    $tipo = 'empresa';
                }

                $tipoId = mb_strtoupper(trim($d['tipo_identificacion'] ?? ''));
                if (! in_array($tipoId, ['CC', 'NIT', 'CE', 'PA', 'RUT'], true)) {
                    $tipoId = $tipo === 'persona' ? 'CC' : 'NIT';
                }

                // El número se guarda sin puntos ni guiones, y el DV aparte,
                // igual que cuando se crea desde el formulario.
                $verificacion = $nit->verificarDv($d['numero_identificacion'] ?? '');
                $base         = $verificacion['base'];

                $cliente = $base !== ''
                    ? Cliente::where('numero_identificacion', $base)->first()
                    : null;
                $esNuevo = ! $cliente;

                // Sede por nombre. Si no viene o no existe, la activa; y si se
                // está viendo "todas", la principal — nunca se queda sin sede.
                $sedeNombre = mb_strtolower(trim($d['sede'] ?? ''));
                $sedeId     = $sedes[$sedeNombre]
                    ?? $cliente?->sede_id
                    ?? ContextoSede::paraGuardar()
                    ?? Sede::where('es_principal', true)->value('id');

                if ($sedeNombre !== '' && ! isset($sedes[$sedeNombre])) {
                    throw new \RuntimeException("La sede \"{$d['sede']}\" no existe. Créala primero o deja la columna vacía.");
                }

                $datos = [
                    'sede_id'               => $sedeId,
                    'tipo'                  => $tipo,
                    'tipo_identificacion'   => $tipoId,
                    'numero_identificacion' => $base !== '' ? $base : null,
                    'digito_verificacion'   => in_array($tipoId, ['NIT', 'RUT'], true) ? $verificacion['dv_calculado'] : null,
                    'nombre'                => $nombre,
                    'apellido'              => $this->texto($d['apellido'] ?? null, $cliente?->apellido),
                    'email'                 => $this->texto($d['email'] ?? null, $cliente?->email),
                    'telefono'              => $this->texto($d['telefono'] ?? null, $cliente?->telefono),
                    'celular'               => $this->texto($d['celular'] ?? null, $cliente?->celular),
                    'ciudad'                => $this->texto($d['ciudad'] ?? null, $cliente?->ciudad),
                    'direccion'             => $this->texto($d['direccion'] ?? null, $cliente?->direccion),
                    'notas'                 => $this->texto($d['notas'] ?? null, $cliente?->notas),
                    'activo'                => $this->esSi($d['activo'] ?? null, $cliente?->activo ?? true),
                    'requiere_anticipo'     => $this->esSi($d['requiere_anticipo'] ?? null, $cliente?->requiere_anticipo ?? false),
                    'tipos_contacto'        => $this->listaSegmentacion($d['tipos_contacto'] ?? null, 'tipo_contacto', $numero, $resultado) ?? $cliente?->tipos_contacto,
                    'industrias'            => $this->listaSegmentacion($d['industrias'] ?? null, 'industria', $numero, $resultado) ?? $cliente?->industrias,
                    'proceso_seguimiento'   => $this->listaSegmentacion($d['proceso_seguimiento'] ?? null, 'proceso_seguimiento', $numero, $resultado) ?? $cliente?->proceso_seguimiento,
                    'fuentes_contacto'      => $this->listaSegmentacion($d['fuentes_contacto'] ?? null, 'fuente_contacto', $numero, $resultado) ?? $cliente?->fuentes_contacto,
                ];

                if ($esNuevo) {
                    $cliente = Cliente::create($datos);
                    $resultado['creados']++;
                } else {
                    $cliente->update($datos);
                    $resultado['actualizados']++;
                }

                $this->sincronizarContacto($cliente, $d, $numero, $resultado);
            });
        } catch (\Throwable $e) {
            $resultado['errores'][] = ['fila' => $numero, 'motivo' => $e->getMessage()];
        }
    }

    /**
     * Crea o actualiza el contacto de la fila.
     *
     * Para una persona natural, el contacto se copia de los datos del cliente,
     * igual que hace el formulario.
     */
    private function sincronizarContacto(Cliente $cliente, array $d, int $numero, array &$resultado): void
    {
        if ($cliente->tipo === 'persona') {
            ContactoCliente::updateOrCreate(
                ['cliente_id' => $cliente->id, 'es_principal' => true],
                [
                    'nombre'   => $cliente->nombre,
                    'apellido' => $cliente->apellido,
                    'email'    => $cliente->email,
                    'telefono' => $cliente->telefono,
                    'celular'  => $cliente->celular,
                ]
            );

            return;
        }

        $nombreContacto = trim($d['contacto_nombre'] ?? '');

        if ($nombreContacto === '') {
            // No se rechaza la fila por esto: es mejor tener el cliente cargado
            // y completarle el contacto después, que perder toda la fila. Pero
            // se avisa, porque una empresa sin contacto no se puede cotizar.
            if ($cliente->contactos()->count() === 0) {
                $resultado['sin_contacto'][] = ['fila' => $numero, 'cliente' => $cliente->nombre];
            }

            return;
        }

        $contacto = ContactoCliente::firstOrNew([
            'cliente_id' => $cliente->id,
            'nombre'     => $nombreContacto,
        ]);

        $contacto->fill([
            'apellido' => $this->texto($d['contacto_apellido'] ?? null, $contacto->apellido),
            'cargo'    => $this->texto($d['contacto_cargo'] ?? null, $contacto->cargo),
            'email'    => $this->texto($d['contacto_email'] ?? null, $contacto->email),
            'telefono' => $this->texto($d['contacto_telefono'] ?? null, $contacto->telefono),
            'celular'  => $this->texto($d['contacto_celular'] ?? null, $contacto->celular),
        ]);

        // La marca de principal solo se decide al crear el contacto. Si se
        // recalculara siempre, al reimportar el archivo el propio contacto
        // principal se vería a sí mismo como "ya hay uno" y perdería la marca.
        if (! $contacto->exists) {
            $contacto->es_principal = $cliente->contactos()->where('es_principal', true)->doesntExist();
        }

        $contacto->save();
    }
}
