<?php

namespace Pb\Imap;

use DateTime;
use Exception;
use Laminas\Mail\Header\AbstractAddressList;
use Laminas\Mail\Headers;
use Laminas\Mail\Storage;
use Laminas\Mail\Storage\Imap as LaminasImap;
use Laminas\Mail\Storage\Part;
use Laminas\Mime\Decode;
use Laminas\Mime\Mime;
use Pb\Imap\Exceptions\MailboxAccessException;
use Pb\Imap\Exceptions\MessageSizeLimitException;
use Pb\Imap\Exceptions\MessageUpdateException;
use RecursiveIteratorIterator as Iterator;
use RuntimeException;
use stdClass;

/**
 * @see https://github.com/particlebits/imap
 *
 * @author Mike Gioia https://particlebits.com
 */
class Mailbox
{
    private $imapHost;
    private $imapLogin;
    private $imapFolder;
    private $imapPassword;
    private $attachmentsDir;
    private $imapParams = [];
    private $imapOptions = 0;
    private $imapRetriesNum = 0;

    private $options;
    private $memoryLimit = 0;
    private $messageSizeLimit;

    // Default options
    private $defaults = [
        self::OPT_DEBUG_MODE => false,
        self::OPT_SKIP_ATTACHMENTS => false,
        self::OPT_SSL => 'SSL'
    ];

    // Internal reference to IMAP connection
    private $imapStream;

    private static $validFlags = [
        Storage::FLAG_SEEN,
        Storage::FLAG_UNSEEN,
        Storage::FLAG_ANSWERED,
        Storage::FLAG_FLAGGED,
        Storage::FLAG_DELETED,
        Storage::FLAG_DRAFT
    ];

    // Option constants
    public const OPT_DEBUG_MODE = 'debug_mode';
    public const OPT_SKIP_ATTACHMENTS = 'skip_attachments';
    public const OPT_SSL = 'ssl';

    /**
     * Sets up a new mailbox object with the IMAP credentials to connect.
     *
     * @throws RuntimeException
     *
     * @param string $attachmentsDir
     */
    public function __construct(
        string $hostname,
        string $login,
        string $password,
        string $folder = 'INBOX',
        string $attachmentsDir = null,
        array $options = []
    ) {
        $this->imapLogin = $login;
        $this->imapHost = $hostname;
        $this->imapPassword = $password;
        $this->imapFolder = ($folder ?: 'INBOX');
        // 1/6th of the PHP memory limit
        $this->memoryLimit = $this->getMemoryLimit();
        $this->messageSizeLimit = $this->memoryLimit
            ? $this->memoryLimit / 6
            : null;

        if ($attachmentsDir) {
            if (! is_dir($attachmentsDir)) {
                throw new RuntimeException("Directory '$attachmentsDir' not found");
            }

            $this->attachmentsDir = rtrim(realpath($attachmentsDir), '\\/');
        }

        // Process the options
        $options = array_intersect_key($options, $this->defaults);
        $this->options = array_merge($this->defaults, $options);
    }

    /**
     * Get IMAP mailbox connection stream.
     *
     * @return Imap
     */
    public function getImapStream()
    {
        if (! $this->imapStream) {
            $this->imapStream = $this->loadImapStream();
        }

        return $this->imapStream;
    }

    /**
     * @throws MailboxAccessException
     */
    protected function loadImapStream()
    {
        try {
            return new LaminasImap([
                'ssl' => $this->options[self::OPT_SSL],
                'host' => $this->imapHost,
                'user' => $this->imapLogin,
                'folder' => $this->imapFolder,
                'password' => $this->imapPassword
            ]);
        } catch (Exception $e) {
            throw new MailboxAccessException();
        }
    }

    public function disconnect()
    {
        $imapStream = $this->getImapStream();

        if ($imapStream) {
            $imapStream->close();
        }
    }

