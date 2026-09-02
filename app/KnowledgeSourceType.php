<?php

namespace App;

enum KnowledgeSourceType: string
{
    case Text = 'text';
    case File = 'file';
    case Image = 'image';
}
