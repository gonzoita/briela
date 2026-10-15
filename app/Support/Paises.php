<?php

namespace App\Support;

use App\Models\Configuracion;
use App\Models\Sede;
use App\Services\ConsultaNitService;

/**
 * Los países con los que trabaja Briela, y lo que cada uno exige.
 *
 * **Lo que depende de un país es un dato, no código.** La lista de países y sus monedas es
 * estable (ISO 3166 e ISO 4217) y vive aquí. Todo lo que cambia por decreto —tarifas,
 * tipos de identificación, qué documento fiscal se pide— vive en un *paquete de país*:
 * `resources/paises/{ISO}.json`. Un país sin paquete funciona igual, con identificaciones
 * genéricas y los impuestos que la empresa escriba.
 *
 * Encima del paquete, la instalación puede ajustar lo suyo (`paises_ajustes` en
 * Configuración): una tarifa que cambió antes de que salga la versión nueva, o una
 * identificación que el paquete no trae. Lo ajustado gana sobre el paquete.
 *
 * El país de la **casa** es el de la sede principal. Un cliente o proveedor sin país se
 * entiende del país de la casa: así lo que ya estaba guardado sigue siendo Colombia sin
 * tocar una sola fila.
 */
class Paises
{
    /** @var array<string, array{nombre: string, moneda: string}> ISO 3166 → nombre y moneda de uso */
    public const CATALOGO = [
        'CO' => ['nombre' => 'Colombia',        'moneda' => 'COP'],
        'PA' => ['nombre' => 'Panamá',          'moneda' => 'USD'],
        'VE' => ['nombre' => 'Venezuela',       'moneda' => 'VES'],
        'EC' => ['nombre' => 'Ecuador',         'moneda' => 'USD'],
        'PE' => ['nombre' => 'Perú',            'moneda' => 'PEN'],
        'MX' => ['nombre' => 'México',          'moneda' => 'MXN'],
        'CL' => ['nombre' => 'Chile',           'moneda' => 'CLP'],
        'AR' => ['nombre' => 'Argentina',       'moneda' => 'ARS'],
        'BR' => ['nombre' => 'Brasil',          'moneda' => 'BRL'],
        'BO' => ['nombre' => 'Bolivia',         'moneda' => 'BOB'],
        'PY' => ['nombre' => 'Paraguay',        'moneda' => 'PYG'],
        'UY' => ['nombre' => 'Uruguay',         'moneda' => 'UYU'],
        'CR' => ['nombre' => 'Costa Rica',      'moneda' => 'CRC'],
        'GT' => ['nombre' => 'Guatemala',       'moneda' => 'GTQ'],
        'HN' => ['nombre' => 'Honduras',        'moneda' => 'HNL'],
        'SV' => ['nombre' => 'El Salvador',     'moneda' => 'USD'],
        'NI' => ['nombre' => 'Nicaragua',       'moneda' => 'NIO'],
        'DO' => ['nombre' => 'República Dominicana', 'moneda' => 'DOP'],
        'US' => ['nombre' => 'Estados Unidos',  'moneda' => 'USD'],
        'CA' => ['nombre' => 'Canadá',          'moneda' => 'CAD'],
        'ES' => ['nombre' => 'España',          'moneda' => 'EUR'],
        'PT' => ['nombre' => 'Portugal',        'moneda' => 'EUR'],
        'FR' => ['nombre' => 'Francia',         'moneda' => 'EUR'],
        'DE' => ['nombre' => 'Alemania',        'moneda' => 'EUR'],
        'IT' => ['nombre' => 'Italia',          'moneda' => 'EUR'],
        'GB' => ['nombre' => 'Reino Unido',     'moneda' => 'GBP'],
        'CN' => ['nombre' => 'China',           'moneda' => 'CNY'],
    ];

    /** Identificaciones de un país sin paquete: lo mínimo para no dejar el campo sin opciones. */
    private const GENERICAS = [
        ['codigo' => 'ID',  'nombre' => 'Identificación tributaria', 'juridica' => true,  'patron' => null, 'dv' => null],
        ['codigo' => 'DOC', 'nombre' => 'Documento de identidad',    'juridica' => false, 'patron' => null, 'dv' => null],
        ['codigo' => 'PAS', 'nombre' => 'Pasaporte',                 'juridica' => false, 'patron' => null, 'dv' => null],
    ];

