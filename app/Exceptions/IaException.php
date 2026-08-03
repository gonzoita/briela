<?php

namespace App\Exceptions;

class IaException extends \RuntimeException
{
    public function __construct(string $message, private readonly array $responseData = [])
    {
        parent::__construct($message);
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }
}
