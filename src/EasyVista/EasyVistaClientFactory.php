<?php

namespace App\EasyVista;

/** Choisit le client EasyVista selon EASYVISTA_MODE (mock ou api). */
final class EasyVistaClientFactory
{
    public function __construct(
        private readonly HttpEasyVistaClient $httpClient,
        private readonly MockEasyVistaClient $mockClient,
    ) {
    }

    public function create(string $mode): EasyVistaClientInterface
    {
        return match (strtolower(trim($mode))) {
            'api', 'http', 'real' => $this->httpClient,
            'mock', '' => $this->mockClient,
            default => throw new \InvalidArgumentException(sprintf('EASYVISTA_MODE invalide : « %s » (attendu : mock ou api).', $mode)),
        };
    }
}
