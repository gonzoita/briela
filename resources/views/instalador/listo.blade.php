@extends('instalador.layout', ['paso' => 3])

@section('contenido')
    <h1>Instalación terminada</h1>
    <p class="sub">Ya puedes entrar con el correo y la contraseña que acabas de elegir.</p>

    <div class="aviso-caja neutro">
        El asistente queda cerrado. Si alguien entra a <code>/instalar</code> ahora,
        se le manda al inicio de sesión.
    </div>

    <form method="GET" action="/login">
        <button type="submit">Entrar al sistema</button>
    </form>

    <ul class="chequeo" style="margin-top:22px">
        <li>
            <span class="icono si">✓</span>
            <span>
                <span class="nombre">Lo primero que conviene hacer</span>
                <span class="detalle">Configuración → Organización: el logo, el color y los datos de la empresa.</span>
            </span>
        </li>
        <li>
            <span class="icono aviso">!</span>
            <span>
                <span class="nombre">Tareas automáticas</span>
                <span class="detalle">
                    Para los avisos de entregas y los recordatorios hace falta una tarea programada
                    en el servidor. Está explicado en la guía de despliegue.
                </span>
            </span>
        </li>
    </ul>
@endsection
