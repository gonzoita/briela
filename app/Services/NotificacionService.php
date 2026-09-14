<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Notificacion;
use App\Models\User;

// Punto único para crear notificaciones internas. Los controladores/modelos
// que disparan eventos llaman a estos métodos; así toda la lógica de "a quién
// le llega" y "si está activada" vive en un solo lugar.
class NotificacionService
{
    /**
     * Catálogo de todos los tipos de aviso, agrupados por módulo, con una
     * etiqueta legible. Es la fuente única para el panel de configuración y
     * para saber qué avisos existen. Cada uno se activa/desactiva con la
     * clave de configuración "notif_{tipo}".
     */
    /**
     * El módulo del que depende cada aviso. Los que no están —chat, menciones, respaldos— van
     * siempre: son del núcleo.
     */
    private const MODULO_DE_TIPO = [
        'op_nueva'                 => 'ops',
        'entrega_proxima'          => 'ops',
        'material_faltante'        => 'inventario',
        'stock_bajo'               => 'inventario',
        'op_a_calidad'             => 'calidad',
        'op_a_reproceso'           => 'calidad',
        'op_lista_despacho'        => 'remisiones',
        'trabajo_asignado'         => 'trabajos',
        'cotizacion_aprobada'      => 'cotizaciones',
        'cotizacion_rechazada'     => 'cotizaciones',
        'cotizacion_sin_respuesta' => 'cotizaciones',
        'lead_nuevo'               => 'crm',
        'lead_repetido'            => 'crm',
        'lead_quieto'              => 'crm',
        'solicitud_compra'         => 'compras',
        'mercancia_recibida'       => 'compras',
        'saldo_vencido'            => 'cartera',
        'evaluacion_por_revisar'   => 'capacitacion',
        'certificado_emitido'      => 'capacitacion',
        'curso_por_vencer'         => 'capacitacion',
        'disciplina_por_firmar'    => 'rrhh',
        'bono_calculado'           => 'rrhh',
        'rrss_publicacion_fallida' => 'rrss',
    ];

    public static function moduloActivoPara(string $tipo): bool
    {
        $modulo = self::MODULO_DE_TIPO[$tipo] ?? null;

        return $modulo === null || \App\Support\Modulos::activo($modulo);
    }

    /**
     * El catálogo que ve Configuración → Notificaciones: sin los avisos de módulos apagados,
     * que no se van a mandar y solo alargarían la lista.
     */
    public static function catalogo(): array
    {
        return collect(static::catalogoCompleto())
            ->map(fn (array $tipos) => array_values(array_filter($tipos, fn ($t) => static::moduloActivoPara($t['tipo']))))
            ->filter()
            ->all();
    }

    public static function catalogoCompleto(): array
    {
        return [
            'Producción' => [
                ['tipo' => 'op_nueva',            'label' => 'Nueva OP para producir'],
                ['tipo' => 'material_faltante',   'label' => 'Material faltante en una OP'],
                ['tipo' => 'op_a_calidad',        'label' => 'OP lista para control de calidad'],
                ['tipo' => 'op_lista_despacho',   'label' => 'OP lista para despachar'],
                ['tipo' => 'op_a_reproceso',      'label' => 'OP devuelta a reproceso por calidad'],
                ['tipo' => 'trabajo_asignado',    'label' => 'Trabajo asignado a un colaborador'],
                ['tipo' => 'entrega_proxima',     'label' => 'Entrega de OP próxima a vencer'],
            ],
            'Comercial' => [
                ['tipo' => 'cotizacion_aprobada',    'label' => 'Cliente aprobó una cotización'],
                ['tipo' => 'cotizacion_rechazada',   'label' => 'Cliente rechazó una cotización'],
                ['tipo' => 'lead_nuevo',             'label' => 'Lead nuevo (cualquier canal)'],
                ['tipo' => 'lead_repetido',          'label' => 'Un lead que ya estaba volvió a escribir'],
                ['tipo' => 'lead_quieto',            'label' => 'Lead sin movimiento hace varios días'],
                ['tipo' => 'whatsapp_mensaje_nuevo', 'label' => 'Mensaje nuevo de WhatsApp'],
                ['tipo' => 'cotizacion_sin_respuesta','label' => 'Cotización sin respuesta (recordatorio)'],
            ],
            'Hilos internos' => [
                ['tipo' => 'chat_mensaje',        'label' => 'Alguien te escribió por el chat'],
                ['tipo' => 'comentario_mencion',  'label' => 'Te mencionaron en un documento'],
                ['tipo' => 'comentario_asignado', 'label' => 'Te asignaron una tarea o solicitud'],
                ['tipo' => 'comentario_resuelto', 'label' => 'Resolvieron tu solicitud'],
            ],
            'Compras' => [
                ['tipo' => 'solicitud_compra',    'label' => 'Solicitud de compra por revisar'],
                ['tipo' => 'mercancia_recibida',  'label' => 'Mercancía recibida'],
                ['tipo' => 'stock_bajo',          'label' => 'Insumo por debajo del stock mínimo'],
            ],
            'Financiero' => [
                ['tipo' => 'saldo_vencido',       'label' => 'Cuota / saldo de OP vencido'],
            ],
            'Capacitación' => [
                ['tipo' => 'evaluacion_por_revisar', 'label' => 'Evaluación por calificar'],
                ['tipo' => 'certificado_emitido',    'label' => 'Certificado emitido'],
                ['tipo' => 'curso_por_vencer',       'label' => 'Curso obligatorio por vencer'],
            ],
            'Recursos Humanos' => [
                ['tipo' => 'disciplina_por_firmar', 'label' => 'Documento disciplinario por firmar'],
                ['tipo' => 'bono_calculado',        'label' => 'Bono del mes calculado'],
            ],
            'Redes Sociales' => [
                ['tipo' => 'rrss_publicacion_fallida', 'label' => 'Publicación programada falló'],
            ],
            'Sistema' => [
                ['tipo' => 'backup_fallido', 'label' => 'El respaldo automático falló'],
            ],
        ];
    }

