<?php

namespace Pb\Imap\Exceptions;

class MessageSizeLimit extends \Exception
{
    public function __construct( $maxSize, $messageSize, $memoryLimit )
    {
        $this->message =
            "Message of size $messageSize exceeds the max of $maxSize. ".
            "You have a PHP memory limit of $memoryLimit which may be ".
            "preventing the message from downloading. If you increase ".
            "this limit the message should download correctly.";
    }
}