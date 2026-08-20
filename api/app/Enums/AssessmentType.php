<?php

namespace App\Enums;

enum AssessmentType: string
{
    case Written = 'written';
    case Assignment = 'assignment';
    case Project = 'project';
    case Oral = 'oral';
    case Submission = 'submission';
}
