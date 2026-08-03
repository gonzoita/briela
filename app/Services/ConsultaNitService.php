<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Configuracion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de identificación de clientes.
 *
 * Tiene tres capas, de la más confiable a la menos:
 *
 *   1. Dígito de verificación: matemática pura, siempre funciona.
 *   2. Duplicados: consulta nuestra propia base, siempre funciona.
 *   3. RUES: fuente externa sin API oficial. Es una conveniencia; si falla,
 *      el formulario debe seguir sirviendo a mano sin estorbar.
 *
 * Por eso la capa 3 nunca lanza excepciones hacia arriba.
 */
class ConsultaNitService
{
    /** Pesos oficiales del DV del NIT, aplicados de derecha a izquierda. */
    private const PESOS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /**
     * Lee un ajuste: manda lo configurado en la app; si está vacío, el .env.
     * Mismo patrón que usa la integración de IA.
     */
    private function ajuste(string $clave, string $configEnv, mixed $default = null): mixed
    {
        $valor = Configuracion::get($clave, null);

        // El portal viejo del RUES fue desmantelado. Si quedó guardada una
        // dirección que apunta allá, se ignora: si no, un valor guardado hace
        // meses dejaría el módulo muerto para siempre.
        if ($clave === 'rues_url' && is_string($valor) && str_contains($valor, 'ruesapi.rues.org.co')) {
            $valor = null;
        }

        return ($valor === null || $valor === '') ? config($configEnv, $default) : $valor;
    }

    /**
     * Deja solo los dígitos. La gente escribe "900.123.456-7", "900123456 7",
     * "NIT 900123456"... todo eso debe entrar igual.
     */
    public function normalizar(string $numero): string
    {
        return preg_replace('/\D/', '', $numero) ?? '';
    }

    /**
     * Separa el número base del dígito de verificación cuando el usuario lo
     * escribió pegado (formato "900123456-7" → base 900123456, dv 7).
     *
     * Ojo: solo separamos si venía un guion. Si escriben 10 dígitos seguidos
     * no podemos adivinar si el último es DV o parte del número.
     */
    public function separarDv(string $numero): array
    {
        $limpio = trim($numero);

        if (preg_match('/^(.+)[-\s]+(\d)$/', $limpio, $m)) {
            return [
                'base' => $this->normalizar($m[1]),
                'dv'   => $m[2],
            ];
        }

        return ['base' => $this->normalizar($limpio), 'dv' => null];
    }

    /**
     * Calcula el dígito de verificación de un NIT.
     * Devuelve null si el número no sirve para calcularlo.
     */
    public function calcularDv(string $base): ?string
    {
        $base = $this->normalizar($base);

        if ($base === '' || strlen($base) > count(self::PESOS)) {
            return null;
        }

        $digitos = array_reverse(str_split($base));
        $suma    = 0;

        foreach ($digitos as $i => $digito) {
            $suma += ((int) $digito) * self::PESOS[$i];
        }

        $residuo = $suma % 11;

        // La regla oficial: con residuo 0 o 1 el DV es el residuo mismo.
        return (string) ($residuo < 2 ? $residuo : 11 - $residuo);
    }

    /**
     * Revisa el número completo y dice qué encontró.
     *
     * @return array{base:string, dv:string|null, dv_calculado:string|null, dv_correcto:bool|null, mensaje:string|null}
     */
    public function verificarDv(string $numero): array
    {
        ['base' => $base, 'dv' => $dv] = $this->separarDv($numero);
        $calculado = $this->calcularDv($base);

        $correcto = ($dv !== null && $calculado !== null) ? $dv === $calculado : null;

        $mensaje = match (true) {
            $base === ''       => 'Escribe el número de identificación.',
            $calculado === null => null,
            $correcto === false => "El dígito de verificación no cuadra. Para el NIT {$base} debería ser {$calculado}.",
            default             => null,
        };

        return [
            'base'         => $base,
            'dv'           => $dv,
            'dv_calculado' => $calculado,
            'dv_correcto'  => $correcto,
            'mensaje'      => $mensaje,
        ];
    }

