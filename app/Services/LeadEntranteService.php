<?php

namespace App\Services;

use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmLeadOrigen;
use App\Support\ContextoSede;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta de entrada de leads al embudo.
 *
 * Antes cada canal creaba el lead a su manera —el plugin del sitio web, el
 * formulario público, WhatsApp y la carga manual, cada uno con su propio
 * `CrmLead::create()`—, y ninguno comprobaba si esa persona ya estaba. El resultado
 * era el embudo con la misma persona repetida tres veces, que es exactamente lo que
 * hace que un vendedor deje de confiar en el CRM y se vuelva a su cuaderno.
 *
 * Aquí entra todo, y aquí se decide si es alguien nuevo o alguien que ya estaba
 * volviendo a acercarse.
 */
class LeadEntranteService
{
    public function __construct(private NotificacionService $notificaciones) {}

    /**
     * Registra un contacto entrante.
     *
     * @param  array{
     *     canal: string, nombre?: ?string, email?: ?string, telefono?: ?string,
     *     empresa?: ?string, mensaje?: ?string, detalle?: ?string, pagina?: ?string,
     *     utm_source?: ?string, utm_medium?: ?string, utm_campaign?: ?string,
     *     referencia_externa?: ?string, sede_id?: ?int, responsable_id?: ?int,
     *     etapa_id?: ?int, avisar?: bool
     * }  $datos
     * @return array{lead: CrmLead, nuevo: bool}
     */
    public function registrar(array $datos): array
    {
        $canal    = $datos['canal'] ?? 'otro';
        $email    = $this->normalizarEmail($datos['email'] ?? null);
        $telefono = $this->normalizarTelefono($datos['telefono'] ?? null);

        return DB::transaction(function () use ($datos, $canal, $email, $telefono) {
            $lead  = $this->buscarExistente($email, $telefono);
            $nuevo = $lead === null;

            if ($nuevo) {
                $lead = $this->crear($datos, $email, $telefono);
            } else {
                $this->completarDatosQueFaltaban($lead, $datos, $email, $telefono);
            }

            $this->registrarOrigen($lead, $canal, $datos);

            // Algunos canales ya avisan a su manera (el formulario avisa al
            // vendedor que tiene asignado), así que pueden pedir que no se avise
            // dos veces por lo mismo.
            $avisar = $datos['avisar'] ?? true;

            if (! $avisar) {
                // nada
            } elseif ($nuevo) {
                $this->avisarDeLeadNuevo($lead);
            } else {
                // Que alguien vuelva a escribir es información valiosa: puede
                // significar que está listo para comprar, y el vendedor debería
                // enterarse aunque el lead ya estuviera en su lista.
                $this->avisarDeContactoRepetido($lead, $canal);
            }

            return ['lead' => $lead->fresh('origenes'), 'nuevo' => $nuevo];
        });
    }