    /**
     * Get information about the current mailbox. Flags and a count
     * of messages are returned in the response array.
     *
     * Returns the information in an object with following properties:
     *   Name - folder name
     *   Count - number of messages in mailbox
     *   Flags - any flags on the folder
     *
     * @return stdClass
     */
    public function status(string $folder = null)
    {
        $info = new stdClass();
        $folder = $folder ?: $this->imapFolder;
        $examine = $this->getImapStream()->examineFolder($folder);

        // Set response defaults
        $info->count = 0;
        $info->flags = [];
        $info->name = $folder;

        if (! $examine) {
            return $info;
        }

        $info->flags = $examine['flags'];
        $info->count = $examine['exists'];

        return $info;
    }

    /**
     * Gets a listing of the folders in the selected mailbox.
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties:
     *   messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return Iterator
     */
    public function getFolders(string $rootFolder = null)
    {
        $folders = $this->getImapStream()->getFolders($rootFolder);

        return new Iterator($folders, Iterator::SELF_FIRST);
    }

    /**
     * This function performs a search on the mailbox currently opened in the
     * given IMAP stream. For example, to match all unanswered messages sent
     * by Mom, you'd use: "UNANSWERED FROM Mom". Searches appear to be case
     * insensitive. This list of criteria is from a reading of the UW c-client
     * source code and may be incomplete or inaccurate.
     *
     * @see RFC2060 section 6.4.4
     *
     * @param string $criteria String, delimited by spaces, in which the
     * following keywords are allowed. Any multi-word arguments (e.g. FROM
     * "joey smith") must be quoted. Results will match all criteria entries.
     *
     *   ALL - return all messages matching the rest of the criteria
     *   ANSWERED - match messages with the \Answered flag set
     *   BCC "string" - match messages with "string" in the Bcc: field
     *   BEFORE "date" - match messages with Date: before "date"
     *   BODY "string" - match messages with "string" in the body of the message
     *   CC "string" - match messages with "string" in the Cc: field
     *   DELETED - match deleted messages
     *   FLAGGED - match messages with the \Flagged (sometimes referred to as
     *     Important or Urgent) flag set
     *   FROM "string" - match messages with "string" in the From: field
     *   KEYWORD "string" - match messages with "string" as a keyword
     *   NEW - match new messages
     *   OLD - match old messages
     *   ON "date" - match messages with Date: matching "date"
     *   RECENT - match messages with the \Recent flag set
     *   SEEN - match messages that have been read (the \Seen flag is set)
     *   SINCE "date" - match messages with Date: after "date"
     *   SUBJECT "string" - match messages with "string" in the Subject:
     *   TEXT "string" - match messages with text "string"
     *   TO "string" - match messages with "string" in the To:
     *   UNANSWERED - match messages that have not been answered
     *   UNDELETED - match messages that are not deleted
     *   UNFLAGGED - match messages that are not flagged
     *   UNKEYWORD "string" - match messages that do not have the keyword "string"
     *   UNSEEN - match messages which have not been read yet
     *
     * @return array Message IDs
     */
    public function search(string $criteria = 'ALL', bool $uid = false)
    {
        $messageIds = $this->getImapStream()->search([$criteria], $uid);

        return $messageIds ?: [];
    }

    /**
     * Returns an array of UIDs indexed by message number.
     *
     * @return array Unique IDs
     */
    public function getUniqueIds()
    {
        return $this->getImapStream()->getUniqueId();
    }

    /**
     * Returns a count of messages for the selected folder.
     *
     * @param array $flags
     *
     * @return int
     */
    public function count(array $flags = null)
    {
        return $this->getImapStream()->countMessages($flags);
    }

    /**
     * Changes the mailbox to a different folder.
     *
     * @return array|null
     */
    public function select(string $folder)
    {
        $this->imapFolder = $folder;

        return $this->getImapStream()->selectFolder($folder);
    }

    /**
     * Sends the expunge command to the mailbox. This removes any
     * messages with the \Deleted flag.
     */
    public function expunge(string $folder = null): void
    {
        if ($folder) {
            $this->select($folder);
        }

        $this->getImapStream()->expunge();
    }

    /**
     * Copies a message to another folder.
     *
     * @return bool
     */
    public function copy(string $folder, int $id)
    {
        return $this->getImapStream()->copyMessage($id, $folder);
    }

