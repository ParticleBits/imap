<?php

namespace Pb\Imap\Exceptions;

use Exception;

class FolderAccessException extends Exception
{
    public function __construct(string $folder, string $verb)
    {
        $this->message = sprintf('%s %s, %s',
            'Cannot examine folder',
            $folder,
            'maybe it doesn\'t exist'
        );
    }
}
