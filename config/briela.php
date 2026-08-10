<?php

/*
 * Ajustes propios del producto.
 *
 * El serial se guarda en la base (lo pide el asistente de instalación), pero también
 * se puede fijar por .env: sirve para las instalaciones que Briela aloja, donde el
 * despliegue es automático y nadie va a escribirlo a mano.
 */

return [
    'serial' => env('BRIELA_SERIAL'),

    // El servidor de licencias y actualizaciones.
    'licencia_url' => env('BRIELA_LICENCIA_URL', 'https://superadmin.briela.app'),

    // El proxy de IA, que es por donde sale el asistente.
    'ia_url' => env('BRIELA_IA_URL', 'https://superadmin.briela.app/api/ia'),
];