    /**
     * La identificación de un tercero del exterior, en formato libre y sin dígito de verificación.
     *
     * Va al final de la lista de **todos** los países: un Tax ID que el paquete no conoce —o con un
     * formato que el patrón no previó— no puede dejar a alguien sin poder registrar a un cliente
     * real. Es la salida, no la regla: el paquete del país propone primero lo suyo.
     */
    public const EXTERIOR = ['codigo' => 'EXT', 'nombre' => 'Identificación tributaria del exterior', 'juridica' => true, 'patron' => null, 'dv' => null,
        'ayuda' => 'Formato libre: el Tax ID, VAT o número fiscal tal como aparece en el documento.'];

    // ─── El país de la casa ──────────────────────────────────────────────────

    /** El país de la empresa: el de la sede principal, y Colombia si todavía no se ha dicho. */
    public static function casa(): string
    {
        // En el contenedor y no en una estática: vive lo que vive la petición, no el proceso.
        if (app()->bound('paises.casa')) {
            return app('paises.casa');
        }

        $iso  = strtoupper((string) Sede::principal()?->pais);
        $casa = self::existe($iso) ? $iso : 'CO';

        app()->instance('paises.casa', $casa);

        return $casa;
    }

    /** Se llama al guardar una sede: el país de la casa pudo cambiar. */
    public static function olvidar(): void
    {
        app()->forgetInstance('paises.casa');
    }

    /** El país de una sede, o el de la casa si no tiene. */
    public static function deSede(?Sede $sede): string
    {
        return $sede && self::existe($sede->pais) ? $sede->pais : self::casa();
    }

    /** El país de la sede activa del encabezado. */
    public static function activo(): string
    {
        return self::deSede(ContextoSede::actual());
    }

    public static function existe(?string $iso): bool
    {
        return $iso !== null && isset(self::CATALOGO[strtoupper($iso)]);
    }

    public static function nombre(?string $iso): string
    {
        return self::CATALOGO[strtoupper((string) $iso)]['nombre'] ?? (string) $iso;
    }

    /** La moneda en la que un país escribe sus documentos. */
    public static function monedaDe(?string $iso): string
    {
        $moneda = self::CATALOGO[strtoupper((string) $iso)]['moneda'] ?? Monedas::base();

        return Monedas::existe($moneda) ? $moneda : Monedas::base();
    }

    /** Para un selector. @return list<array{iso: string, nombre: string, moneda: string, paquete: bool}> */
    public static function lista(): array
    {
        $lista = [];

        foreach (self::CATALOGO as $iso => $info) {
            $lista[] = ['iso' => $iso, 'nombre' => $info['nombre'], 'moneda' => $info['moneda'], 'paquete' => self::paquete($iso) !== null];
        }

        usort($lista, fn ($a, $b) => strcmp($a['nombre'], $b['nombre']));

        return $lista;
    }

    // ─── El paquete ──────────────────────────────────────────────────────────

    /** El paquete tal como viene en el código, sin los ajustes de la instalación. */
    public static function paquete(?string $iso): ?array
    {
        $iso = strtoupper((string) $iso);

        if (! preg_match('/^[A-Z]{2}$/', $iso)) {
            return null;
        }

        $ruta = resource_path("paises/{$iso}.json");

        if (! is_file($ruta)) {
            return null;
        }

        $datos = json_decode((string) file_get_contents($ruta), true);

        return is_array($datos) ? $datos : null;
    }

