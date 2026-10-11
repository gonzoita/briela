<?php

namespace App\Models;

use App\Services\SecuenciaService;
use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un embarque: lo que llegó de afuera y lo que costó traerlo.
 *
 * Mientras es `borrador` se puede editar y se calcula en vivo. Al liquidarse queda fija: su
 * resultado es la historia de lo que costó ese embarque, y no se reescribe.
 */
class Importacion extends Model
{
    protected $table = 'importaciones';

    protected $fillable = [
        'sede_id', 'numero', 'proveedor_id', 'orden_compra_id', 'estado', 'fecha', 'referencia',
        'pais_origen', 'incoterm', 'moneda', 'tasa', 'tasa_fecha', 'metodo_prorrateo',
        'iva_descontable', 'notas', 'total_fob', 'total_flete_seguro', 'total_arancel', 'total_iva',
        'total_gastos', 'total_costo', 'liquidada_at', 'liquidada_por', 'creado_por',
    ];

    protected $casts = [
        'fecha'           => 'date:Y-m-d',
        'tasa_fecha'      => 'date:Y-m-d',
        'liquidada_at'    => 'datetime',
        'tasa'            => 'decimal:8',
        'iva_descontable' => 'boolean',
    ];

    /** Los Incoterms 2020: una lista cerrada de la Cámara de Comercio Internacional. */
    public const INCOTERMS = [
        'EXW' => 'En fábrica', 'FCA' => 'Franco transportista', 'FAS' => 'Franco al costado del buque',
        'FOB' => 'Franco a bordo', 'CFR' => 'Costo y flete', 'CIF' => 'Costo, seguro y flete',
        'CPT' => 'Transporte pagado hasta', 'CIP' => 'Transporte y seguro pagados hasta',
        'DAP' => 'Entregado en lugar', 'DPU' => 'Entregado y descargado', 'DDP' => 'Entregado derechos pagados',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->sede_id ??= ContextoSede::paraGuardar();
            $model->numero ??= app(SecuenciaService::class)->siguiente('importacion', $model->sede_id);
        });
    }

    public function esBorrador(): bool
    {
        return $this->estado === 'borrador';
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportacionItem::class)->orderBy('orden')->orderBy('id');
    }

    public function costos(): HasMany
    {
        return $this->hasMany(ImportacionCosto::class)->orderBy('orden')->orderBy('id');
    }
}
