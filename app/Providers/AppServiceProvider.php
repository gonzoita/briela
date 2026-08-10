<?php

namespace App\Providers;

use App\Support\Marca;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // La hora del sistema sale de la sede principal, no de un valor fijo en la
        // configuración: una empresa con sedes en husos distintos necesita decidir
        // en cuál vive su operación. Si la base todavía no existe, esto no estorba.
        \App\Support\HoraSistema::aplicar();

        // Color de marca para las vistas que no pueden usar variables CSS.
        //
        // En la interfaz el color entra como var(--marca) desde app.blade.php,
        // pero dompdf no resuelve variables CSS: los PDF necesitan el valor ya
        // calculado. Antes estaba escrito a mano en cada plantilla, así que un
        // cliente podía cambiar su color de marca y sus PDF seguían saliendo
        // con el azul de fábrica.
        //
        // Se resuelve una vez por vista renderizada, y solo para estas
        // carpetas: así no se consulta la configuración en cada petición ni
        // durante las migraciones, cuando la tabla todavía no existe.
        View::composer(['pdf.*', 'comisiones.*', 'formularios.*'], function ($view) {
            $view->with([
                'marcaColor'    => Marca::color(),
                'marcaNombre'   => Marca::nombreEmpresa(),
                // Dos formas del logo, porque estas carpetas mezclan PDF y HTML:
                // dompdf necesita la ruta en disco (no sabe leer una URL), y el
                // formulario público es una página web, que necesita la URL.
                // La ruta es null mientras la empresa no haya subido logo.
                'marcaLogoPath' => Marca::logoPath(),
                'marcaLogoUrl'  => Marca::logoUrl(),
                'marcaEmail'    => \App\Models\Configuracion::get('empresa_email', ''),
                'marcaWeb'      => \App\Models\Configuracion::get('empresa_web', ''),
            ]);
        });
    }
}
