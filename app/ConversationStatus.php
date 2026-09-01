<?php

namespace App;

enum ConversationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
