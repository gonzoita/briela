<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Lo tributario que la empresa configura: sus responsabilidades y las reglas de retención.
 *
 * **Nada de esto se escribe en el código como verdad.** Las tarifas, las bases y el valor de
 * la UVT cambian por decreto y por año. Si vivieran aquí, cada reforma obligaría a sacar una
 * versión nueva y a actualizar a todos los clientes de urgencia. Viven en Configuración →
 * Perfil fiscal; los valores de abajo son solo el punto de partida de una instalación nueva,
 * y la pantalla pide confirmarlos con el contador.
 */
class Fiscal
{
    /**
     * Los códigos de la casilla 53 del RUT que deciden algo en Briela.
     *
     * No es la lista completa de la DIAN —tiene más de cincuenta—: son los que cambian qué
     * retención se practica. Un código que no esté aquí se guarda igual y se muestra con su
     * número.
     */
    public const RESPONSABILIDADES = [
        '05' => 'Impuesto de renta · régimen ordinario',
        '07' => 'Agente de retención en la fuente (renta)',
        '09' => 'Agente de retención de IVA',
        '13' => 'Gran contribuyente',
        '14' => 'Informante de exógena',
        '15' => 'Autorretenedor',
        '42' => 'Obligado a llevar contabilidad',
        '47' => 'Régimen simple de tributación',
        '48' => 'Responsable de IVA',
        '49' => 'No responsable de IVA',
        '52' => 'Facturador electrónico',
    ];

    /** Los conceptos de retención en la fuente con los que arranca una instalación. */
    private const CONCEPTOS_INICIALES = [
        ['clave' => 'compras',   'nombre' => 'Compras',   'tarifa' => 2.5, 'base_uvt' => 27],
        ['clave' => 'servicios', 'nombre' => 'Servicios', 'tarifa' => 4,   'base_uvt' => 4],
    ];

    /** @return array{uvt: ?float, conceptos: list<array{clave: string, nombre: string, tarifa: float, base_uvt: float}>, reteiva_pct: float, reteica_por_mil: float, responsabilidades: list<string>, actividad: string} */
    public static function ajustes(): array
    {
        $conceptos = self::json('fiscal_retefuente_conceptos') ?? self::CONCEPTOS_INICIALES;
        $uvt       = Configuracion::get('fiscal_uvt');

        return [
            // Sin UVT no se sabe si una venta pasa la base mínima: se avisa y se calcula
            // como si no hubiera base, que es lo más prudente para quien va a recibir menos.
            'uvt'             => is_numeric($uvt) && (float) $uvt > 0 ? (float) $uvt : null,
            'conceptos'       => array_values(array_map(fn ($c) => [
                'clave'    => (string) $c['clave'],
                'nombre'   => (string) $c['nombre'],
                'tarifa'   => (float) $c['tarifa'],
                'base_uvt' => (float) $c['base_uvt'],
            ], $conceptos)),
            'reteiva_pct'     => (float) (Configuracion::get('fiscal_reteiva_pct') ?? 15),
            'reteica_por_mil' => (float) (Configuracion::get('fiscal_reteica_por_mil') ?? 0),
            'responsabilidades' => self::codigos(self::json('fiscal_responsabilidades') ?? []),
            'actividad'       => (string) (Configuracion::get('fiscal_actividad') ?? ''),
        ];
    }

    /** Las responsabilidades de la empresa que usa Briela, según su RUT. @return list<string> */
    public static function responsabilidadesEmpresa(): array
    {
        return self::ajustes()['responsabilidades'];
    }

    /**
     * Códigos limpios: dos dígitos, sin repetir. «7», «07» y «O-07» son el mismo.
     *
     * @return list<string>
     */
    public static function codigos(mixed $lista): array
    {
        if (! is_array($lista)) {
            return [];
        }

        $limpios = [];

        foreach ($lista as $codigo) {
            $digitos = preg_replace('/\D/', '', (string) $codigo);

            if ($digitos !== '' && strlen($digitos) <= 2) {
                $limpios[] = str_pad($digitos, 2, '0', STR_PAD_LEFT);
            }
        }

        $limpios = array_values(array_unique($limpios));
        sort($limpios);

        return $limpios;
    }

    /** El catálogo como lo necesita una pantalla. @return list<array{codigo: string, nombre: string}> */
    public static function catalogo(): array
    {
        return array_map(
            fn ($codigo, $nombre) => ['codigo' => (string) $codigo, 'nombre' => $nombre],
            array_keys(self::RESPONSABILIDADES),
            self::RESPONSABILIDADES,
        );
    }

    public static function guardarJson(string $clave, array $valor): void
    {
        Configuracion::set($clave, json_encode($valor, JSON_UNESCAPED_UNICODE));
    }

    /** Lee un ajuste JSON venga como venga: la columna `tipo` no existe en todas las instalaciones. */
    private static function json(string $clave): ?array
    {
        $valor = Configuracion::get($clave);

        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return is_array($valor) ? $valor : null;
    }
}
