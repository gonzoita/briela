<?php

namespace App\Mail;

use App\Services\LicenciaService;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Entrega el correo al panel de Briela, que lo envía con su proveedor (Brevo o Amazon).
 *
 * Es un transporte de Laravel como el SMTP: todo `Mail::` del sistema sale por aquí sin
 * cambiar una línea. La instalación no tiene credencial de ningún proveedor; se identifica
 * con su serial, igual que con la IA.
 *
 * **Si el panel no lo puede enviar, se usa el SMTP de la instalación**, si lo tiene: un
 * aviso de calidad no puede perderse porque el dominio todavía se está verificando o el
 * panel no responde. Lo que el panel rechaza por una razón de fondo —una dirección que
 * rebotó— no se reintenta.
 *
 * El tipo se marca con la cabecera `X-Briela-Tipo: masivo`; sin ella, es una notificación,
 * que no se cobra.
 */
class TransporteBriela extends AbstractTransport
{
    /** @param  Closure(): ?TransportInterface  $respaldo */
    public function __construct(
        private LicenciaService $licencias,
        private ?Closure $respaldo = null,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $mensaje): void
    {
        $correo = MessageConverter::toEmail($mensaje->getOriginalMessage());

        try {
            $this->alPanel($correo, $mensaje->getEnvelope());
        } catch (RechazoDelPanel $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $respaldo = $this->respaldo ? ($this->respaldo)() : null;

            if (! $respaldo) {
                throw new TransportException('No se pudo enviar por Briela: ' . $e->getMessage(), 0, $e);
            }

            Log::info('Correo: el panel no lo tomó (' . $e->getMessage() . '); sale por el SMTP de la instalación.');
            $respaldo->send($mensaje->getOriginalMessage(), $mensaje->getEnvelope());
        }
    }

    private function alPanel(Email $correo, Envelope $sobre): void
    {
        $serial = $this->licencias->serial();

        if ($serial === null) {
            throw new \RuntimeException('la instalación no tiene serial');
        }

        $tipo = strtolower((string) $correo->getHeaders()->get('X-Briela-Tipo')?->getBodyAsString()) === 'masivo'
            ? 'masivo' : 'transaccional';

        $responder = $correo->getReplyTo()[0] ?? null;
        $de        = $correo->getFrom()[0] ?? null;
        $nombres   = [];
        foreach (array_merge($correo->getTo(), $correo->getCc(), $correo->getBcc()) as $a) {
            $nombres[strtolower($a->getAddress())] = $a->getName();
        }

        $encabezados = [];
        foreach (['List-Unsubscribe', 'List-Unsubscribe-Post'] as $nombre) {
            if ($h = $correo->getHeaders()->get($nombre)) {
                $encabezados[$nombre] = $h->getBodyAsString();
            }
        }

        // Un mensaje por destinatario: el panel cuenta, suprime y cobra por dirección.
        $mensajes = [];
        foreach ($sobre->getRecipients() as $i => $destino) {
            $mensajes[] = array_filter([
                'id'                 => (string) $i,
                'tipo'               => $tipo,
                'para'               => $destino->getAddress(),
                'para_nombre'        => $nombres[strtolower($destino->getAddress())] ?? null,
                'asunto'             => (string) $correo->getSubject(),
                'html'               => $correo->getHtmlBody() !== null ? (string) $correo->getHtmlBody() : null,
                'texto'              => $correo->getTextBody() !== null ? (string) $correo->getTextBody() : null,
                'remitente_nombre'   => $de?->getName() ?: null,
                'responder_a'        => $responder?->getAddress(),
                'responder_a_nombre' => $responder?->getName() ?: null,
                'encabezados'        => $encabezados ?: null,
            ], fn ($v) => $v !== null);
        }

        $resp = Http::timeout(30)
            ->acceptJson()
            ->withHeaders(['X-Briela-Serial' => $serial])
            ->post(rtrim((string) config('briela.licencia_url'), '/') . '/api/correo/enviar', ['mensajes' => $mensajes]);

        if (! $resp->successful()) {
            throw new \RuntimeException($resp->json('mensaje') ?? "el panel respondió {$resp->status()}");
        }

        // Lo que el panel no envió por una razón de fondo no se reintenta por otro lado.
        $fallidos = collect($resp->json('resultados') ?? [])->reject(fn ($r) => ($r['ok'] ?? false) || ($r['suprimido'] ?? false));

        if ($fallidos->isNotEmpty()) {
            throw new RechazoDelPanel($fallidos->pluck('motivo')->filter()->unique()->implode(' '));
        }
    }

    public function __toString(): string
    {
        return 'briela';
    }
}