    /**
     * ¿Esta persona ya está en el embudo?
     *
     * Se busca por correo y por teléfono, en ese orden. El correo identifica mejor
     * —un teléfono se comparte en una empresa pequeña— pero el teléfono es lo único
     * que llega por WhatsApp.
     *
     * Solo se unifican leads **abiertos**: si alguien compró hace un año y vuelve a
     * escribir, eso es una oportunidad nueva y no un comentario sobre la anterior.
     */
    private function buscarExistente(?string $email, ?string $telefono): ?CrmLead
    {
        if ($email === null && $telefono === null) {
            return null;
        }

        return CrmLead::query()
            ->where('estado', 'activo')
            ->where(function ($q) use ($email, $telefono) {
                if ($email !== null) {
                    $q->orWhereRaw('LOWER(TRIM(email_contacto)) = ?', [$email]);
                }
                if ($telefono !== null) {
                    // Se comparan solo los últimos dígitos: el mismo número llega
                    // como +57 300 123 4567, 3001234567 o 57 300 1234567 según el
                    // canal, y son la misma persona.
                    $q->orWhereRaw(
                        "RIGHT(REGEXP_REPLACE(COALESCE(telefono_contacto,''), '[^0-9]', ''), 10) = ?",
                        [$telefono]
                    );
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function crear(array $datos, ?string $email, ?string $telefono): CrmLead
    {
        $sedeId  = $datos['sede_id'] ?? ContextoSede::id() ?? \App\Models\Sede::where('es_principal', true)->value('id');
        $etapaId = $datos['etapa_id'] ?? CrmEtapa::orderBy('orden')->value('id');

        $orden = (int) CrmLead::where('etapa_id', $etapaId)->max('orden_en_etapa') + 1;

        $nombre = trim((string) ($datos['nombre'] ?? '')) ?: null;

        return CrmLead::create([
            'sede_id'           => $sedeId,
            'etapa_id'          => $etapaId,
            'responsable_id'    => $datos['responsable_id'] ?? null,
            'titulo'            => $this->titulo($datos),
            'nombre_contacto'   => $nombre,
            // Se guarda normalizado, no tal como llegó: un correo con espacios o
            // en mayúsculas se ve descuidado en la ficha y rompe cualquier envío.
            'email_contacto'    => $email,
            'telefono_contacto' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'empresa_contacto'  => trim((string) ($datos['empresa'] ?? '')) ?: null,
            'descripcion'       => $datos['mensaje'] ?? null,
            // Las columnas viejas se siguen llenando con el PRIMER origen: es lo
            // que cuenta para atribuir de dónde salió el negocio, y hay informes
            // que las leen.
            'fuente'            => $datos['detalle'] ?? (CrmLeadOrigen::canales()[$datos['canal'] ?? 'otro']['etiqueta'] ?? 'Otro'),
            'pagina_origen'     => $datos['pagina'] ?? null,
            'utm_source'        => $datos['utm_source'] ?? null,
            'utm_medium'        => $datos['utm_medium'] ?? null,
            'utm_campaign'      => $datos['utm_campaign'] ?? null,
            'estado'            => 'activo',
            'orden_en_etapa'    => $orden,
        ]);
    }

    /**
     * Completa los huecos del lead que ya existía, sin pisar lo que ya tenía.
     *
     * Si entró por WhatsApp sin correo y ahora llega el formulario con correo, se
     * suma. Lo que ya estaba escrito no se toca: puede haberlo corregido a mano un
     * vendedor, y su versión vale más que la del formulario.
     */
    private function completarDatosQueFaltaban(CrmLead $lead, array $datos, ?string $email, ?string $telefono): void
    {
        $cambios = [];

        foreach ([
            'nombre_contacto'   => trim((string) ($datos['nombre'] ?? '')) ?: null,
            'email_contacto'    => $email,
            'telefono_contacto' => trim((string) ($datos['telefono'] ?? '')) ?: null,
            'empresa_contacto'  => trim((string) ($datos['empresa'] ?? '')) ?: null,
        ] as $columna => $valor) {
            if (blank($lead->$columna) && filled($valor)) {
                $cambios[$columna] = $valor;
            }
        }

        // El mensaje nuevo se agrega al final en vez de reemplazar: la conversación
        // completa es lo que le sirve a quien va a llamar.
        if (filled($datos['mensaje'] ?? null)) {
            $cambios['descripcion'] = trim(
                ($lead->descripcion ? $lead->descripcion . "\n\n" : '')
                . '— ' . now()->format('d/m/Y H:i') . ': ' . $datos['mensaje']
            );
        }

        if ($cambios !== []) {
            $lead->update($cambios);
        }
    }

    private function registrarOrigen(CrmLead $lead, string $canal, array $datos): void
    {
        $referencia = $datos['referencia_externa'] ?? null;

        // Si el canal manda su identificador y ya está registrado, es el mismo
        // webhook llegando dos veces: no se duplica el origen.
        if ($referencia !== null && CrmLeadOrigen::where('canal', $canal)->where('referencia_externa', $referencia)->exists()) {
            return;
        }

        CrmLeadOrigen::create([
            'lead_id'            => $lead->id,
            'canal'              => $canal,
            'detalle'            => $datos['detalle'] ?? null,
            'pagina'             => $datos['pagina'] ?? null,
            'utm_source'         => $datos['utm_source'] ?? null,
            'utm_medium'         => $datos['utm_medium'] ?? null,
            'utm_campaign'       => $datos['utm_campaign'] ?? null,
            'referencia_externa' => $referencia,
        ]);
    }

    private function titulo(array $datos): string
    {
        $quien = trim((string) ($datos['nombre'] ?? '')) ?: trim((string) ($datos['empresa'] ?? '')) ?: 'Contacto';
        $canal = CrmLeadOrigen::canales()[$datos['canal'] ?? 'otro']['etiqueta'] ?? 'Otro';

        return mb_substr("{$quien} — {$canal}", 0, 200);
    }

    private function avisarDeLeadNuevo(CrmLead $lead): void
    {
        // 'lead_nuevo' ya está en el catálogo de NotificacionService, así que se
        // reutiliza en vez de inventar un tipo que nadie podría apagar desde
        // Ajustes.
        $this->notificaciones->paraRol(
            'administrador',
            'lead_nuevo',
            'Lead nuevo: ' . $lead->titulo,
            trim(($lead->nombre_contacto ?: 'Sin nombre') . ' · ' . ($lead->telefono_contacto ?: $lead->email_contacto ?: 'sin contacto')),
            '/crm',
        );
    }

    private function avisarDeContactoRepetido(CrmLead $lead, string $canal): void
    {
        $etiqueta = CrmLeadOrigen::canales()[$canal]['etiqueta'] ?? $canal;

        $this->notificaciones->paraRol(
            'administrador',
            'lead_repetido',
            'Volvió a escribir: ' . ($lead->nombre_contacto ?: $lead->titulo),
            "Nuevo contacto por {$etiqueta}. Ya estaba en el embudo.",
            '/crm',
        );
    }

    private function normalizarEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    /** Los últimos 10 dígitos, que es lo que identifica al número sin el prefijo. */
    private function normalizarTelefono(?string $telefono): ?string
    {
        $soloDigitos = preg_replace('/[^0-9]/', '', (string) $telefono);

        return strlen((string) $soloDigitos) >= 7
            ? substr((string) $soloDigitos, -10)
            : null;
    }
}