    /**
     * Lo que rige en un país para esta instalación: el paquete, más lo que ella haya ajustado.
     *
     * Siempre devuelve la misma forma, haya paquete o no.
     *
     * @return array{iso: string, nombre: string, moneda: string, revisado: bool, version: ?string,
     *   documento_fiscal: string, autoridad: ?string, retenciones: bool, facturacion: ?string,
     *   identificaciones: list<array>, impuestos: list<array>, otros: list<array>,
     *   importacion: array{iva_descontable: bool, nota: ?string}, notas: list<string>, con_paquete: bool}
     */
    public static function perfil(?string $iso): array
    {
        $iso     = self::existe($iso) ? strtoupper((string) $iso) : self::casa();
        $paquete = self::paquete($iso);
        $ajuste  = self::ajustes()[$iso] ?? [];

        $impuestos = $ajuste['impuestos'] ?? $paquete['impuestos'] ?? [
            ['clave' => 'iva', 'nombre' => 'Impuesto', 'tarifas' => [0], 'defecto' => 0, 'nota' => 'Este país no tiene paquete: escribe aquí los impuestos que aplican.'],
        ];

        return [
            'iso'              => $iso,
            'nombre'           => self::nombre($iso),
            'moneda'           => self::monedaDe($iso),
            'revisado'         => (bool) ($paquete['revisado'] ?? false),
            'version'          => $paquete['version'] ?? null,
            'documento_fiscal' => (string) ($paquete['documento_fiscal'] ?? 'Documento fiscal'),
            'autoridad'        => $paquete['autoridad'] ?? null,
            'retenciones'      => (bool) ($paquete['retenciones'] ?? false),
            'facturacion'      => $paquete['facturacion'] ?? null,
            'identificaciones' => self::conExterior(array_merge($paquete['identificaciones'] ?? self::GENERICAS, $ajuste['identificaciones'] ?? [])),
            'impuestos'        => array_values($impuestos),
            'otros'            => array_values($paquete['otros'] ?? []),
            'importacion'      => [
                'iva_descontable' => (bool) ($ajuste['importacion']['iva_descontable'] ?? $paquete['importacion']['iva_descontable'] ?? true),
                'nota'            => $paquete['importacion']['nota'] ?? null,
            ],
            'notas'            => array_values($paquete['notas'] ?? []),
            'con_paquete'      => $paquete !== null,
        ];
    }

    /** La lista de identificaciones, con la del exterior al final si no está. @return list<array> */
    private static function conExterior(array $identificaciones): array
    {
        if (! in_array(self::EXTERIOR['codigo'], array_column($identificaciones, 'codigo'), true)) {
            $identificaciones[] = self::EXTERIOR;
        }

        return array_values($identificaciones);
    }

    /** Lo que la instalación ha ajustado, por país. @return array<string, array> */
    public static function ajustes(): array
    {
        $valor = Configuracion::get('paises_ajustes');

        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return is_array($valor) ? $valor : [];
    }

    public static function guardarAjuste(string $iso, array $ajuste): void
    {
        $todos = self::ajustes();
        $todos[strtoupper($iso)] = $ajuste;

        Configuracion::set('paises_ajustes', json_encode($todos, JSON_UNESCAPED_UNICODE));
    }

    // ─── Impuestos ───────────────────────────────────────────────────────────

    /** Las tarifas que se ofrecen en un ítem de cotización, de mayor a menor. @return list<float> */
    public static function tarifas(?string $iso): array
    {
        $tarifas = [];

        foreach (self::perfil($iso)['impuestos'] as $impuesto) {
            foreach ($impuesto['tarifas'] ?? [] as $t) {
                $tarifas[] = (float) $t;
            }
        }

        $tarifas = array_values(array_unique($tarifas));
        rsort($tarifas);

        return $tarifas ?: [0.0];
    }

    /** Cómo se llama el impuesto principal de un país: IVA, ITBMS… */
    public static function nombreImpuesto(?string $iso): string
    {
        return (string) (self::perfil($iso)['impuestos'][0]['nombre'] ?? 'Impuesto');
    }

    // ─── Identificaciones ────────────────────────────────────────────────────

    /** @return list<string> Los códigos de identificación que acepta un país. */
    public static function codigosIdentificacion(?string $iso): array
    {
        return array_column(self::perfil($iso)['identificaciones'], 'codigo');
    }

