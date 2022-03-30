<?php

namespace Pb\Imap\Exceptions;

use Exception;

class MailboxAccessException extends Exception
{
    public $message = 'Failed to connect to IMAP mailbox';
}
