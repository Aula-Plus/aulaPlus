<?php

namespace App\Enums;

enum CurricularItemType: string
{
    case GeneralCompetency = 'general_competency';
    case CurricularSpace = 'curricular_space';
    case Subject = 'subject';
    case Strand = 'strand';
    case Substrand = 'substrand';
}
