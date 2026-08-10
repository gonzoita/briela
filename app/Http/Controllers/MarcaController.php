<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\ImagenMarcaService;
use App\Support\Marca;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Identidad visual: color, favicon y título de la pestaña.
 *
 * Se separa de la pantalla general de Configuración porque estos ajustes
 * necesitan controles propios (selector de color, subida de imagen, vista
 * previa en vivo) y no los campos de texto genéricos.
 */
class MarcaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Configuracion/Marca', [
            'marca' => [
                'color'       => Marca::color(),
                'titulo'      => Marca::plantillaTitulo(),
                'favicon_url' => ImagenMarcaService::url('marca_favicon') ?? '',
                'logo_url'    => ImagenMarcaService::url('empresa_logo') ?? '',
                'logo_oscuro_url'    => ImagenMarcaService::url('empresa_logo_oscuro') ?? '',
                'favicon_oscuro_url' => ImagenMarcaService::url('marca_favicon_oscuro') ?? '',
                'empresa'     => Marca::nombreEmpresa(),
                'paleta'      => Marca::paleta(),
                'fuente'      => Marca::fuenteClave(),
            ],
            'color_por_defecto' => Marca::COLOR_POR_DEFECTO,
            // Las tipografías disponibles, con su pila real para que la vista
            // previa se dibuje con la fuente de verdad y no con una aproximación.
            'fuentes' => collect(Marca::fuentes())->map(fn ($f, $clave) => [
                'clave'  => $clave,
                'nombre' => $f['nombre'],
                'pila'   => $f['pila'],
                'nota'   => $f['nota'],
            ])->values(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'color'  => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'titulo' => ['required', 'string', 'max:120'],
            'fuente' => ['required', 'string', 'in:' . implode(',', array_keys(Marca::fuentes()))],
        ], [
            'color.regex' => 'El color debe venir en formato hexadecimal, por ejemplo #2563EB.',
            'fuente.in'   => 'Esa tipografía no está entre las disponibles.',
        ]);

        Configuracion::set('marca_color', strtoupper($data['color']));
        Configuracion::set('marca_titulo', trim($data['titulo']));
        Configuracion::set('marca_fuente', $data['fuente']);

        return back()->with('success', 'Identidad visual actualizada.');
    }

    /**
     * Devuelve la paleta derivada de un color, sin guardar nada.
     * Lo usa la vista previa para mostrar cómo quedaría antes de aplicar.
     */
    public function previsualizar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $base = strtoupper($data['color']);

        return response()->json([
            'marca'        => $base,
            'marca-oscuro' => Marca::ajustarLuz($base, -0.18),
            'marca-suave'  => Marca::ajustarLuz($base, 0.90),
            'marca-medio'  => Marca::ajustarLuz($base, 0.75),
            'marca-texto'  => Marca::textoLegible($base),
        ]);
    }

    public function subirFavicon(Request $request): JsonResponse
    {
        $request->validate([
            // ICO no lo acepta el validador 'image' de Laravel, así que va por
            // extensión. PNG cuadrado es lo que mejor se ve en todos lados.
            'favicon' => 'required|file|mimes:png,ico,svg,jpg,jpeg|max:512',
        ], [
            'favicon.max' => 'El favicon no debe pesar más de 512 KB.',
        ]);

        $url = ImagenMarcaService::guardar($request->file('favicon'), 'marca_favicon');

        return response()->json(['url' => $url]);
    }

    public function quitarFavicon(): RedirectResponse
    {
        ImagenMarcaService::eliminar('marca_favicon');

        return back()->with('success', 'Se volvió al ícono por defecto.');
    }

    public function subirLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|file|mimes:png,svg,jpg,jpeg,webp|max:2048',
        ], [
            'logo.max' => 'El logo no debe pesar más de 2 MB.',
        ]);

        $url = ImagenMarcaService::guardar($request->file('logo'), 'empresa_logo');

        return response()->json(['url' => $url]);
    }

    /**
     * El logo para el modo de noche.
     *
     * Va aparte y no se genera solo: un logo es una imagen, y un texto oscuro sobre
     * fondo oscuro no se arregla invirtiendo colores sin arruinar la marca.
     */
    public function subirLogoOscuro(Request $request): JsonResponse
    {
        $request->validate([
            'logo_oscuro' => 'required|file|mimes:png,svg,jpg,jpeg,webp|max:2048',
        ], [
            'logo_oscuro.max' => 'El logo no debe pesar más de 2 MB.',
        ]);

        $url = ImagenMarcaService::guardar($request->file('logo_oscuro'), 'empresa_logo_oscuro');

        return response()->json(['url' => $url]);
    }

    public function quitarLogoOscuro(): RedirectResponse
    {
        ImagenMarcaService::eliminar('empresa_logo_oscuro');

        return back()->with('success', 'Se quitó el logo del modo de noche.');
    }

    public function subirFaviconOscuro(Request $request): JsonResponse
    {
        $request->validate([
            'favicon_oscuro' => 'required|file|mimes:png,svg,ico,jpg,jpeg,webp|max:1024',
        ], [
            'favicon_oscuro.max' => 'El favicon no debe pesar más de 1 MB.',
        ]);

        $url = ImagenMarcaService::guardar($request->file('favicon_oscuro'), 'marca_favicon_oscuro');

        return response()->json(['url' => $url]);
    }

    public function quitarFaviconOscuro(): RedirectResponse
    {
        ImagenMarcaService::eliminar('marca_favicon_oscuro');

        return back()->with('success', 'Se quitó el favicon del modo de noche.');
    }

    public function quitarLogo(): RedirectResponse
    {
        ImagenMarcaService::eliminar('empresa_logo');

        return back()->with('success', 'Se volvió al logo por defecto.');
    }
}