    /**
     * Updates flags across multiple messages/folders.
     *
     * @param array $ids Message IDs to update
     * @param array $flags Set of flags to add
     * @param string $folder Optional folder to switch to
     *
     * @throws RunTimeException
     *
     * @return bool
     */
    public function addFlags(array $ids, array $flags = [], string $folder = null)
    {
        return $this->updateFlags($ids, $flags, '+', $folder);
    }

    /**
     * @return bool
     */
    public function removeFlags(array $ids, array $flags = [], string $folder = null)
    {
        return $this->updateFlags($ids, $flags, '-', $folder);
    }

    /**
     * @throws MessageUpdateException
     *
     * @return bool
     */
    private function updateFlags(
        array $ids,
        array $flags,
        string $mode,
        string $folder = null
    ) {
        try {
            if ($folder && $folder !== $this->imapFolder) {
                $this->select($folder);
            }

            $ids = array_filter($ids);
            $flags = array_intersect(self::$validFlags, $flags);

            if (! $flags || ! $ids) {
                return false;
            }

            foreach ($ids as $id) {
                if ('+' === $mode) {
                    $this->getImapStream()->addFlags($id, $flags);
                } else {
                    $this->getImapStream()->removeFlags($id, $flags);
                }
            }
        } catch (Exception $e) {
            $exception = new MessageUpdateException(
                "There was a problem setting the flags for message $id. ".
                ucfirst($e->getMessage())
            );

            throw $exception;
        }

        return true;
    }

    /**
     * Wrapped to retrieve a message number from a unique ID.
     *
     * @return int
     */
    public function getNumberByUniqueId(string $uniqueId)
    {
        return $this->getImapStream()->getNumberByUniqueId($uniqueId);
    }

    /**
     * Fetch headers for listed message IDs. Returns an object in
     * the following format:
     *   uid: integer,
     *   size: integer,
     *   charset: string,
     *   flags: [
     *       seen: bool,
     *       draft: bool,
     *       recent: bool,
     *       deleted: bool,
     *       flagged: bool,
     *       answered: bool
     *   ],
     *   message: Message object
     *   messageNum: Sequence number
     *   headers: [
     *       to: Recipient(s), string
     *       from: Who sent it, string
     *       cc: CC recipient(s), string
     *       bcc: BCC recipient(s), string
     *       id: Unique identifier, string
     *       date: When it was sent, string
     *       replyTo: Who should be replied to
     *       subject: Message's subject, string
     *       contentType: Content type of the message
     *       inReplyTo: ID of message this replying to
     *       references: List of messages this one references
     *   ].
     *
     * @throws MessageSizeLimitException
     *
     * @return MessageInfo
     */
    public function getMessageInfo(int $id)
    {
        // Set up the new message
        $messageInfo = new MessageInfo((string) $id);

        // Check if the filesize will exceed our memory limit. Since
        // decoding the message explodes new lines, it could vastly
        // overuse memory.
        $messageSize = $this->getImapStream()->getSize($id);
        $this->checkMessageSize($messageSize);

        // Get the message info
        $message = $this->getImapStream()->getMessage($id);

        // Store some internal properties
        $messageInfo->message = $message;
        $messageInfo->size = $messageSize;
        $messageInfo->uid = $this->getImapStream()->getUniqueId($id);

        // Use this to lookup the headers
        $headers = $message->getHeaders();
        $messageInfo->addHeaders($headers);

        // Try to store the character-set from the content type.
        // This will probably only exist on text/plain parts.
        if ($headers->has('content-type')) {
            $contentType = $this->getSingleHeader($headers, 'content-type');
            $messageInfo->charset = $contentType->getParameter('charset');
        }

        // Add in the flags and raw content
        $messageInfo->addFlags($message->getFlags());
        $messageInfo->rawContent = $message->getContent();

        return $messageInfo;
    }

