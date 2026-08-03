<?php

namespace App\Models\Concerns;

use App\Models\RegistroActividad;

/**
 * Trait Auditable
 *
 * Registra automáticamente en `registros_actividad` cada vez que un modelo
 * se crea, se actualiza o se elimina. Se activa agregando `use Auditable;`
 * en el modelo — no requiere configuración adicional.
 *
 * Para dar una etiqueta legible al registro, el modelo puede definir
 * opcionalmente un método `nombreParaAuditoria(): string` (por ejemplo,
 * devolviendo el número de OP, el nombre del cliente, etc.). Si no existe,
 * se usa el id.
 *
 * Para excluir campos sensibles o irrelevantes del diff (contraseñas,
 * tokens, timestamps internos), el modelo puede definir:
 *   protected array $auditableExcept = ['password', 'token_publico'];
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            RegistroActividad::create([
                'user_id'     => auth()->id(),
                'accion'      => 'creado',
                'modelo'      => class_basename($model),
                'modelo_id'   => $model->getKey(),
                'descripcion' => class_basename($model) . ' creado: ' . $model->auditableEtiqueta(),
                'cambios'     => null,
                'ip'          => request()?->ip(),
                'created_at'  => now(),
            ]);
        });

        static::updated(function ($model) {
            $excluidos = array_merge(['updated_at', 'created_at'], $model->auditableExcept ?? []);
            $cambios   = [];

            foreach ($model->getChanges() as $campo => $valorNuevo) {
                if (in_array($campo, $excluidos, true)) {
                    continue;
                }
                $cambios[$campo] = [
                    'antes'   => $model->getOriginal($campo),
                    'despues' => $valorNuevo,
                ];
            }

            if (empty($cambios)) {
                return;
            }

            RegistroActividad::create([
                'user_id'     => auth()->id(),
                'accion'      => 'actualizado',
                'modelo'      => class_basename($model),
                'modelo_id'   => $model->getKey(),
                'descripcion' => class_basename($model) . ' actualizado: ' . $model->auditableEtiqueta(),
                'cambios'     => $cambios,
                'ip'          => request()?->ip(),
                'created_at'  => now(),
            ]);
        });

        static::deleted(function ($model) {
            $accion = (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())
                ? 'eliminado_definitivo'
                : 'eliminado';

            RegistroActividad::create([
                'user_id'     => auth()->id(),
                'accion'      => $accion,
                'modelo'      => class_basename($model),
                'modelo_id'   => $model->getKey(),
                'descripcion' => class_basename($model) . ' eliminado: ' . $model->auditableEtiqueta(),
                'cambios'     => null,
                'ip'          => request()?->ip(),
                'created_at'  => now(),
            ]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($model) {
                RegistroActividad::create([
                    'user_id'     => auth()->id(),
                    'accion'      => 'restaurado',
                    'modelo'      => class_basename($model),
                    'modelo_id'   => $model->getKey(),
                    'descripcion' => class_basename($model) . ' restaurado: ' . $model->auditableEtiqueta(),
                    'cambios'     => null,
                    'ip'          => request()?->ip(),
                    'created_at'  => now(),
                ]);
            });
        }
    }

    public function auditableEtiqueta(): string
    {
        if (method_exists($this, 'nombreParaAuditoria')) {
            return (string) $this->nombreParaAuditoria();
        }

        foreach (['numero', 'nombre', 'titulo', 'name'] as $campo) {
            if (isset($this->attributes[$campo])) {
                return (string) $this->attributes[$campo];
            }
        }

        return '#' . $this->getKey();
    }
}
