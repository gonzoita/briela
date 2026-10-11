<?php

namespace App\Services;

use App\Models\CuentaBancaria;
use App\Models\MovimientoBancario;
use App\Models\OpCuota;
use App\Models\OpPago;
use App\Support\Modulos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Confirma con el banco que el dinero de una cuota llegó, sin que alguien tenga que revisar la cuenta.
 *
 * **Solo lee.** No mueve plata, no paga, no toca el banco: recibe los abonos —por un extracto hoy,
 * por una conexión de banca abierta mañana— y los empareja con las cuotas que la empresa espera
 * cobrar. La fuente no importa: todas terminan en {@see importar()}.
 *
 * Cada abono sale de aquí en uno de cuatro estados:
 *  - **conciliado** — hubo una sola explicación posible y se registró el pago, ya «verificado por el
 *    banco»;
 *  - **dudoso** — hay candidatas y una persona decide, con un clic;
 *  - **pendiente** — nadie lo reclama todavía: dinero sin dueño, que se ve en la bandeja;
 *  - **ignorado** — no es de la empresa (un abono propio, un error).
 *
 * Lo que se concilia solo es lo que no deja duda. En montos altos, equivocarse en silencio es peor
 * que preguntar: si hay dos explicaciones, se pregunta.
 */
class VerificadorPagosService
{
    /** Diferencia aceptada entre lo que entró y el saldo de la cuota (centavos). */
    private const TOLERANCIA = 0.01;

    /** Palabras de un nombre de empresa que no sirven para reconocerla. */
    private const NOMBRE_GENERICO = [
        'LTDA', 'LIMITADA', 'COMPANIA', 'EMPRESA', 'GRUPO', 'CORP', 'CORPORACION', 'INVERSIONES',
        'COLOMBIA', 'SERVICIOS', 'SOLUCIONES', 'COMERCIAL', 'INDUSTRIAL', 'DISTRIBUIDORA',
    ];

    public static function habilitado(): bool
    {
        return Modulos::activo('verificador_pagos') && Modulos::activo('cartera');
    }

    // ─── Entrada: la fuente da igual ─────────────────────────────────────────

    /**
     * Guarda los abonos que llegaron y concilia solos los que no dejan duda.
     *
     * Es el punto único de entrada, venga de un extracto o de una conexión bancaria: lo que cambia
     * entre fuentes es de dónde salen las filas, no lo que se hace con ellas. Un abono que ya estaba
     * —porque el extracto se traslapa con el anterior, o porque llegó por dos caminos— no se
     * vuelve a contar.
     *
     * @param  list<array{fecha: string, valor: float, descripcion?: ?string, referencia?: ?string, contraparte?: ?string, id_externo?: ?string}>  $abonos
     * @return array{nuevos: int, repetidos: int, conciliados: int, dudosos: int, pendientes: int}
     */
    public function importar(CuentaBancaria $cuenta, array $abonos, string $origen = 'extracto', ?int $userId = null): array
    {
        $resumen = ['nuevos' => 0, 'repetidos' => 0, 'conciliados' => 0, 'dudosos' => 0, 'pendientes' => 0];

        if ($abonos === []) {
            return $resumen;
        }

        // Dos abonos iguales el mismo día son dos pagos, no uno repetido: la huella lleva cuántas
        // veces ya vimos esa misma fila en este archivo.
        $vistas = [];
        $filas  = [];

        foreach ($abonos as $a) {
            $base = implode('|', [
                $a['id_externo'] ?? '', $a['fecha'], number_format((float) $a['valor'], 2, '.', ''),
                $a['descripcion'] ?? '', $a['referencia'] ?? '',
            ]);
            $n = $vistas[$base] = ($vistas[$base] ?? 0) + 1;

            $filas[sha1($base.'#'.$n)] = $a;
        }

        $existentes = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)
            ->whereIn('huella', array_keys($filas))
            ->pluck('huella')
            ->flip();

        $cuotas = $this->cuotasAbiertas();

