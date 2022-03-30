<?php

namespace Pb\Imap\Exceptions;

use Exception;

class MessageSizeLimitException extends Exception
{
    public function __construct(int $maxSize, int $messageSize, int $memoryLimit)
    {
        $maxSize = $this->formatBytes($maxSize);
        $messageSize = $this->formatBytes($messageSize);
        $memoryLimit = $this->formatBytes($memoryLimit);

        $this->message =
            "Message of size $messageSize exceeds the max of $maxSize. ".
            "You have a PHP memory limit of $memoryLimit which may be ".
            'preventing the message from downloading. If you increase '.
            'this limit the message should download correctly.';
    }

    private function formatBytes(int $bytes, int $precision = 3)
    {
        $bytes = max($bytes, 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        // Alternative calculation
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
