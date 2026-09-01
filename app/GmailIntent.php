<?php

namespace App;

enum GmailIntent: string
{
    case None = 'none';
    case Search = 'search';
    case Read = 'read';
    case Clarify = 'clarify';
    case ProposeDraft = 'propose_draft';
    case ProposeSend = 'propose_send';
}
