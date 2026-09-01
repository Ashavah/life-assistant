<?php

namespace App;

enum DriveIntent: string
{
    case None = 'none';
    case Search = 'search';
    case Read = 'read';
    case Clarify = 'clarify';
    case ProposeCreateFolder = 'propose_create_folder';
    case ProposeCreateDocument = 'propose_create_document';
}