    /**
     * Get the message data stored in a wrapper object.
     *
     * @return Message
     */
    public function getMessage(int $id)
    {
        $message = new Message();
        $messageInfo = $this->getMessageInfo($id);

        // Store some common properties
        $message->id = $id;
        $head = $messageInfo->headers;
        $message->messageId = isset($head->id)
            ? $head->id->getFieldValue()
            : null;
        $message->dateString = isset($head->date)
            ? $head->date->getFieldValue()
            : '';
        $message->receivedString = isset($head->received)
            ? $head->received->getFieldValue()
            : '';
        $time = $message->dateString
            ? strtotime($this->getCleanDateString($message->dateString))
            : time();
        $receivedDate = $this->getReceivedDate($message->receivedString);
        $receivedTime = $receivedDate
            ? strtotime($this->getCleanDateString($receivedDate))
            : $time;
        $message->date = gmdate('Y-m-d H:i:s', $time);
        $message->subject = isset($head->subject)
            ? $head->subject->getFieldValue()
            : '';
        $message->dateReceived = gmdate('Y-m-d H:i:s', $receivedTime);
        // Try to get the from address and name
        $from = $this->getAddresses($head, 'from');
        $message->fromName = $from
            ? $from[0]->getName()
            : '';
        $message->fromAddress = $from
            ? $from[0]->getEmail()
            : '';
        $message->fromString = isset($head->from)
            ? $head->from->getFieldValue()
            : '';
        // The next fields are the remaining addresses
        $message->to = isset($head->to)
            ? $this->getAddresses($head, 'to')
            : [];
        $message->toString = isset($head->to)
            ? $head->to->getFieldValue()
            : '';
        $message->cc = isset($head->cc)
            ? $this->getAddresses($head, 'cc')
            : [];
        $message->bcc = isset($head->bcc)
            ? $this->getAddresses($head, 'bcc')
            : [];
        $message->replyTo = isset($head->replyTo)
            ? $this->getAddresses($head, 'replyTo')
            : [];
        // This is a message ID that the message is replying to
        $message->inReplyTo = isset($head->inReplyTo)
            ? $head->inReplyTo->getFieldValue()
            : null;
        // This is a list of other message IDs that this message
        // may reference
        $message->references = isset($head->references)
            ? $head->references->getFieldValue()
            : null;
        // Set an internal reference to the IMAP protocol message
        $message->setImapMessage($messageInfo->message);
        // Set the raw content and headers
        $message->setRaw($messageInfo);

        // Extend message with messageInfo
        $message->uid = $messageInfo->uid;
        $message->size = $messageInfo->size;
        $message->flags = $messageInfo->flags;
        $message->headers = $messageInfo->headers;
        $message->charset = $messageInfo->charset;
        $message->messageNum = $messageInfo->messageNum;

        // If this is NOT a multipart message, store the plain text
        if (! $messageInfo->message->isMultipart()) {
            $this->processContent($message, $messageInfo->message);

            return $message;
        }

        // Add all of the message parts. This will save attachments to
        // the message object. We can iterate over the Message object
        // and get each part that way.
//        $partNum = 1;

        foreach ($messageInfo->message as $part) {
//            $part->partNum = $partNum++;
            $this->processPart($message, $part);
        }

        return $message;
    }

