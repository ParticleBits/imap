<?php

namespace Pb\Imap\Exceptions;

use Exception;

class FolderAccessException extends Exception
{
    public function __construct(string $folder, string $verb)
    {
        $this->message = sprintf('Cannot %s folder %s, %s',
            $verb,
            $folder,
            'maybe it doesn\'t exist'
        );
    }
}
