<?php

namespace App\Security;

use App\EasyVista\Dto\Employee;
use Symfony\Component\Security\Core\User\UserInterface;

/** Agent connecté au portail. */
final class PortalUser implements UserInterface
{
    public function __construct(public readonly Employee $employee)
    {
    }

    public function getUserIdentifier(): string
    {
        return '' !== $this->employee->login ? $this->employee->login : $this->employee->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function name(): string
    {
        return $this->employee->name;
    }

    public function firstName(): string
    {
        return $this->employee->firstName();
    }

    public function initials(): string
    {
        return $this->employee->initials();
    }
}
