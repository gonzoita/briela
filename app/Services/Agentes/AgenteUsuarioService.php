<?php

namespace App\Services\Agentes;

use App\Models\AgenteInterno;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * El usuario de servicio con el que actúa un agente.
 *
 * Todo el sistema de consultas depende del usuario autenticado —permisos y sede—, así que en
 * lugar de reescribirlo cada agente actúa **como un usuario con el rol de su cargo**. El techo
 * de lo que puede leer lo fija ese rol, que ya es configurable desde la pantalla de Roles.
 *
 * No es una persona y no puede entrar:
 * - su correo es `@agentes.invalid` (un dominio reservado que no resuelve nunca);
 * - tiene una contraseña aleatoria que nadie conoce;
 * - `User` lo esconde de todas las listas y repartos (vendedores, responsables, comisiones…), y
 *   el proveedor de autenticación consulta ese mismo modelo, así que tampoco inicia sesión.
 */
class AgenteUsuarioService
{
    /** Crea el usuario del agente si no lo tiene, o lo pone al día con su cargo y su sede. */
    public function asegurar(AgenteInterno $agente): User
    {
        $usuario = $agente->usuario()->first();

        $datos = [
            'name'    => 'Agente · ' . $agente->nombre,
            'rol_id'  => $agente->cargo?->rol_id,
            'sede_id' => $agente->sede_id,
            'activo'  => true,
        ];

        if ($usuario) {
            $usuario->forceFill($datos)->save();

            return $usuario;
        }

        // El correo lleva el id: es único y estable, y no depende del nombre, que se puede cambiar.
        $usuario = new User($datos + [
            'email'    => "agente-{$agente->id}@" . User::DOMINIO_AGENTES,
            // El rol legado más bajo: solo cuenta si el rol del cargo no existe, y en ese caso el
            // ejecutor se niega a correr (ver `EjecutorAgente::prepararUsuario`).
            'rol'      => 'operario',
            'password' => Str::random(64),
        ]);
        $usuario->save();

        $agente->forceFill(['usuario_id' => $usuario->id])->save();

        return $usuario;
    }
}