    /**
     * Takes in a headers object and returns an array of addresses
     * for a particular field. If $returnString is true, then the
     * comma-separated list of RFC822 name/emails is returned.
     *
     * @return array|string
     */
    protected function getAddresses(stdClass $headers, string $field, bool $returnString = false)
    {
        $addresses = [];

        if (isset($headers->$field)
            && $headers->$field instanceof AbstractAddressList
        ) {
            foreach ($headers->$field->getAddressList() as $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    protected function processPart(Message &$message, Part $part)
    {
        $headers = $part->getHeaders();
        $contentType = $headers->has('content-type')
            ? $part->getHeaderField('content-type')
            : null;

        // If it's a file attachment we want to process all of
        // the attachments and save them to $message->attachments.
        // RFC822 parts are almost ALWAYS wrapping something else
        // so if that's the content type then skip out.
        if (($headers->has('x-attachment-id')
                || $headers->has('content-disposition'))
            && ! self::isTextType($contentType)
            && Mime::MESSAGE_RFC822 !== $contentType
        ) {
            $this->processAttachment($message, $part);
        } else {
            // Check if the part is text/plain or text/html and save
            // those as properties on $message.
            $this->processContent($message, $part);
        }
    }

    protected function processContent(Message &$message, Part $part)
    {
        $headers = $part->getHeaders();
        $contentType = $headers->has('content-type')
            ? strtolower($part->getHeaderField('content-type'))
            : null;
        $charsetHeader = $contentType
            ? $part->getHeaderField('content-type', 'charset')
            : null;
        $charset = $contentType
            ? ($charsetHeader ?: 'US-ASCII')
            : 'US-ASCII';

        if (self::isMultipartType($contentType)) {
            $boundary = $part->getHeaderField('content-type', 'boundary');

            if ($boundary) {
                $subStructs = Decode::splitMessageStruct(
                    $part->getContent(),
                    $boundary
                );
//                $subPartNum = 1;
                $subStructs = $subStructs ?: [];

                foreach ($subStructs as $subStruct) {
                    $subPart = new Part([
                        'content' => $subStruct['body'],
                        'headers' => $subStruct['header']
                    ]);
                    // Recursive call
//                    $subPart->partNum = $part->partNum.'.'.$subPartNum++;
                    $this->processContent($message, $subPart);
                }
            } else { // Most likely an RFC822 wrapper
                $wrappedPart = new Part([
                    'raw' => $part->getContent()
                ]);
//                $wrappedPart->partNum = $part->partNum;
                $this->processContent($message, $wrappedPart);
            }
        } elseif (self::isTextType($contentType) || ! $contentType) {
            $this->processTextContent(
                $message,
                $contentType ?: '',
                self::convertContent(
                    $part->getContent(),
                    $part->getHeaders(),
                    $contentType
                ),
                $charset
            );
        } else {
            $this->processAttachment($message, $part);
        }
    }

    protected function processTextContent(
        Message &$message,
        string $contentType,
        string $content,
        string $charset
    ): void {
        if (Mime::TYPE_HTML === $contentType) {
            $message->textHtml .= File::convertEncoding($content, $charset, 'UTF-8');
        } else {
            // Set the charset here if we have one
            $message->charset = $charset;
            $message->textPlain .= File::convertEncoding($content, $charset, 'UTF-8');
        }
    }

    protected function processAttachment(Message &$message, Part $part): void
    {
        // If attachments are disabled, skip out now
        if (true === $this->options[self::OPT_SKIP_ATTACHMENTS]) {
            return;
        }

        $name = null;
        $filename = null;
        $contentType = null;
        $headers = $part->getHeaders();
        $attachment = new Attachment($part);

        // Get the filename and/or name for the attachment. Try the
        // disposition first.
        if ($headers->has('content-disposition')) {
            $name = $part->getHeaderField('content-disposition', 'name');
            $filename = $part->getHeaderField('content-disposition', 'filename');
        }

        if ($headers->has('content-type')) {
            $contentType = strtolower($part->getHeaderField('content-type'));
            $name = $name ?: $part->getHeaderField('content-type', 'name');
            $filename = $filename ?: $part->getHeaderField('content-type', 'filename');
        }

        // Store this before overwriting
        $attachment->origName = $name;
        $attachment->origFilename = $filename;

        // Certain mime types don't provide name info but we can try
        // to infer it from the mime type.
        if (! $filename && $headers->has('content-id')) {
            $filename = trim($part->getHeaderField('content-id'), ' <>');
        }

        if (! $filename && $headers->has('x-attachment-id')) {
            $filename = trim($part->getHeaderField('x-attachment-id'), ' <>');
        }

        // Content-Location can be the URL path to a file. If this exists
        // then try to get the filename from this path.
        if (! $filename && $headers->has('content-location')) {
            $filename = basename($part->getHeaderField('content-location'));
        }

        // If it's a calendar event then let's give it a nice name
        if (! $filename && 'text/calendar' === $contentType) {
            $filename = 'event.ics';
        }

        if (! $filename) {
            $filename = $name;
        }

        if (! $filename) {
            $filename = 'noname';
        }

        // Try to add an extension if it's missing one
        File::addExtensionIfMissing($filename, $contentType);

        // If we are fortunate enough to get an attachment ID, then
        // use that. Otherwise, create one in a deterministic way.
        $attachment->name = $name;
        $attachment->filename = $filename;
        $attachment->mimeType = $contentType;
        $attachment->generateId($message);

        // Attachments are saved in YYYY/MM directories
        if ($this->attachmentsDir) {
            $attachment->generateFilepath($message, $this->attachmentsDir);
            $this->debug('Before writing attachment to disk');
            $attachment->saveToFile();
            $this->debug('After file_put_contents finished');
        }

        $message->addAttachment($attachment);
        $this->debug('New attachment created');
    }

    public static function convertContent(
        string $content,
        Headers $headers,
        string $contentType = null,
        bool $failOnNoEncode = false
    ) {
        $data = null;

        if ($headers->has('content-transfer-encoding')) {
            $encoding = $headers
                ->get('content-transfer-encoding')
                ->getFieldValue();

            if ('base64' === $encoding) {
                $data = preg_replace(
                    '~[^a-zA-Z0-9+=/]+~s',
                    '',
                    $content);
                $data = base64_decode($data);
            } elseif ('7bit' === $encoding) {
                $data = File::decode7bit($content);
            } elseif ('8bit' === $encoding) {
                $data = $content;
            } elseif ('quoted-printable' === $encoding) {
                $data = quoted_printable_decode($content);
            }
        }

        if (is_null($data)) {
            // If it's simple text just return it back
            if (self::isTextType($contentType)) {
                return $content;
            }

            if (true === $failOnNoEncode) {
                $exception = new RuntimeException(
                    'Missing Content-Transfer-Encoding header. '.
                    'Unsure about how to decode.'
                );

                throw $exception;
            }

            // Depending on file extension, we may need to
            // base64_decode the data.
            $decoded = base64_decode($content, true);
            $data = $decoded ?: $content;
        }

        return $data;
    }

    private static function isMultipartType(string $contentType = null)
    {
        return in_array(
            $contentType, [
                Mime::MESSAGE_RFC822,
                Mime::MULTIPART_MIXED,
                Mime::MULTIPART_REPORT,
                Mime::MULTIPART_RELATED,
                Mime::MULTIPART_RELATIVE,
                Mime::MULTIPART_ALTERNATIVE
            ]);
    }

    private static function isTextType(string $contentType = null)
    {
        return ! is_null($contentType)
            && in_array($contentType, [
                Mime::TYPE_XML,
                Mime::TYPE_TEXT,
                Mime::TYPE_HTML,
                Mime::TYPE_ENRICHED,
                Mime::MESSAGE_DELIVERY_STATUS
            ]);
    }

    private function getSingleHeader(Headers $headers, string $key)
    {
        $header = $headers->get($key);

        if (is_a($header, 'ArrayIterator') && count($header) > 1) {
            return current($header);
        }

        return $header;
    }

    private function getCleanDateString(string $string)
    {
        return preg_replace('/\(.*?\)/', '', $string);
    }

    private function getReceivedDate(string $string)
    {
        // Work backwards, trying to find a date part
        $parts = array_reverse(explode(';', $string));

        foreach ($parts as $part) {
            if (strtotime($part) > 0) {
                return $part;
            }
        }

        return null;
    }

    private function getMemoryLimit()
    {
        $val = trim(ini_get('memory_limit'));

        if (-1 == $val) {
            return null;
        }

        $num = intval(substr($val, -1));
        $last = strtolower($val[strlen($val) - 1]);

        switch ($last) {
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
            case 'k':
                $num *= 1024;
        }

        return $num;
    }

    private function checkMessageSize(int $size)
    {
        if ($this->messageSizeLimit && $size > $this->messageSizeLimit) {
            $exception = new MessageSizeLimitException(
                $this->messageSizeLimit,
                $size,
                $this->memoryLimit
            );

            throw $exception;
        }
    }

    public function debug(string $message)
    {
        if (! $this->options[self::OPT_DEBUG_MODE]) {
            return;
        }

        $date = new DateTime();

        echo sprintf(
            '[%s] %s MB peak, %s MB real, %s MB cur -- %s%s',
            $date->format('Y-m-d H:i:s'),
            number_format(
                memory_get_peak_usage(true) / 1024 / 1024,
                2),
            number_format(
                memory_get_usage(true) / 1024 / 1024,
                2),
            number_format(
                memory_get_usage() / 1024 / 1024,
                2),
            $message,
            PHP_EOL
        );
    }
}