    /**
     * Busca el número entre los clientes que ya existen.
     *
     * A propósito NO filtra por sede: si el cliente está en Cali y lo estás
     * creando desde Bogotá, quieres saberlo — eso es justo el duplicado que
     * hay que evitar.
     */
    public function buscarDuplicado(string $base, ?int $ignorarId = null): ?array
    {
        if ($base === '') {
            return null;
        }

        // Sin filtro de sede a propósito, pero respetando el borrado suave:
        // un cliente eliminado no debe reportarse como duplicado.
        $cliente = Cliente::query()
            ->with('sede:id,nombre')
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            // Comparamos solo dígitos para que "900.123.456-7" encuentre
            // a "9001234567" y viceversa.
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(numero_identificacion, '.', ''), '-', ''), ' ', '') LIKE ?",
                [$base . '%']
            )
            ->first();

        if (! $cliente) {
            return null;
        }

        return [
            'id'     => $cliente->id,
            'nombre' => trim($cliente->nombre . ' ' . ($cliente->apellido ?? '')),
            'numero' => $cliente->numero_identificacion,
            'sede'   => $cliente->sede?->nombre,
            'activo' => (bool) $cliente->activo,
            'url'    => "/clientes/{$cliente->id}",
        ];
    }

    /**
     * Consulta los datos abiertos del RUES en datos.gov.co.
     *
     * Es el conjunto c82u-588k, que publica Confecámaras con los datos del
     * Registro Mercantil de todas las cámaras del país. Se actualiza cada mes.
     *
     * Se usa este y no la API del portal del RUES porque esa exige un token
     * que Confecámaras no entrega públicamente. Esta es una API documentada
     * (Socrata/SODA), gratuita y sin credenciales.
     *
     * Aun así se trata como opcional: timeout corto, caché de 30 días, y ante
     * cualquier problema devolvemos null en vez de reventar.
     */
    public function activo(): bool
    {
        return (bool) $this->ajuste('rues_activo', 'services.rues.activo', true);
    }

    public function consultarRues(string $base): ?array
    {
        $base = $this->normalizar($base);

        if ($base === '' || ! $this->activo()) {
            return null;
        }

        // La versión entra en la llave para poder invalidar toda la caché de
        // golpe cuando se cambia la dirección desde Configuración.
        $version = Configuracion::get('rues_cache_version', '1');

        return Cache::remember("rues_nit_{$version}_{$base}", now()->addDays(30), function () use ($base) {
            try {
                $cabeceras = ['Accept' => 'application/json'];

                // El token es opcional: solo sube el límite de consultas.
                if ($token = $this->ajuste('rues_token', 'services.rues.token', '')) {
                    $cabeceras['X-App-Token'] = $token;
                }

                $respuesta = Http::timeout((int) $this->ajuste('rues_timeout', 'services.rues.timeout', 6))
                    ->withHeaders($cabeceras)
                    ->get($this->ajuste('rues_url', 'services.rues.url'), [
                        'nit'    => $base,
                        '$limit' => 5,
                    ]);

                if (! $respuesta->successful()) {
                    Log::info('RUES respondió ' . $respuesta->status() . " para el NIT {$base}");

                    return null;
                }

                return $this->interpretarRues($respuesta->json());
            } catch (\Throwable $e) {
                // No es un error del sistema: el RUES simplemente no contestó.
                Log::info("RUES no disponible para el NIT {$base}: " . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * Saca los campos que nos sirven de la respuesta de datos abiertos.
     *
     * La respuesta es una lista: un NIT puede traer la matriz y sus
     * sucursales. Nos quedamos con la principal.
     */
    private function interpretarRues(mixed $json): ?array
    {
        if (! is_array($json) || $json === []) {
            return null;
        }

        // Preferimos el registro principal sobre las sucursales.
        $registro = null;

        foreach ($json as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            if (($fila['codigo_categoria_matricula'] ?? null) === '01') {
                $registro = $fila;
                break;
            }
            $registro ??= $fila;
        }

        if (! $registro) {
            return null;
        }

        $tomar = function (string $llave) use ($registro): ?string {
            $valor = $registro[$llave] ?? null;

            return (is_scalar($valor) && trim((string) $valor) !== '') ? trim((string) $valor) : null;
        };

        $razonSocial = $tomar('razon_social');

        // Sin razón social no hay nada útil que llenar.
        if (! $razonSocial) {
            return null;
        }

        return array_filter([
            'razon_social'     => $razonSocial,
            'sigla'            => $tomar('sigla'),
            'dv_oficial'       => $tomar('digito_verificacion'),
            'estado_matricula' => $tomar('estado_matricula'),
            'camara_comercio'  => $tomar('camara_comercio'),
            'organizacion'     => $tomar('organizacion_juridica'),
            'representante'    => $tomar('representante_legal'),
            'matricula'        => $tomar('matricula'),
            'ultimo_renovado'  => $tomar('ultimo_ano_renovado'),
            'consultado_en'    => now()->toDateTimeString(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Punto de entrada único: junta las tres capas en una sola respuesta.
     */
    public function consultar(string $numero, string $tipoIdentificacion, ?int $ignorarId = null): array
    {
        $verificacion = $this->verificarDv($numero);
        $base         = $verificacion['base'];

        $resultado = [
            'base'       => $base,
            'dv'         => $verificacion['dv_calculado'],
            'dv_aviso'   => $verificacion['mensaje'],
            'duplicado'  => $this->buscarDuplicado($base, $ignorarId),
            'rues'       => null,
            'rues_aviso' => null,
        ];

        // El RUES solo tiene registro mercantil. Para una cédula de alguien
        // que no es comerciante no hay fuente pública, y está bien que así sea.
        if (! in_array($tipoIdentificacion, ['NIT', 'RUT'], true)) {
            return $resultado;
        }

        // Si está apagado en Configuración, no decimos "no lo encontramos":
        // sería mentira. Simplemente no consultamos.
        if (! $this->activo()) {
            return $resultado;
        }

        $rues = $this->consultarRues($base);

        if ($rues) {
            $resultado['rues'] = $rues;

            // El DV que publica Confecámaras es el oficial. Si por lo que sea
            // no coincide con el nuestro, manda el de ellos.
            if (! empty($rues['dv_oficial'])) {
                $resultado['dv'] = $rues['dv_oficial'];

                if ($verificacion['dv'] !== null && $verificacion['dv'] !== $rues['dv_oficial']) {
                    $resultado['dv_aviso'] = "El dígito de verificación no cuadra. Según el registro mercantil debería ser {$rues['dv_oficial']}.";
                }
            }
        } else {
            $resultado['rues_aviso'] = 'No encontramos esta empresa en el RUES. Puedes escribir los datos a mano.';
        }

        return $resultado;
    }
}
