<?php

namespace App\EasyVista\Exception;

/** Erreur de communication avec EasyVista (réseau, authentification, réponse inattendue…). */
class EasyVistaException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
