<?php

namespace App\Enums;

enum AiOperationType: string
{
    case ResumeAdaptation = 'resume_adaptation';
    case ResumeTranslation = 'resume_translation';
    case StrategicCompetencyClassification = 'strategic_competency_classification';
    case AtsOptimization = 'ats_optimization';
    case AtsScore = 'ats_score';
}
