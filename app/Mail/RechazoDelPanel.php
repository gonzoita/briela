<?php

namespace App\Mail;

use RuntimeException;

/** El panel recibió el correo pero no lo envió, por una razón que reintentar no arregla. */
class RechazoDelPanel extends RuntimeException {}