        foreach ($filas as $huella => $a) {
            if ($existentes->has($huella)) {
                $resumen['repetidos']++;
                continue;
            }

            $mov = MovimientoBancario::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'id_externo'         => $a['id_externo'] ?? null,
                'huella'             => $huella,
                'fecha'              => $a['fecha'],
                'valor'              => $a['valor'],
                'descripcion'        => $a['descripcion'] ?? null,
                'referencia'         => $a['referencia'] ?? null,
                'contraparte'        => $a['contraparte'] ?? null,
                'estado'             => 'pendiente',
                'origen'             => $origen,
            ]);

            $resumen['nuevos']++;

            if (! static::habilitado()) {
                $resumen['pendientes']++;
                continue;
            }

            $veredicto = $this->clasificar($mov, $cuotas);

            if ($veredicto['tipo'] === 'exacta') {
                // `aplicar` toca el saldo de la cuota, así que la lista en memoria se refresca.
                $this->aplicar($mov, $veredicto['cuotas'][0], $userId, automatico: true);
                $cuotas = $this->cuotasAbiertas();
                $resumen['conciliados']++;
            } elseif ($veredicto['tipo'] === 'dudosa') {
                $mov->update(['estado' => 'dudoso']);
                $resumen['dudosos']++;
            } else {
                $resumen['pendientes']++;
            }
        }

        return $resumen;
    }

    // ─── El emparejamiento ───────────────────────────────────────────────────

    /**
     * Qué se sabe de un abono: a qué cuota corresponde, o cuáles podrían ser.
     *
     * 1. Si menciona una orden (`OP-2026-0042`), se busca entre las cuotas de ESA orden; con una
     *    cuota por ese valor exacto, es esa (si hay varias iguales, la primera: las cuotas se pagan
     *    en orden).
     * 2. Si no menciona ninguna, se busca por **valor exacto**. Con una sola cuota de ese valor
     *    cuyo cliente aparece en la descripción, es esa. Con un valor que coincide pero sin nadie que
     *    lo reclame, se pregunta: el mismo valor puede ser de dos clientes.
     *
     * @param  Collection<int, OpCuota>|null  $cuotas  las cuotas abiertas; se cargan si no vienen
     * @return array{tipo: 'exacta'|'dudosa'|'ninguna', cuotas: list<OpCuota>}
     */
    public function clasificar(MovimientoBancario $mov, ?Collection $cuotas = null): array
    {
        $cuotas ??= $this->cuotasAbiertas();
        $texto   = $this->compacto(implode(' ', array_filter([$mov->referencia, $mov->descripcion, $mov->contraparte])));
        $valor   = (float) $mov->valor;
        $coincide = fn (OpCuota $c) => abs($c->saldo - $valor) < self::TOLERANCIA;

        // 1. ¿Nombra una orden?
        $mencionadas = $cuotas->groupBy('op_id')->filter(fn (Collection $deOp) => $this->menciona($texto, (string) $deOp->first()->op->numero));

        if ($mencionadas->count() === 1) {
            $deOp   = $mencionadas->first()->sortBy('numero_cuota')->values();
            $exacta = $deOp->filter($coincide)->values();

            return $exacta->isNotEmpty()
                ? ['tipo' => 'exacta', 'cuotas' => [$exacta->first()]]
                : ['tipo' => 'dudosa', 'cuotas' => $deOp->all()];
        }

        if ($mencionadas->count() > 1) {
            $todas  = $mencionadas->flatten(1)->sortBy('numero_cuota')->values();
            $exacta = $todas->filter($coincide)->values();

            return ['tipo' => 'dudosa', 'cuotas' => ($exacta->isNotEmpty() ? $exacta : $todas)->all()];
        }

        // 2. Sin orden: por valor, y por el cliente si lo nombra.
        $exacta = $cuotas->filter($coincide)->values();

        if ($exacta->isEmpty()) {
            return ['tipo' => 'ninguna', 'cuotas' => []];
        }

        $conCliente = $exacta->filter(fn (OpCuota $c) => $this->nombraAlCliente($texto, (string) $c->op->cliente?->nombre))->values();

        if ($conCliente->count() === 1) {
            return ['tipo' => 'exacta', 'cuotas' => [$conCliente->first()]];
        }

        return ['tipo' => 'dudosa', 'cuotas' => ($conCliente->isNotEmpty() ? $conCliente : $exacta)->all()];
    }

    /**
     * Las cuotas que se pueden proponer para un abono: primero las que clasifican, y después todas
     * las demás abiertas, para que la persona pueda asignarlo a mano a cualquiera.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatas(MovimientoBancario $mov): array
    {
        $cuotas   = $this->cuotasAbiertas();
        $sugeridas = collect($this->clasificar($mov, $cuotas)['cuotas'])->pluck('id')->flip();

        return $cuotas->sortBy([
            fn ($a, $b) => (int) $sugeridas->has($b->id) <=> (int) $sugeridas->has($a->id),
            fn ($a, $b) => abs($a->saldo - (float) $mov->valor) <=> abs($b->saldo - (float) $mov->valor),
        ])->take(60)->map(fn (OpCuota $c) => [
            'id'         => $c->id,
            'op_id'      => $c->op_id,
            'op_numero'  => $c->op->numero,
            'cliente'    => $c->op->cliente?->nombre,
            'concepto'   => $c->concepto,
            'saldo'      => $c->saldo,
            'vence'      => $c->fecha_vencimiento?->toDateString(),
            'coincide'   => abs($c->saldo - (float) $mov->valor) < self::TOLERANCIA,
            'sugerida'   => $sugeridas->has($c->id),
        ])->values()->all();
    }

    // ─── Conciliar, deshacer, ignorar ────────────────────────────────────────

    /**
     * Registra el abono como pago de la cuota, marcado como verificado por el banco.
     *
     * Hace lo mismo que registrar un pago a mano en la OP (recibo, saldo de la cuota, saldo de la
     * orden): el verificador no es un segundo camino con reglas propias, es el mismo pago con la
     * diferencia de que lo confirmó el banco.
     */
    public function aplicar(MovimientoBancario $mov, OpCuota $cuota, ?int $userId = null, bool $automatico = false): OpPago
    {
        $pago = DB::transaction(function () use ($mov, $cuota, $userId, $automatico) {
            $mov = MovimientoBancario::whereKey($mov->id)->lockForUpdate()->firstOrFail();

            if ($mov->estado === 'conciliado') {
                throw new RuntimeException('Este abono ya está conciliado.');
            }

            $cuota = OpCuota::whereKey($cuota->id)->lockForUpdate()->firstOrFail();

            $pago = OpPago::create([
                'op_id'          => $cuota->op_id,
                'cuota_id'       => $cuota->id,
                'numero_recibo'  => 'REC-TMP',
                'valor'          => $mov->valor,
                'medio_pago'     => 'transferencia',
                'fecha_pago'     => $mov->fecha->toDateString(),
                'referencia'     => Str::limit((string) ($mov->referencia ?: $mov->descripcion), 190, ''),
                'notas'          => 'Confirmado con el banco'.($mov->cuenta ? " · {$mov->cuenta->nombre}" : '').($automatico ? ' · conciliado automáticamente' : ''),
                'registrado_por' => $userId,
                'verificado_at'  => now(),
            ]);

            $pago->numero_recibo = 'REC-'.str_pad((string) $pago->id, 4, '0', STR_PAD_LEFT);
            $pago->save();

            $cuota->valor_pagado = (float) $cuota->valor_pagado + (float) $mov->valor;
            $cuota->estado       = (float) $cuota->valor_pagado >= (float) $cuota->valor - self::TOLERANCIA ? 'pagado' : 'parcial';
            $cuota->save();

            $mov->update([
                'estado'         => 'conciliado',
                'op_pago_id'     => $pago->id,
                'conciliado_por' => $userId,
                'conciliado_at'  => now(),
                'automatico'     => $automatico,
            ]);

            return $pago;
        });

        $cuota->op?->sincronizarSaldoPendiente();

        return $pago;
    }

    /**
     * Deshace una conciliación: borra el pago y la cuota vuelve a deber lo que debía.
     *
     * El abono queda **pendiente** y no se vuelve a emparejar solo: si alguien lo deshizo es porque
     * el emparejamiento estaba mal, y repetirlo sería insistir en el error.
     */
    public function deshacer(MovimientoBancario $mov): void
    {
        if ($mov->estado !== 'conciliado') {
            throw new RuntimeException('Este abono no está conciliado.');
        }

        $cuota = null;

        DB::transaction(function () use ($mov, &$cuota) {
            $pago = $mov->pago;

            if ($pago) {
                $cuota = $pago->cuota;

                if ($cuota) {
                    $cuota->valor_pagado = max(0, (float) $cuota->valor_pagado - (float) $pago->valor);
                    $cuota->estado = (float) $cuota->valor_pagado <= 0 ? 'pendiente'
                        : ((float) $cuota->valor_pagado >= (float) $cuota->valor - self::TOLERANCIA ? 'pagado' : 'parcial');
                    $cuota->save();
                }

                $pago->delete();
            }

            $mov->update([
                'estado' => 'pendiente', 'op_pago_id' => null, 'conciliado_por' => null,
                'conciliado_at' => null, 'automatico' => false, 'nota' => 'Conciliación deshecha',
            ]);
        });

        $cuota?->op?->sincronizarSaldoPendiente();
    }

    /** Un abono que no es de la empresa (o que no hay que cobrar a nadie) sale de la bandeja. */
    public function ignorar(MovimientoBancario $mov, ?string $nota = null): void
    {
        if ($mov->estado === 'conciliado') {
            throw new RuntimeException('Un abono conciliado no se ignora: primero se deshace.');
        }

        $mov->update(['estado' => 'ignorado', 'nota' => $nota ? Str::limit($nota, 290, '') : null]);
    }

    public function restaurar(MovimientoBancario $mov): void
    {
        if ($mov->estado === 'ignorado') {
            $mov->update(['estado' => 'pendiente', 'nota' => null]);
        }
    }

    /**
     * Si borran a mano un pago que estaba confirmado por el banco, el abono vuelve a la bandeja:
     * el dinero sigue en la cuenta aunque el pago ya no esté anotado.
     */
    public function pagoBorrado(int $pagoId): void
    {
        MovimientoBancario::where('op_pago_id', $pagoId)->update([
            'estado' => 'pendiente', 'op_pago_id' => null, 'conciliado_por' => null,
            'conciliado_at' => null, 'automatico' => false, 'nota' => 'El pago se borró de la cartera',
        ]);
    }

    // ─── Lectura ─────────────────────────────────────────────────────────────

    /** @return array{por_revisar: int, dudosos: int, pendientes: int, conciliados_mes: int, sin_dueno_valor: float} */
    public function resumen(): array
    {
        $porEstado = MovimientoBancario::selectRaw('estado, COUNT(*) as n, SUM(valor) as total')->groupBy('estado')->get()->keyBy('estado');

        return [
            'por_revisar'     => (int) (($porEstado['pendiente']->n ?? 0) + ($porEstado['dudoso']->n ?? 0)),
            'dudosos'         => (int) ($porEstado['dudoso']->n ?? 0),
            'pendientes'      => (int) ($porEstado['pendiente']->n ?? 0),
            'conciliados_mes' => MovimientoBancario::where('estado', 'conciliado')->where('conciliado_at', '>=', now()->startOfMonth())->count(),
            'sin_dueno_valor' => (float) (($porEstado['pendiente']->total ?? 0) + ($porEstado['dudoso']->total ?? 0)),
        ];
    }

    // ─── Detalles ────────────────────────────────────────────────────────────

    /**
     * Las cuotas que todavía deben algo, con su orden y su cliente.
     *
     * @return Collection<int, OpCuota>
     */
    private function cuotasAbiertas(): Collection
    {
        return OpCuota::with('op.cliente:id,nombre')
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->whereHas('op')
            ->get()
            ->filter(fn (OpCuota $c) => $c->saldo > self::TOLERANCIA)
            ->values();
    }

    /** Mayúsculas, sin tildes y solo letras y números: «Op-2026/001» y «OP 2026 001» se leen igual. */
    private function compacto(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]+/', ' ', Str::upper(Str::ascii($texto))) ?? '';
    }

    /**
     * ¿El texto nombra esta orden? «OP-2026-001» no puede confundirse con «OP-2026-0012».
     *
     * El texto ya viene compacto —los separadores son un solo espacio—, así que «OP-2026-001»,
     * «OP 2026 001» y «OP2026001» se reconocen igual, y el final no puede seguir con otro dígito.
     */
    private function menciona(string $textoCompacto, string $numeroOp): bool
    {
        $trozos = preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($numeroOp)), -1, PREG_SPLIT_NO_EMPTY);

        if (! $trozos) {
            return false;
        }

        return (bool) preg_match(
            '/(?<![A-Z0-9])'.implode(' ?', array_map(fn ($t) => preg_quote($t, '/'), $trozos)).'(?![A-Z0-9])/',
            $textoCompacto,
        );
    }

    /** ¿Alguna palabra con peso del nombre del cliente aparece en el texto? */
    private function nombraAlCliente(string $textoCompacto, string $nombre): bool
    {
        if ($nombre === '') {
            return false;
        }

        $palabras = array_filter(
            preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($nombre)), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($p) => strlen($p) >= 4 && ! in_array($p, self::NOMBRE_GENERICO, true),
        );

        foreach ($palabras as $p) {
            if (preg_match('/(?<![A-Z0-9])'.preg_quote($p, '/').'(?![A-Z0-9])/', $textoCompacto)) {
                return true;
            }
        }

        return false;
    }
}
