<?php

namespace App\Exceptions;

class IaException extends \RuntimeException
{
    /** @param int $status el código HTTP del proveedor o del proxy, si lo hubo (0 = no hubo respuesta) */
    public function __construct(string $message, private readonly array $responseData = [], private readonly int $status = 0)
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * ¿Vale la pena reintentar con otro modelo? Sí cuando falló el proveedor o se acabó el
     * tiempo; no cuando el proxy corta por suscripción o por cupo (402) ni cuando la petición
     * misma está mal (4xx): cambiar de modelo gastaría otro intento sin arreglar nada.
     */
    public function admiteRespaldo(): bool
    {
        return $this->status === 0 || $this->status >= 500 || $this->status === 429;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }
}
