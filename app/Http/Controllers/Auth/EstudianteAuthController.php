<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CapacitacionEstudiante;
use App\Models\InvitacionCapacitacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EstudianteAuthController extends Controller
{
    public function mostrarInvitacion(string $token): Response
    {
        $invitacion = InvitacionCapacitacion::where('token', $token)->firstOrFail();

        if (! $invitacion->estaVigente()) {
            return Inertia::render('Auth/RegistroEstudiante', [
                'invitacion' => null,
                'mensaje'    => $invitacion->usado_at
                    ? 'Esta invitación ya fue utilizada.'
                    : 'Esta invitación ha expirado.',
            ]);
        }

        return Inertia::render('Auth/RegistroEstudiante', [
            'invitacion' => [
                'token'           => $invitacion->token,
                'email'           => $invitacion->email,
                'nombre_sugerido' => $invitacion->nombre_sugerido,
            ],
            'mensaje' => null,
        ]);
    }

    public function registrar(Request $request, string $token): RedirectResponse
    {
        $invitacion = InvitacionCapacitacion::where('token', $token)->firstOrFail();

        abort_unless($invitacion->estaVigente(), 403, 'La invitación no es válida.');

        $data = $request->validate([
            'nombre'   => 'required|string|max:150',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $estudiante = CapacitacionEstudiante::create([
            'nombre'     => $data['nombre'],
            'email'      => $invitacion->email,
            'password'   => Hash::make($data['password']),
            'tipo'       => $invitacion->tipo,
            'cliente_id' => $invitacion->cliente_id,
            'activo'     => true,
        ]);

        $invitacion->update(['usado_at' => now()]);

        Auth::guard('estudiante')->login($estudiante);

        return redirect('/portal-capacitacion');
    }

    public function mostrarLogin(): Response
    {
        return Inertia::render('Auth/LoginEstudiante');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::guard('estudiante')->attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $request->session()->regenerate();

        return redirect('/portal-capacitacion');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('estudiante')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/portal-capacitacion/login');
    }
}
