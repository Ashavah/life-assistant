<?php

namespace App;

enum CalendarIntent: string
{
    case None = 'none';
    case List = 'list';
    case Clarify = 'clarify';
    case ProposeCreate = 'propose_create';
}
