<?php

namespace App;

enum ExternalActionStatus: string
{
    case Pending = 'pending';
    case Executing = 'executing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';
}
