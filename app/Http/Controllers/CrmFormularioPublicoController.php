<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\CrmEtapa;
use App\Models\CrmFormulario;
use App\Models\CrmLead;
use App\Services\SmtpConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CrmFormularioPublicoController extends Controller
{
    public function show(string $slug)
    {
        $formulario = CrmFormulario::where('slug', $slug)
            ->where('activo', true)
            ->firstOrFail();

        $siteKey = Configuracion::get('recaptcha_site_key', '');
        return view('formularios.publico', compact('formulario', 'siteKey'));
    }

    public function submit(Request $request, string $slug)
    {
        $formulario = CrmFormulario::where('slug', $slug)
            ->where('activo', true)
            ->firstOrFail();

        // reCAPTCHA v3
        if ($formulario->captcha_activo) {
            $secretKey = Configuracion::get('recaptcha_secret_key', '');
            if ($secretKey) {
                $token  = $request->input('g-recaptcha-response', '');
                $verify = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret'   => $secretKey,
                    'response' => $token,
                ])->json();

                if (!($verify['success'] ?? false) || ($verify['score'] ?? 0) < 0.5) {
                    if ($request->expectsJson()) {
                        return response()->json(['ok' => false, 'mensaje' => 'Verificación de seguridad fallida.'], 422);
                    }
                    return redirect()->back()->withErrors(['captcha' => 'Verificación de seguridad fallida.']);
                }
            }
        }

        // Validación dinámica de campos
        $rules = [];
        foreach ($formulario->campos as $campo) {
            $rules[$campo['nombre']] = ($campo['requerido'] ?? false)
                ? 'required|string|max:500'
                : 'nullable|string|max:500';
        }
        $data = $request->validate($rules);

        $etapaId = $formulario->etapa_id ?? CrmEtapa::orderBy('orden')->value('id');
        $orden   = CrmLead::where('etapa_id', $etapaId)->max('orden_en_etapa') + 1;

        $lead = CrmLead::create([
            'etapa_id'          => $etapaId,
            'responsable_id'    => $this->resolverResponsable($formulario),
            'titulo'            => ($data['nombre'] ?? $data['empresa'] ?? 'Lead') . ' — ' . $formulario->nombre,
            'nombre_contacto'   => $data['nombre'] ?? null,
            'email_contacto'    => $data['email'] ?? null,
            'telefono_contacto' => $data['telefono'] ?? null,
            'empresa_contacto'  => $data['empresa'] ?? null,
            'descripcion'       => $data['mensaje'] ?? null,
            'fuente'            => $formulario->fuente ?? 'Web',
            'estado'            => 'activo',
            'orden_en_etapa'    => $orden,
        ]);

        // Aviso interno al vendedor asignado (o a admin si no hay asignado).
        $notif = app(\App\Services\NotificacionService::class);
        if ($lead->responsable_id) {
            $notif->crear(
                $lead->responsable_id,
                'lead_nuevo',
                'Nuevo lead desde la web',
                $lead->titulo,
                '/crm',
            );
        } else {
            $notif->paraRol(['administrador'], 'lead_nuevo', 'Nuevo lead desde la web', $lead->titulo, '/crm');
        }

        // Email de notificación
        if ($formulario->email_notificacion) {
            try {
                SmtpConfigService::aplicar();

                $htmlCampos = '';
                foreach ($formulario->campos as $campo) {
                    $val      = htmlspecialchars($data[$campo['nombre']] ?? '—');
                    $etiqueta = htmlspecialchars($campo['etiqueta']);
                    $htmlCampos .= "<tr>
                        <td style='padding:8px 16px;color:#666;font-size:13px;border-bottom:1px solid #f3f4f6;width:40%;'>{$etiqueta}</td>
                        <td style='padding:8px 16px;font-weight:600;font-size:13px;border-bottom:1px solid #f3f4f6;'>{$val}</td>
                    </tr>";
                }

                $appUrl = config('app.url');
                $html   = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                  <div style='background:#0A4283;padding:24px 20px;border-radius:12px 12px 0 0;'>
                    <h2 style='color:white;margin:0;font-size:18px;font-weight:700;'>
                      Nuevo lead: {$formulario->nombre}
                    </h2>
                  </div>
                  <div style='background:#f9fafb;padding:20px;border-radius:0 0 12px 12px;'>
                    <table style='width:100%;border-collapse:collapse;background:white;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);'>
                      {$htmlCampos}
                    </table>
                    <div style='margin-top:20px;text-align:center;'>
                      <a href='{$appUrl}/crm'
                        style='background:#0A4283;color:white;padding:11px 28px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;display:inline-block;'>
                        Ver en el CRM
                      </a>
                    </div>
                  </div>
                  <p style='text-align:center;color:#9ca3af;font-size:11px;margin-top:12px;'>
                    SGI Interfrigo &middot; {$appUrl}
                  </p>
                </div>";

                $nombre = $data['nombre'] ?? $data['empresa'] ?? 'Nuevo lead';
                Mail::html($html, function ($m) use ($formulario, $nombre) {
                    $m->to($formulario->email_notificacion)
                      ->subject("Nuevo lead: {$nombre} — {$formulario->nombre}");
                });
            } catch (\Exception $e) {
                Log::warning('Email formulario falló: ' . $e->getMessage());
            }
        }

        // Página de gracias
        if ($formulario->gracias_tipo === 'redirect' && $formulario->gracias_url) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'redirect' => $formulario->gracias_url]);
            }
            return redirect($formulario->gracias_url);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'mensaje' => $formulario->mensaje_exito]);
        }
        return redirect()->back()->with('success', $formulario->mensaje_exito);
    }

    private function resolverResponsable(CrmFormulario $formulario): ?int
    {
        if ($formulario->asignacion_tipo === 'fijo' || empty($formulario->responsables_ids)) {
            return $formulario->responsable_id;
        }

        $ids = $formulario->responsables_ids;

        if ($formulario->asignacion_tipo === 'round_robin') {
            $idx           = ($formulario->round_robin_ultimo) % count($ids);
            $responsableId = $ids[$idx];
            $formulario->increment('round_robin_ultimo');
            return $responsableId;
        }

        if ($formulario->asignacion_tipo === 'ponderado') {
            $pesos = $formulario->responsables_pesos ?? [];
            $pool  = [];
            foreach ($ids as $id) {
                $peso = max(1, (int) ($pesos[$id] ?? 1));
                for ($i = 0; $i < $peso; $i++) {
                    $pool[] = $id;
                }
            }
            if (empty($pool)) return $formulario->responsable_id;
            $idx           = ($formulario->round_robin_ultimo) % count($pool);
            $responsableId = $pool[$idx];
            $formulario->increment('round_robin_ultimo');
            return $responsableId;
        }

        return $formulario->responsable_id;
    }
}
