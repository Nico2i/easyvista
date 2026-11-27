<?php

namespace App\Service;

use App\Security\PortalUser;
use Symfony\Component\HttpFoundation\Request;

/** Bénéficiaire d'un signalement ou d'une demande : l'agent lui-même ou un·e collègue. */
final class Beneficiary
{
    private function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly bool $isSelf,
    ) {
    }

    public static function self(PortalUser $user): self
    {
        return new self($user->employee->name, $user->employee->email, true);
    }

    /**
     * Lit les champs « pour » (moi | collegue) et « collegue » du formulaire.
     * Le champ collègue accepte « NOM Prénom <adresse@mail> », « NOM Prénom » ou une adresse seule.
     */
    public static function fromRequest(Request $request, PortalUser $user): self
    {
        if ('collegue' !== $request->request->get('pour')) {
            return self::self($user);
        }
        $raw = trim((string) $request->request->get('collegue', ''));
        if ('' === $raw) {
            throw new \InvalidArgumentException('Indiquez le nom de votre collègue.');
        }
        $email = '';
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m)) {
            $email = $m[0];
            $raw = trim(str_replace(['<'.$email.'>', $email, '—'], '', $raw), " \t-–—<>");
        }

        return new self('' !== $raw ? $raw : $email, $email, false);
    }

    public function label(): string
    {
        return $this->name.('' !== $this->email && !$this->isSelf ? ' <'.$this->email.'>' : '');
    }
}
