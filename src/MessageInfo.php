<?php

/**
 * Message container for use in the Mailbox class.
 */

namespace Pb\Imap;

use stdClass;
use Exception;
use Zend\Mail\Headers;
use Zend\Mail\Storage;

class MessageInfo
{
    public $uid;
    public $size;
    public $flags;
    public $headers;
    public $message;
    public $charset;
    public $rawHeaders;
    public $messageNum;

    const FLAG_MAP = [
        Storage::FLAG_SEEN => 'seen',
        Storage::FLAG_DRAFT => 'draft',
        Storage::FLAG_RECENT => 'recent',
        Storage::FLAG_DELETED => 'deleted',
        Storage::FLAG_FLAGGED => 'flagged',
        Storage::FLAG_ANSWERED => 'answered'
    ];

    const HEADER_MAP = [
        'to' => 'to',
        'cc' => 'cc',
        'bcc' => 'bcc',
        'from' => 'from',
        'date' => 'date',
        'message-id' => 'id',
        'subject' => 'subject',
        'reply-to' => 'replyTo',
        'received' => 'received',
        'references' => 'references',
        'in-reply-to' => 'inReplyTo',
        'content-type' => 'contentType'
    ];

    public function __construct(string $id)
    {
        $this->messageNum = $id;
        $this->flags = new stdClass;
        $this->headers = new stdClass;
    }

    public function addFlags(array $flags)
    {
        foreach (self::FLAG_MAP as $field => $key) {
            $this->flags->$key = isset($flags[$field]);
        }
    }

    public function addHeaders(Headers $headers)
    {
        $this->rawHeaders = $headers->toString();

        // Add the headers. This could throw exceptions during the
        // header parsing that we want to catch.
        foreach (self::HEADER_MAP as $field => $key) {
            if (! isset($this->headers->$key)) {
                $this->headers->$key = null;
            }

            if ($headers->has($field)) {
                // These exceptions could be where a ReplyTo header
                // exists along with a Reply-To. The former is invalid
                // and will throw an exception.
                try {
                    $header = $headers->get($field);
                    $this->headers->$key = $header;
                } catch (Exception $e) {
                    // Ignore
                }
            }

            // We only want one in case, say, subject came in twice
            if (is_a($this->headers->$key, 'ArrayIterator')) {
                $this->headers->$key = $this->headers->$key->current();
            }
        }
    }
}
