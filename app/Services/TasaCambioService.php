<?php

namespace App\Services;

use App\Models\TasaCambio;
use App\Support\Monedas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La tasa de cambio de cada día: de dónde sale, dónde se guarda y cómo se usa.
 *
 * ## Las fuentes
 *
 *  - **Dólar**: la TRM oficial que certifica la Superintendencia Financiera, publicada como
 *    dato abierto en datos.gov.co. Es la misma fuente que ya usa la consulta al RUES: gratis,
 *    sin credenciales.
 *  - **Euro**: la TRM no existe para el euro. Se calcula como la TRM × la cotización del
 *    euro en dólares que publica el Banco Central Europeo. Es el mismo cruce que hacen los
 *    bancos para cualquier moneda distinta del dólar.
 *
 * ## Si no hay internet
 *
 * El servidor de un cliente puede no poder salir a internet, y la tasa del día puede no
 * estar publicada todavía. Nada de eso detiene el sistema: se usa la última tasa guardada y
 * la pantalla dice de qué fecha es. También se puede escribir a mano en Configuración →
 * Monedas, y **una tasa escrita a mano no la pisa la automática**: si alguien la escribió,
 * fue a propósito.
 */
class TasaCambioService
{
    private const TIEMPO = 10;

    // ─── Leer ────────────────────────────────────────────────────────────────

    /** La tasa vigente para una fecha: la de ese día, o la última anterior. */
    public function vigente(string $moneda, ?Carbon $fecha = null): ?TasaCambio
    {
        if ($moneda === Monedas::base()) {
            return null;
        }

        return TasaCambio::where('moneda', $moneda)
            ->whereDate('fecha', '<=', ($fecha ?? now())->toDateString())
            ->orderByDesc('fecha')
            ->first();
    }

    /** Pesos por unidad. El peso vale 1; una moneda sin ninguna tasa guardada, null. */
    public function valor(string $moneda, ?Carbon $fecha = null): ?float
    {
        if ($moneda === Monedas::base()) {
            return 1.0;
        }

        $tasa = $this->vigente($moneda, $fecha);

        return $tasa ? (float) $tasa->valor : null;
    }

    /**
     * Un costo en otra moneda, en pesos, con el colchón de Configuración.
     *
     * Null si la moneda no tiene tasa: mejor no calcular que calcular con un uno.
     */
    public function costoEnPesos(float $costo, string $moneda): ?float
    {
        if ($moneda === Monedas::base()) {
            return round($costo, 2);
        }

        $tasa = $this->valor($moneda);

        if ($tasa === null) {
            return null;
        }

        return round($costo * $tasa * (1 + Monedas::colchonPct() / 100), 2);
    }

    /**
     * Las tasas vigentes, como las necesita una pantalla.
     *
     * @return array<string, array{valor: float, fecha: string, fuente: string, de_hoy: bool}|null>
     */
    public function paraInterfaz(): array
    {
        $hoy = now()->toDateString();
        $out = [];

        foreach (Monedas::extranjeras() as $moneda) {
            $tasa = $this->vigente($moneda);

            $out[$moneda] = $tasa ? [
                'valor'  => (float) $tasa->valor,
                'fecha'  => $tasa->fecha->toDateString(),
                'fuente' => $tasa->fuente,
                'de_hoy' => $tasa->fecha->toDateString() === $hoy,
            ] : null;
        }

        return $out;
    }

    // ─── Escribir ────────────────────────────────────────────────────────────

    /** Una tasa escrita por una persona. Manda sobre la automática de ese mismo día. */
    public function registrarManual(string $moneda, float $valor, ?Carbon $fecha = null): TasaCambio
    {
        return TasaCambio::updateOrCreate(
            ['moneda' => $moneda, 'fecha' => ($fecha ?? now())->toDateString()],
            ['valor' => round($valor, 8), 'fuente' => 'manual'],
        );
    }

    /** Las monedas que el Banco Central Europeo publica: con ellas se cruza todo lo automático. */
    private const BCE = ['USD', 'EUR', 'GBP', 'CNY', 'MXN', 'BRL', 'CAD'];

