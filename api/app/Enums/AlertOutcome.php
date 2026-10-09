<?php

namespace App\Enums;

/**
 * The three ways out of an alert (ClickUp 86e3jpzdp; documento vivo screen
 * 11: "nada se cierra con «ya me encargué»"). Each one names a responsible
 * person and a deadline.
 *
 * - Owned: "Me ocupo yo" — the actor takes it.
 * - HandedOff: "Se la paso a otro rol" — another staff member becomes
 *   responsible; opens the thread between roles.
 * - Observing: "La dejo en observación" — the actor keeps it and looks again
 *   on the deadline.
 */
enum AlertOutcome: string
{
    case Owned = 'owned';
    case HandedOff = 'handed_off';
    case Observing = 'observing';
}