    /**
     * Crea una notificación para un usuario puntual.
     */
    public function crear(
        int $userId,
        string $tipo,
        string $titulo,
        ?string $mensaje = null,
        ?string $url = null,
        ?string $icono = null,
        ?string $color = null,
    ): void {
        // Cada tipo se puede apagar desde Ajustes con la clave
        // "notif_{tipo}" = "0". Si no existe la config, se asume activada.
        if (Configuracion::get("notif_{$tipo}", '1') === '0') {
            return;
        }

        // Un aviso de un módulo apagado lleva a una pantalla que ya no abre.
        if (! static::moduloActivoPara($tipo)) {
            return;
        }

        Notificacion::create([
            'user_id' => $userId,
            'tipo'    => $tipo,
            'titulo'  => $titulo,
            'mensaje' => $mensaje,
            'url'     => $url,
            'icono'   => $icono,
            'color'   => $color,
        ]);

        // Canal email opcional — apagado por defecto. Solo se manda si en
        // Ajustes se activó "también por email" para este tipo y hay SMTP
        // configurado. Nunca rompe el flujo si el correo falla.
        if (Configuracion::get("notif_{$tipo}_email", '0') === '1') {
            $this->enviarEmail($userId, $titulo, $mensaje, $url);
        }
    }

    private function enviarEmail(int $userId, string $titulo, ?string $mensaje, ?string $url): void
    {
        try {
            $user = User::find($userId);
            if (! $user || ! $user->email) return;

            \App\Services\SmtpConfigService::aplicar();

            $cuerpo = trim(($mensaje ?? '') . ($url ? "\n\n" . url($url) : ''));

            // El asunto lleva el nombre de la empresa que tiene la instalación, no un
            // nombre fijo: el correo lo recibe su gente, y un prefijo ajeno en la bandeja
            // de entrada se lee como spam.
            $marca = \App\Support\Marca::nombreEmpresa();

            \Illuminate\Support\Facades\Mail::raw($cuerpo !== '' ? $cuerpo : $titulo, function ($m) use ($user, $titulo, $marca) {
                $m->to($user->email)->subject("[{$marca}] {$titulo}");
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("No se pudo enviar email de notificación: {$e->getMessage()}");
        }
    }

    /**
     * Crea la misma notificación para todos los usuarios activos que tengan
     * alguno de los roles indicados (ej. avisar a todos los jefes de
     * producción). Evita duplicar si un usuario cae en varios roles.
     */
    public function paraRol(
        array|string $roles,
        string $tipo,
        string $titulo,
        ?string $mensaje = null,
        ?string $url = null,
        ?string $icono = null,
        ?string $color = null,
        ?int $excluirUserId = null,
    ): void {
        $roles = (array) $roles;

        User::whereIn('rol', $roles)
            ->where('activo', true)
            ->when($excluirUserId, fn ($q) => $q->where('id', '!=', $excluirUserId))
            ->pluck('id')
            ->each(fn ($id) => $this->crear($id, $tipo, $titulo, $mensaje, $url, $icono, $color));
    }
}
