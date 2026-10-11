<?php

namespace App\Http\Controllers;

use App\Models\CuentaBancaria;
use App\Models\MovimientoBancario;
use App\Models\OpCuota;
use App\Services\Banco\LectorExtracto;
use App\Services\Banco\NormalizadorExtracto;
use App\Services\VerificadorPagosService;
use App\Support\Orden;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Verificador de pagos: confirma con el banco que el dinero de las cuotas llegó.
 *
 * Solo lee lo que entra a las cuentas de la empresa; no mueve plata. La lógica vive en
 * {@see VerificadorPagosService}: aquí solo se atienden las pantallas.
 */
class VerificadorPagosController extends Controller
{
    private const ESTADOS = ['por_revisar', 'dudoso', 'pendiente', 'conciliado', 'ignorado', 'todos'];

    public function __construct(private VerificadorPagosService $verificador)
    {
    }

    public function index(Request $request): Response
    {
        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? $request->query('estado') : 'por_revisar';

        $query = MovimientoBancario::with(['cuenta:id,nombre,banco', 'pago:id,op_id,cuota_id,numero_recibo', 'pago.op:id,numero', 'conciliadoPor:id,name'])
            ->when($estado === 'por_revisar', fn ($q) => $q->whereIn('estado', ['pendiente', 'dudoso']))
            ->when(! in_array($estado, ['por_revisar', 'todos'], true), fn ($q) => $q->where('estado', $estado))
            ->when($request->filled('cuenta'), fn ($q) => $q->where('cuenta_bancaria_id', $request->integer('cuenta')))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($w) use ($request) {
                $b = '%'.$request->query('buscar').'%';
                $w->where('descripcion', 'like', $b)->orWhere('referencia', 'like', $b)->orWhere('contraparte', 'like', $b);
            }));

        // El orden lo pide la pantalla; el campo se valida contra esta lista.
        $orden = Orden::aplicar($query, $request, [
            'fecha'  => 'fecha',
            'valor'  => 'valor',
            'estado' => 'estado',
        ], 'fecha', 'desc');

        $movimientos = $query->paginate(25)->withQueryString()->through(fn (MovimientoBancario $m) => [
            'id'          => $m->id,
            'fecha'       => $m->fecha->toDateString(),
            'valor'       => (float) $m->valor,
            'descripcion' => $m->descripcion,
            'referencia'  => $m->referencia,
            'contraparte' => $m->contraparte,
            'estado'      => $m->estado,
            'automatico'  => $m->automatico,
            'nota'        => $m->nota,
            'cuenta'      => $m->cuenta?->nombre,
            'pago'        => $m->pago ? ['id' => $m->pago->id, 'recibo' => $m->pago->numero_recibo, 'op_id' => $m->pago->op_id, 'op_numero' => $m->pago->op?->numero] : null,
            'conciliado_por' => $m->conciliadoPor?->name,
            'conciliado_at'  => $m->conciliado_at?->toIso8601String(),
        ]);

        return Inertia::render('Financiero/VerificadorPagos', [
            'movimientos' => $movimientos,
            'orden'       => $orden,
            'filtros'     => ['estado' => $estado, 'cuenta' => $request->query('cuenta'), 'buscar' => $request->query('buscar')],
            'resumen'     => $this->verificador->resumen(),
            'cuentas'     => CuentaBancaria::orderBy('nombre')->get(['id', 'nombre', 'banco', 'ultimos_digitos', 'activa', 'origen']),
        ]);
    }

    // ─── Cuentas ─────────────────────────────────────────────────────────────

    public function guardarCuenta(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre'          => 'required|string|max:120',
            'banco'           => 'nullable|string|max:80',
            // Solo los últimos dígitos: el número completo no hace falta y no se guarda.
            'ultimos_digitos' => 'nullable|string|max:10',
        ]);

        CuentaBancaria::create($datos);

        return back()->with('success', 'Cuenta agregada. Ya puedes subir su extracto.');
    }

    public function alternarCuenta(CuentaBancaria $cuenta): RedirectResponse
    {
        $cuenta->update(['activa' => ! $cuenta->activa]);

        return back()->with('success', $cuenta->activa ? 'Cuenta activada.' : 'Cuenta desactivada: ya no aparece al subir extractos.');
    }

    // ─── Extracto ────────────────────────────────────────────────────────────

    /**
     * Sube un extracto y concilia los abonos. Si no se reconocen las columnas, responde 422 con los
     * encabezados que hay para que la persona indique cuál es la fecha y cuál el valor.
     */
    public function importar(Request $request, LectorExtracto $lector, NormalizadorExtracto $normalizador): JsonResponse
    {
        $datos = $request->validate([
            'cuenta_id'          => 'required|exists:cuentas_bancarias,id',
            'archivo'            => 'required|file|max:10240',
            'columnas'           => 'nullable|array',
            'columnas.fecha'     => 'nullable|integer|min:0',
            'columnas.valor'     => 'nullable|integer|min:0',
            'columnas.descripcion' => 'nullable|integer|min:0',
            'columnas.referencia'  => 'nullable|integer|min:0',
        ]);

        $cuenta  = CuentaBancaria::findOrFail($datos['cuenta_id']);
        $archivo = $request->file('archivo');

        try {
            $filas = $lector->leer($archivo->getRealPath(), $archivo->getClientOriginalExtension());

            if ($filas === []) {
                throw new RuntimeException('El archivo está vacío.');
            }

            // Si la persona indicó las columnas, se usan; si no, se adivinan.
            $indicadas = array_filter($datos['columnas'] ?? [], fn ($v) => $v !== null && $v !== '');

            if (isset($indicadas['fecha'], $indicadas['valor'])) {
                $encabezado = $this->filaDeEncabezado($filas, (int) $indicadas['fecha'], (int) $indicadas['valor'], $normalizador);
                $columnas   = array_map('intval', $indicadas);
            } else {
                $detectado  = $normalizador->detectar($filas);
                $encabezado = $detectado['encabezado'];
                $columnas   = $detectado['columnas'];
            }

            $leido = $normalizador->normalizar($filas, $encabezado, $columnas);
        } catch (RuntimeException $e) {
            return response()->json([
                'message'      => $e->getMessage(),
                // Las primeras filas, para que pueda señalar las columnas.
                'vista_previa' => isset($filas) ? array_slice($filas, 0, 8) : [],
            ], 422);
        }

        $resumen = $this->verificador->importar($cuenta, $leido['abonos'], 'extracto', auth()->id());

        return response()->json($resumen + ['salidas' => $leido['salidas'], 'ilegibles' => $leido['invalidas']]);
    }

    /** Con columnas indicadas a mano, el encabezado es la fila anterior a la primera con una fecha válida en esa columna. */
    private function filaDeEncabezado(array $filas, int $colFecha, int $colValor, NormalizadorExtracto $normalizador): int
    {
        foreach ($filas as $i => $fila) {
            if ($normalizador->fecha($fila[$colFecha] ?? '') !== null && $normalizador->dinero($fila[$colValor] ?? '') !== null) {
                return max(0, $i - 1);
            }
        }

        throw new RuntimeException('Con esas columnas no encontré ninguna fila con fecha y valor. Revisa cuál es cuál.');
    }

    // ─── Conciliar a mano ────────────────────────────────────────────────────

    public function candidatas(MovimientoBancario $movimiento): JsonResponse
    {
        return response()->json($this->verificador->candidatas($movimiento));
    }

    public function asignar(Request $request, MovimientoBancario $movimiento): RedirectResponse
    {
        $datos = $request->validate(['cuota_id' => 'required|exists:op_cuotas,id']);

        try {
            $this->verificador->aplicar($movimiento, OpCuota::findOrFail($datos['cuota_id']), auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pago registrado y confirmado con el banco.');
    }

    public function ignorar(Request $request, MovimientoBancario $movimiento): RedirectResponse
    {
        $datos = $request->validate(['nota' => 'nullable|string|max:290']);

        try {
            $this->verificador->ignorar($movimiento, $datos['nota'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Abono ignorado.');
    }

    public function restaurar(MovimientoBancario $movimiento): RedirectResponse
    {
        $this->verificador->restaurar($movimiento);

        return back()->with('success', 'El abono volvió a la bandeja.');
    }

    public function deshacer(MovimientoBancario $movimiento): RedirectResponse
    {
        try {
            $this->verificador->deshacer($movimiento);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Conciliación deshecha: el pago se borró y la cuota volvió a deber su valor.');
    }
}
