<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'firma'  => $request->user()->firma,
        ]);
    }

    /** Cambia la foto de perfil. La anterior se borra: no se acumulan fotos viejas en el disco. */
    public function subirFoto(Request $request): RedirectResponse
    {
        $request->validate([
            'foto' => 'required|image|mimes:jpg,jpeg,png,webp|max:3072',
        ], [
            'foto.image' => 'La foto tiene que ser una imagen.',
            'foto.max'   => 'La foto no debe pesar más de 3 MB.',
        ]);

        $user = $request->user();

        try {
            $ruta = $request->file('foto')->store('usuarios', 'public');
        } catch (\Throwable $e) {
            // El servidor del cliente puede no dejar escribir: se dice, no se rompe.
            report($e);
            $ruta = false;
        }

        if (! $ruta) {
            return back()->with('error', 'El servidor no dejó guardar la foto. Avísale a quien administra la instalación.');
        }

        if ($user->foto_path) {
            Storage::disk('public')->delete($user->foto_path);
        }

        $user->update(['foto_path' => $ruta]);

        return back()->with('success', 'Foto actualizada.');
    }

    public function quitarFoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->foto_path) {
            Storage::disk('public')->delete($user->foto_path);
            $user->update(['foto_path' => null]);
        }

        return back()->with('success', 'Se quitó la foto.');
    }

    /**
     * Guarda la firma: dibujada en la pantalla o subida como imagen, siempre como PNG en base64.
     * Sale sola en las cotizaciones, órdenes de producción, órdenes de compra y remisiones.
     */
    public function guardarFirma(Request $request): RedirectResponse
    {
        $request->validate([
            'firma' => ['required', 'string', 'max:700000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
        ], [
            'firma.required' => 'Firma en el recuadro o sube la imagen de tu firma.',
            'firma.regex'    => 'La firma tiene que ser una imagen PNG o JPG.',
            'firma.max'      => 'La imagen de la firma es muy pesada.',
        ]);

        $request->user()->update(['firma' => $request->firma]);

        return back()->with('success', 'Firma guardada. Tus documentos saldrán firmados.');
    }

    public function quitarFirma(Request $request): RedirectResponse
    {
        $request->user()->update(['firma' => null]);

        return back()->with('success', 'Se quitó tu firma.');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