    /** La definición de un tipo de identificación dentro de un país, o null si no existe. */
    public static function identificacion(?string $iso, ?string $codigo): ?array
    {
        foreach (self::perfil($iso)['identificaciones'] as $i) {
            if (strcasecmp($i['codigo'], (string) $codigo) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Revisa un número de identificación contra las reglas de su país.
     *
     * Devuelve el aviso a mostrar, o null si todo cuadra. **Es un aviso, no un bloqueo**:
     * un patrón mal copiado de un decreto no debería impedir registrar a un cliente real.
     */
    public static function avisoIdentificacion(?string $iso, ?string $codigo, ?string $numero, ?string $dv = null): ?string
    {
        $numero = trim((string) $numero);
        $def    = self::identificacion($iso, $codigo);

        if ($numero === '' || $def === null) {
            return null;
        }

        if (! empty($def['patron']) && @preg_match('/' . str_replace('/', '\/', $def['patron']) . '/', $numero) !== 1) {
            return "El formato no parece el de un {$def['nombre']} de " . self::nombre($iso) . ($def['ayuda'] ?? false ? '. ' . $def['ayuda'] : '.');
        }

        if (($def['dv'] ?? null) !== null && $dv !== null && $dv !== '') {
            $esperado = self::calcularDv($iso, $codigo, $numero);

            if ($esperado !== null && strcasecmp($esperado, $dv) !== 0) {
                return "El dígito de verificación no corresponde: debería ser {$esperado}.";
            }
        }

        return null;
    }

    /**
     * El dígito de verificación de un número, con la regla de su país, o null si no lleva.
     *
     * - `nit_co`: el NIT de Colombia (DIAN), con sus pesos primos.
     * - `rut_cl`: el RUT de Chile (SII), módulo 11 con pesos 2 a 7; 10 es «K» y 11 es «0».
     */
    public static function calcularDv(?string $iso, ?string $codigo, ?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero);

        if ($digitos === '') {
            return null;
        }

        return match (self::identificacion($iso, $codigo)['dv'] ?? null) {
            'nit_co' => app(ConsultaNitService::class)->calcularDv($digitos),
            'rut_cl' => self::dvRutChile($digitos),
            default  => null,
        };
    }

    private static function dvRutChile(string $digitos): string
    {
        $suma = 0;
        $peso = 2;

        foreach (array_reverse(str_split($digitos)) as $d) {
            $suma += (int) $d * $peso;
            $peso = $peso === 7 ? 2 : $peso + 1;
        }

        $resto = 11 - ($suma % 11);

        return match ($resto) {
            11      => '0',
            10      => 'K',
            default => (string) $resto,
        };
    }

    // ─── La identificación de la empresa ─────────────────────────────────────

    /**
     * La identificación fiscal de la empresa que usa Briela: tipo (NIT, RFC, RUC, EIN…), número
     * tal como se guardó y el texto listo para un encabezado («NIT: 900123456-7»).
     *
     * El tipo se guarda aparte (`empresa_tipo_identificacion`); si nunca se eligió, es la primera
     * identificación de empresa del país de la casa. El número sigue en `empresa_nit`, que es la
     * clave que ya leen los PDF: renombrarla rompería las plantillas guardadas.
     *
     * @return array{tipo: string, nombre: string, numero: string, texto: string}
     */
    public static function identificacionEmpresa(): array
    {
        $casa   = self::casa();
        $tipo   = (string) Configuracion::get('empresa_tipo_identificacion', '');
        $def    = $tipo !== '' ? self::identificacion($casa, $tipo) : null;
        $def  ??= collect(self::perfil($casa)['identificaciones'])->firstWhere('juridica', true) ?? self::EXTERIOR;
        $numero = trim((string) Configuracion::get('empresa_nit', ''));

        return [
            'tipo'   => $def['codigo'],
            'nombre' => $def['nombre'],
            'numero' => $numero,
            // La sigla en el encabezado, no el nombre largo: «EIN», no «EIN (Employer…)».
            'texto'  => $numero === '' ? '' : ($def['codigo'] === self::EXTERIOR['codigo'] ? 'Id. fiscal' : $def['codigo']) . ': ' . $numero,
        ];
    }

    /** Si ese tipo de identificación lleva dígito de verificación calculable. */
    public static function llevaDv(?string $iso, ?string $codigo): bool
    {
        return (self::identificacion($iso, $codigo)['dv'] ?? null) !== null;
    }
}
