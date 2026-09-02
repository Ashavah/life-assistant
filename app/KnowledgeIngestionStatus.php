<?php

namespace App;

enum KnowledgeIngestionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case AwaitingReview = 'awaiting_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Purged = 'purged';
}
