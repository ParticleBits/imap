<?php

namespace Pb\Imap;

use Laminas\Mail\Storage\Message as ImapMessage;

class Message
{
    public $id;
    public $to = [];
    public $cc = [];
    public $bcc = [];
    public $uid = '';
    public $size = 0;
    public $date = '';
    public $flags = [];
    public $charset = '';
    public $headers = [];
    public $subject = '';
    public $replyTo = [];
    public $toString = '';
    public $fromName = '';
    public $textHtml = '';
    public $textPlain = '';
    public $messageId = '';
    public $inReplyTo = '';
    public $messageNum = 0;
    public $rawHeaders = '';
    public $rawContent = '';
    public $references = '';
    public $dateString = '';
    public $fromString = '';
    public $fromAddress = '';
    public $dateReceived = '';
    public $receivedString = '';

    // Reference to IMAP message object
    private $imapMessage;

    /**
     * @var Attachment []
     */
    private $attachments = [];

    /**
     * Store a new attachment to the internal array.
     */
    public function addAttachment(Attachment $attachment)
    {
        $this->attachments[$attachment->id] = $attachment;
    }

    /**
     * @return Attachment[]
     */
    public function getAttachments()
    {
        return $this->attachments;
    }

    public function setImapMessage(ImapMessage $imapMessage)
    {
        $this->imapMessage = $imapMessage;
    }

    public function getImapMessage()
    {
        return $this->imapMessage;
    }

    public function setRaw(MessageInfo $messageInfo)
    {
        $this->rawContent = $messageInfo->rawContent;
        $this->rawHeaders = $messageInfo->rawHeaders;
    }

    /**
     * Get array of internal HTML links placeholders.
     *
     * @return array attachmentId => link placeholder
     */
    public function getInternalLinksPlaceholders()
    {
        $matchAll = preg_match_all(
            '/=["\'](ci?d:([\w\.%*@-]+))["\']/i',
            $this->textHtml,
            $matches);

        if ($matchAll) {
            return array_combine($matches[2], $matches[1]);
        }

        return [];
    }

    public function replaceInternalLinks(string $baseUri)
    {
        $fetchedHtml = $this->textHtml;
        $baseUri = rtrim($baseUri, '\\/').'/';
        $placeholders = $this->getInternalLinksPlaceholders();

        foreach ($placeholders as $attachmentId => $placeholder) {
            if (isset($this->attachments[$attachmentId])) {
                $basename = basename($this->attachments[$attachmentId]->filepath);
                $fetchedHtml = str_replace(
                    $placeholder,
                    $baseUri.$basename,
                    $fetchedHtml
                );
            }
        }

        return $fetchedHtml;
    }
}
