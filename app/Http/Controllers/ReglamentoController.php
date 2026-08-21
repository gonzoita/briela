<?php

namespace App\Http\Controllers;

use App\Models\Reglamento;
use App\Support\Marca;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * El reglamento interno de trabajo: se escribe adentro, se lee afuera.
 *
 * Dos caras del mismo documento. Adentro lo edita quien tenga permiso; afuera lo lee
 * cualquiera con el enlace, sin usuario y sin contraseña — que es el punto: un colaborador
 * nuevo tiene que poder leerlo el primer día, antes de que alguien le cree un acceso.
 */
class ReglamentoController extends Controller
{
    /** La pantalla de edición, con el enlace público y su QR. */
    public function edit(): Response
    {
        $reglamento = Reglamento::principal()->load('actualizadoPor:id,name');

        return Inertia::render('RRHH/Reglamento/Edit', [
            'reglamento' => [
                ...$reglamento->toArray(),
                'url_publica'         => $reglamento->urlPublica(),
                'tiene_contenido'     => $reglamento->tieneContenido(),
                'actualizado_por_nombre' => $reglamento->actualizadoPor?->name,
                'actualizado_el'      => $reglamento->updated_at?->format('d/m/Y H:i'),
            ],
            // El QR se arma aquí y viaja como SVG: así la pantalla no necesita una librería
            // para dibujarlo y el mismo código sirve para imprimirlo.
            'qr' => $this->qrDe($reglamento),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'titulo'        => 'required|string|max:200',
            'contenido'     => 'nullable|string|max:2000000',
            'version'       => 'nullable|string|max:30',
            'vigente_desde' => 'nullable|date',
            'publicado'     => 'boolean',
        ]);

        $reglamento = Reglamento::principal();

        // No se publica un documento vacío: el enlace existiría y quien lo abriera vería una
        // hoja en blanco con el nombre de la empresa encima.
        if (($datos['publicado'] ?? false) && trim(strip_tags($datos['contenido'] ?? '')) === '') {
            return back()->with('error', 'Escribe el reglamento antes de publicarlo: el enlace mostraría una hoja en blanco.');
        }

        $reglamento->update([...$datos, 'actualizado_por' => auth()->id()]);

        return back()->with('success', $reglamento->publicado
            ? 'Reglamento guardado y publicado.'
            : 'Reglamento guardado. Todavía no está publicado.');
    }

    /**
     * Cambia la dirección pública.
     *
     * Lo que se hace cuando el enlace llegó a donde no debía: el anterior deja de responder al
     * instante. Hay que volver a repartir el QR, y por eso la pantalla lo pregunta antes.
     */
    public function regenerarToken(): RedirectResponse
    {
        Reglamento::principal()->update(['token_publico' => Reglamento::tokenNuevo()]);

        return back()->with('success', 'Enlace nuevo generado. El anterior ya no funciona: hay que repartir el QR otra vez.');
    }

    /** El QR como archivo, para imprimirlo o pegarlo en una cartelera. */
    public function qrDescargar(): HttpResponse
    {
        $reglamento = Reglamento::principal();

        return response($this->qrDe($reglamento, 512))
            ->header('Content-Type', 'image/svg+xml')
            ->header('Content-Disposition', 'attachment; filename="reglamento-qr.svg"');
    }

    /**
     * La cara pública. Sin login, sin menú, sin nada que distraiga de leer.
     *
     * Un reglamento despublicado responde 404 y no una pantalla de «no autorizado»: hacia
     * afuera, un documento que la empresa retiró simplemente no existe.
     */
    public function publico(string $token): Response
    {
        $reglamento = Reglamento::where('token_publico', $token)
            ->where('publicado', true)
            ->firstOrFail();

        abort_unless($reglamento->tieneContenido(), 404);

        return Inertia::render('Publico/Reglamento', [
            'reglamento' => [
                'titulo'        => $reglamento->titulo,
                'contenido'     => $reglamento->contenido,
                'version'       => $reglamento->version,
                'vigente_desde' => $reglamento->vigente_desde?->format('d/m/Y'),
                'actualizado_el' => $reglamento->updated_at?->format('d/m/Y'),
            ],
            'empresa' => [
                'nombre' => Marca::nombreEmpresa(),
                'logo'   => Marca::logoUrl(),
            ],
        ]);
    }

    private function qrDe(Reglamento $reglamento, int $tamano = 220): string
    {
        return (string) QrCode::format('svg')->size($tamano)->margin(1)
            ->generate($reglamento->urlPublica());
    }
}
