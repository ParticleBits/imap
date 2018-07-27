<?php

namespace Pb\Imap;

class Message
{
    public $id;
    public $to = [];
    public $cc = [];
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
    public $references = '';
    public $dateString = '';
    public $fromString = '';
    public $fromAddress = '';
    public $dateReceived = '';
    public $receivedString = '';
    public $originalMessage = '';

    // Reference to IMAP message object
    private $imapMessage;

    /**
     * @var Attachment []
     */
    protected $attachments = [];

    /**
     * Store a new attachment to the internal array.
     *
     * @param Attachment $attachment
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

    public function setImapMessage($imapMessage)
    {
        $this->imapMessage = $imapMessage;
    }

    public function getImapMessage()
    {
        return $this->imapMessage;
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

    public function replaceInternalLinks($baseUri)
    {
        $fetchedHtml = $this->textHtml;
        $baseUri = rtrim($baseUri, '\\/').'/';
        $placeholders = $this->getInternalLinksPlaceholders();

        foreach ($placeholders as $attachmentId => $placeholder) {
            if (isset($this->attachments[$attachmentId])) {
                $basename = basename($this->attachments[$attachmentId]->filePath);
                $fetchedHtml = str_replace(
                    $placeholder,
                    $baseUri.$basename,
                    $fetchedHtml);
            }
        }

        return $fetchedHtml;
    }
}
