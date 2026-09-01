<?php

namespace App;

enum CharacterSlug: string
{
    case Doctor = 'doctor';
    case Manager = 'manager';
    case Secretary = 'secretary';
    case Global = 'global';

    /**
     * @return array<int, self>
     */
    public static function specialists(): array
    {
        return [
            self::Doctor,
            self::Manager,
            self::Secretary,
        ];
    }
}
