<?php

namespace App;

enum KnowledgeIngestionDecision: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