    /**
     * Las monedas cuya tasa se trae sola con la moneda base actual. Las demás se escriben a mano.
     *
     * Con base en pesos colombianos, la TRM da el dólar y todo lo demás se cruza con él. Con
     * otra base, solo hay fuente si la base misma la publica el BCE.
     *
     * @return list<string>
     */
    public function automaticas(): array
    {
        $base = Monedas::base();

        if ($base === 'COP') {
            return self::BCE;
        }

        return in_array($base, self::BCE, true) ? array_values(array_diff(self::BCE, [$base])) : [];
    }

    /**
     * Trae las tasas de hoy de sus fuentes y las guarda.
     *
     * No lanza nunca: devuelve, por moneda, si salió bien y por qué no. Lo llama una tarea
     * programada, y una tarea que revienta a las seis de la mañana no la ve nadie.
     *
     * @return array<string, array{ok: bool, valor?: float, fuente?: string, mensaje: string}>
     */
    public function actualizar(): array
    {
        $hoy  = now()->startOfDay();
        $base = Monedas::base();

        if ($base !== 'COP') {
            return $this->actualizarConBce($hoy, $base);
        }

        $resultado = [];

        $trm = $this->traerTrm($hoy);

        $resultado['USD'] = $trm === null
            ? ['ok' => false, 'mensaje' => 'No se pudo consultar la TRM en datos.gov.co.']
            : $this->guardar('USD', $trm, 'superfinanciera', $hoy);

        // El euro se cruza con la TRM. Sin TRM de la fuente, se cruza con la última
        // guardada: sigue siendo mejor que no tener euro.
        $usd = $trm ?? $this->valor('USD');
        $eurUsd = $this->traerEurUsd();

        if ($usd === null || $eurUsd === null) {
            $resultado['EUR'] = [
                'ok'      => false,
                'mensaje' => $eurUsd === null
                    ? 'No se pudo consultar la cotización del euro en el Banco Central Europeo.'
                    : 'No hay TRM para cruzar el euro.',
            ];
        } else {
            $resultado['EUR'] = $this->guardar('EUR', round($usd * $eurUsd, 4), 'bce', $hoy);
        }

        // Las demás monedas del BCE, cruzadas con el dólar. Si no llegan, no es un error:
        // el euro y el dólar son lo que importa, y las otras se pueden escribir a mano.
        if ($usd !== null) {
            $porDolar = $this->traerCruces('USD', array_diff(self::BCE, ['USD', 'EUR']));

            foreach ($porDolar as $moneda => $cuantas) {
                $resultado[$moneda] = $this->guardar($moneda, round($usd / $cuantas, 8), 'bce', $hoy);
            }
        }

        return $resultado;
    }

    /**
     * Con una base distinta del peso colombiano: todo se cruza directo con el BCE.
     *
     * @return array<string, array{ok: bool, valor?: float, fuente?: string, mensaje: string}>
     */
    private function actualizarConBce(Carbon $hoy, string $base): array
    {
        $objetivo = $this->automaticas();

        if ($objetivo === []) {
            return ['*' => ['ok' => false, 'mensaje' => "La moneda base ({$base}) no tiene fuente automática: escribe las tasas a mano."]];
        }

        $cruces    = $this->traerCruces($base, $objetivo);
        $resultado = [];

        foreach ($objetivo as $moneda) {
            $resultado[$moneda] = isset($cruces[$moneda])
                ? $this->guardar($moneda, round(1 / $cruces[$moneda], 8), 'bce', $hoy)
                : ['ok' => false, 'mensaje' => 'No se pudo consultar la cotización en el Banco Central Europeo.'];
        }

        return $resultado;
    }

