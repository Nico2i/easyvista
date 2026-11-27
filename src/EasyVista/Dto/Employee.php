<?php

namespace App\EasyVista\Dto;

/** Agent tel que connu d'EasyVista (table AM_EMPLOYEE, GET /employees). */
final class Employee
{
    public function __construct(
        public readonly string $id,
        public readonly string $login,
        /** EasyVista stocke généralement « NOM Prénom » dans LAST_NAME */
        public readonly string $name,
        public readonly string $email = '',
        public readonly string $phone = '',
        public readonly string $departmentPath = '',
        public readonly string $locationPath = '',
    ) {
    }

    /** « CARDON Nicolas » donne « Nicolas » : les mots qui ne sont pas entièrement en majuscules. */
    public function firstName(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = array_filter($words, static fn (string $w) => $w !== mb_strtoupper($w));

        return $first ? implode(' ', $first) : (string) end($words);
    }

    public function initials(): string
    {
        $words = preg_split('/[\s\-]+/u', trim($this->name)) ?: [];
        $letters = array_map(static fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), \array_slice($words, 0, 2));

        return implode('', $letters);
    }
}
