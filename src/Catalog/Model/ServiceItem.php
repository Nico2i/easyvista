<?php

namespace App\Catalog\Model;

/** Service du catalogue des demandes (hors demandes d'accès, regroupées dans AccessResource). */
final class ServiceItem
{
    public function __construct(
        public readonly string $title,
        public readonly string $family,
        public readonly string $delay = '',
        public readonly string $validator = '',
        public readonly int $maxQuantity = 0,
        public readonly ?string $code = null,
    ) {
    }
}