    /**
     * Cuántas unidades de cada moneda vale una de `$base`, según el BCE.
     *
     * @param  iterable<string>  $monedas
     * @return array<string, float>  Solo las que llegaron con un valor razonable.
     */
    private function traerCruces(string $base, iterable $monedas): array
    {
        $monedas = array_values(array_diff(is_array($monedas) ? $monedas : iterator_to_array($monedas), [$base]));

        if ($monedas === []) {
            return [];
        }

        try {
            $resp = Http::timeout(self::TIEMPO)->acceptJson()->get(
                (string) config('services.tasas.bce_url'),
                ['base' => $base, 'symbols' => implode(',', $monedas)],
            );

            if (! $resp->successful()) {
                Log::warning('Tasas: los cruces del BCE no llegaron.', ['status' => $resp->status()]);

                return [];
            }

            $out = [];

            foreach ($monedas as $moneda) {
                $valor = (float) ($resp->json("rates.{$moneda}") ?? 0);

                if ($valor > 0) {
                    $out[$moneda] = $valor;
                }
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('Tasas: no se pudieron consultar los cruces. ' . $e->getMessage());

            return [];
        }
    }

    /** @return array{ok: bool, valor: float, fuente: string, mensaje: string} */
    private function guardar(string $moneda, float $valor, string $fuente, Carbon $fecha): array
    {
        if ($moneda === Monedas::base()) {
            return ['ok' => true, 'valor' => 1.0, 'fuente' => $fuente, 'mensaje' => 'Es la moneda base.'];
        }

        $existente = TasaCambio::where('moneda', $moneda)->whereDate('fecha', $fecha->toDateString())->first();

        if ($existente?->fuente === 'manual') {
            return [
                'ok'      => true,
                'valor'   => (float) $existente->valor,
                'fuente'  => 'manual',
                'mensaje' => 'Se dejó la tasa escrita a mano para hoy.',
            ];
        }

        TasaCambio::updateOrCreate(
            ['moneda' => $moneda, 'fecha' => $fecha->toDateString()],
            ['valor' => $valor, 'fuente' => $fuente],
        );

        return ['ok' => true, 'valor' => $valor, 'fuente' => $fuente, 'mensaje' => 'Actualizada.'];
    }

    /**
     * La TRM vigente hoy, de la Superintendencia Financiera.
     *
     * Cada registro dice desde y hasta cuándo rige: la del lunes se publica el viernes y
     * rige todo el fin de semana. Se pide la última que ya empezó a regir.
     */
    private function traerTrm(Carbon $hoy): ?float
    {
        try {
            $resp = Http::timeout(self::TIEMPO)->acceptJson()->get(
                (string) config('services.tasas.trm_url'),
                [
                    '$where' => "vigenciadesde <= '" . $hoy->format('Y-m-d') . "T00:00:00.000'",
                    '$order' => 'vigenciadesde DESC',
                    '$limit' => 1,
                ],
            );

            $valor = (float) ($resp->json('0.valor') ?? 0);

            if (! $resp->successful() || $valor < 100) {
                Log::warning('Tasas: la TRM no llegó bien.', ['status' => $resp->status(), 'cuerpo' => mb_substr($resp->body(), 0, 300)]);

                return null;
            }

            return round($valor, 4);
        } catch (Throwable $e) {
            Log::warning('Tasas: no se pudo consultar la TRM. ' . $e->getMessage());

            return null;
        }
    }

    /** Cuántos dólares vale un euro, según el Banco Central Europeo. */
    private function traerEurUsd(): ?float
    {
        try {
            $resp = Http::timeout(self::TIEMPO)->acceptJson()->get(
                (string) config('services.tasas.bce_url'),
                ['base' => 'EUR', 'symbols' => 'USD'],
            );

            $valor = (float) ($resp->json('rates.USD') ?? 0);

            // Un euro ha valido entre 0,8 y 1,6 dólares desde que existe. Fuera de ahí, la
            // respuesta está mal y convertir con ella dañaría todos los costos en euros.
            if (! $resp->successful() || $valor < 0.5 || $valor > 2.5) {
                Log::warning('Tasas: la cotización del euro no llegó bien.', ['status' => $resp->status()]);

                return null;
            }

            return $valor;
        } catch (Throwable $e) {
            Log::warning('Tasas: no se pudo consultar el euro. ' . $e->getMessage());

            return null;
        }
    }
}
