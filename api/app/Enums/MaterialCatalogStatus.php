<?php

namespace App\Enums;

/**
 * A material's place in the school's catalog (screen 19). Null = «Solo tuyo».
 * There is no «pending» yet: the approval switch of Usuarios y permisos ›
 * Aprobaciones (screen 25) does not exist, so publishing takes effect on save
 * (P19-DEC-04) and Psicopedagogía and Dirección can take it out.
 */
enum MaterialCatalogStatus: string
{
    case Published = 'published';
    case Removed = 'removed';
}
