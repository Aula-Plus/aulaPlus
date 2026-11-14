<?php

namespace App\Enums;

/**
 * Which set of unit-planner fields a curricular framework brings (screen 23 ›
 * Planificadores, screen 13). `common` is the base every unit has; the others
 * are the fields an external program asks for (they can be renamed, never
 * removed). Stored as data on `curricular_frameworks.planner_family`, so a new
 * framework is a catalog row, not code (P23-DEC-03).
 */
enum PlannerFamily: string
{
    case Common = 'common';
    case Anep = 'anep';
    case IbMyp = 'ib_myp';
    case IbPyp = 'ib_pyp';
    case Cambridge = 'cambridge';
    // Start with only the common fields; the school adds its own (PO 13/11).
    case Igcse = 'igcse';
    case IbDp = 'ib_dp';
}
