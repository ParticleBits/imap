<?php

namespace Pb\Imap;

use stdClass
  , Exception
  , Pb\Imap\Imap
  , Pb\Imap\File
  , Zend\Mime\Mime
  , Pb\Imap\Message
  , Zend\Mime\Decode
  , RuntimeException
  , Zend\Mail\Storage
  , Pb\Imap\Attachment
  , Zend\Mail\Storage\Part
  , RecursiveIteratorIterator as Iterator
  , Zend\Mail\Exception\InvalidArgumentException
  , Pb\Imap\Exceptions\MessageSizeLimit as MessageSizeLimitException;

/**
 * Author of this library:
 * @see https://github.com/particlebits/imap
 * @author Mike Gioia https://particlebits.com
 */
class Mailbox
{
    protected $imapHost;
    protected $imapLogin;
    protected $imapFolder;
    protected $imapPassword;
    protected $attachmentsDir;
    protected $imapParams = [];
    protected $imapOptions = 0;
    protected $imapRetriesNum = 0;

    private $options;
    private $memoryLimit = 0;
    private $messageSizeLimit;
    // Default options
    private $defaults = [
        'debug_mode' => FALSE,
        'skip_attachments' => FALSE
    ];

    // Internal reference to IMAP connection
    protected $imapStream;

    // Option constants
    const OPT_DEBUG_MODE = 'debug_mode';
    const OPT_SKIP_ATTACHMENTS = 'skip_attachments';

    /**
     * Sets up a new mailbox object with the IMAP credentials to connect.
     * @param string $hostname
     * @param string $login
     * @param string $password
     * @param string $folder
     * @param string $attachmentsDir
     * @param array $options
     */
    function __construct(
        $hostname,
        $login,
        $password,
        $folder = 'INBOX',
        $attachmentsDir = NULL,
        $options = [] )
    {
        $this->imapLogin = $login;
        $this->imapHost = $hostname;
        $this->imapPassword = $password;
        $this->imapFolder = ( $folder ?: 'INBOX' );
        // 1/6th of the PHP memory limit
        $this->memoryLimit = $this->getMemoryLimit();
        $this->messageSizeLimit = ( $this->memoryLimit )
            ? $this->memoryLimit / 6
            : NULL;

        if ( $attachmentsDir ) {
            if ( ! is_dir( $attachmentsDir ) ) {
                throw new Exception( "Directory '$attachmentsDir' not found" );
            }

            $this->attachmentsDir = rtrim( realpath( $attachmentsDir ), '\\/' );
        }

        // Process the options
        $options = array_intersect_key( $options, $this->defaults );
        $this->options = array_merge( $this->defaults, $options );
    }

    /**
     * Get IMAP mailbox connection stream
     * @return NULL|Zend\Mail\Protocol\Imap
     */
    public function getImapStream()
    {
        if ( ! $this->imapStream ) {
            $this->imapStream = $this->initImapStream();
        }

        return $this->imapStream;
    }

    protected function initImapStream()
    {
        $imapStream = new Imap([
            'ssl' => 'SSL',
            'host' => $this->imapHost,
            'user' => $this->imapLogin,
            'folder' => $this->imapFolder,
            'password' => $this->imapPassword
        ]);

        if ( ! $imapStream ) {
            throw new Exception( 'Failed to connect to IMAP mailbox' );
        }

        return $imapStream;
    }

    public function disconnect()
    {
        $imapStream = $this->getImapStream();

        if ( $imapStream ) {
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
    public function status( $folder = NULL )
    {
        $info = new stdClass;
        $folder = $folder ?: $this->imapFolder;
        $examine = $this->getImapStream()->examineFolder( $folder );

        // Set response defaults
        $info->count = 0;
        $info->flags = [];
        $info->name = $folder;

        if ( ! $examine ) {
            return $info;
        }

        $info->flags = $examine[ 'flags' ];
        $info->count = $examine[ 'exists' ];

        return $info;
    }

    /**
     * Gets a listing of the folders in the selected mailbox.
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties:
     *   messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return RecursiveIteratorIterator
     */
    public function getFolders( $rootFolder = NULL )
    {
        $folders = $this->getImapStream()->getFolders( $rootFolder );

        if ( ! $folders ) {
            return [];
        }

        return new Iterator( $folders, Iterator::SELF_FIRST );
    }

    /**
     * This function performs a search on the mailbox currently opened in the
     * given IMAP stream. For example, to match all unanswered messages sent by
     * Mom, you'd use: "UNANSWERED FROM mom". Searches appear to be case
     * insensitive. This list of criteria is from a reading of the UW c-client
     * source code and may be incomplete or inaccurate (see also RFC2060,
     * section 6.4.4).
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
     * @param bool $uid
     * @return array Message IDs
     */
    public function search( $criteria = 'ALL', $uid = FALSE )
    {
        $messageIds = $this->getImapStream()->search([ $criteria ], $uid );

        return $messageIds ?: [];
    }

    /**
     * Returns an array of UIDs indexed by message number.
     * @return array Unique IDs
     */
    public function getUniqueIds()
    {
        return $this->getImapStream()->getUniqueId();
    }

    /**
     * Returns a count of messages for the selected folder.
     * @param array $flags
     * @return integer
     */
    public function count( $flags = NULL )
    {
        return $this->getImapStream()->countMessages( $flags );
    }

    /**
     * Changes the mailbox to a different folder.
     * @param string $folder
     */
    public function select( $folder )
    {
        return $this->getImapStream()->selectFolder( $folder );
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
     *   ]
     *
     * @param integer $id
     * @throws MessageSizeLimitException
     * @return stdClass
     */
    public function getMessageInfo( $id )
    {
        // Set up the new message
        $messageInfo = new stdClass;
        $messageInfo->charset = NULL;
        $messageInfo->messageNum = $id;
        $messageInfo->flags = new stdClass;
        $messageInfo->headers = new stdClass;

        // Check if the filesize will exceed our memory limit. Since
        // decoding the message explodes new lines, it could vastly
        // overuse memory.
        $messageSize = $this->getImapStream()->getSize( $id );
        $this->checkMessageSize( $messageSize );

        // Get the message info
        $message = $this->getImapStream()->getMessage( $id );
        // Store some internal properties
        $messageInfo->message = $message;
        $messageInfo->size = $messageSize;
        $messageInfo->uid = $this->getImapStream()->getUniqueId( $id );

        // Use this to lookup the headers
        $headers = $message->getHeaders();
        $headerMap = [
            'to' => 'to',
            'cc' => 'cc',
            'bcc' => 'bcc',
            'from' => 'from',
            'date' => 'date',
            'message-id' => 'id',
            'subject' => 'subject',
            'reply-to' => 'replyTo',
            'references' => 'references',
            'in-reply-to' => 'inReplyTo',
            'content-type' => 'contentType'
        ];

        // Add the headers. This could throw exceptions during the
        // header parsing that we want to catch.
        foreach ( $headerMap as $field => $key ) {
            if ( ! isset( $messageInfo->headers->$key ) ) {
                $messageInfo->headers->$key = NULL;
            }

            if ( $headers->has( $field ) ) {
                // These exceptions could be where a ReplyTo header
                // exists along with a Reply-To. The former is invalid
                // and will throw an exception.
                try {
                    $header = $headers->get( $field );
                    $messageInfo->headers->$key = $header;
                }
                catch ( Exception $e ) {}
            }

            // We only want one in case, say, subject came in twice
            if ( count( $messageInfo->headers->$key ) > 1 ) {
                $messageInfo->headers->$key =
                    current( $messageInfo->headers->$key );
            }
        }

        // Try to store the character-set from the content type.
        // This will probably only exist on text/plain parts.
        if ( $headers->has( 'content-type' ) ) {
            $contentType = $this->getSingleHeader( $headers, 'content-type' );
            $messageInfo->charset = $contentType->getParameter( 'charset' );
        }

        // Add in the flags
        $flags = $message->getFlags();
        $flagMap = [
            Storage::FLAG_SEEN => 'seen',
            Storage::FLAG_DRAFT => 'draft',
            Storage::FLAG_RECENT => 'recent',
            Storage::FLAG_DELETED => 'deleted',
            Storage::FLAG_FLAGGED => 'flagged',
            Storage::FLAG_ANSWERED => 'answered'
        ];

        foreach ( $flagMap as $field => $key ) {
            $messageInfo->flags->$key = isset( $flags[ $field ] );
        }

        return $messageInfo;
    }

    /**
     * Get the message data stored in a wrapper object.
     * @param $id
     * @return Message
     */
    public function getMessage( $id )
    {
        $message = new Message;
        $messageInfo = $this->getMessageInfo( $id );

        // Store some common properties
        $message->id = $id;
        $head = $messageInfo->headers;
        $message->messageId = ( isset( $head->id ) )
            ? $head->id->getFieldValue()
            : NULL;
        $message->dateString = ( isset( $head->date ) )
            ? $head->date->getFieldValue()
            : '';
        $time = ( $message->dateString )
            ? strtotime(
                preg_replace(
                    '/\(.*?\)/',
                    '',
                    $message->dateString
                ))
            : time();
        $message->date = date( 'Y-m-d H:i:s', $time );
        $message->subject = ( isset( $head->subject ) )
            ? $head->subject->getFieldValue()
            : '';
        // Try to get the from address and name
        $from = $this->getAddresses( $head, 'from' );
        $message->fromName = ( $from )
            ? $from[ 0 ]->getName()
            : '';
        $message->fromAddress = ( $from )
            ? $from[ 0 ]->getEmail()
            : '';
        $message->fromString = ( isset( $head->from ) )
            ? $head->from->getFieldValue()
            : '';
        // The next fields are the remaining addresses
        $message->to = ( isset( $head->to ) )
            ? $this->getAddresses( $head, 'to' )
            : [];
        $message->toString = ( isset( $head->to ) )
            ? $head->to->getFieldValue()
            : '';
        $message->cc = ( isset( $head->cc ) )
            ? $this->getAddresses( $head, 'cc' )
            : [];
        $message->bcc = ( isset( $head->bcc ) )
            ? $this->getAddresses( $head, 'bcc' )
            : [];
        $message->replyTo = ( isset( $head->replyTo ) )
            ? $this->getAddresses( $head, 'replyTo' )
            : [];
        // This is a message ID that the message is replying to
        $message->inReplyTo = ( isset( $head->inReplyTo ) )
            ? $head->inReplyTo->getFieldValue()
            : NULL;
        // This is a list of other message IDs that this message
        // may reference
        $message->references = ( isset( $head->references ) )
            ? $head->references->getFieldValue()
            : NULL;
        // Set an internal reference to the IMAP protocol message
        $message->setImapMessage( $messageInfo->message );

        // Extend message with messageInfo
        $message->uid = $messageInfo->uid;
        $message->size = $messageInfo->size;
        $message->flags = $messageInfo->flags;
        $message->headers = $messageInfo->headers;
        $message->charset = $messageInfo->charset;
        $message->messageNum = $messageInfo->messageNum;

        // If this is NOT a multipart message, store the plain text
        if ( ! $messageInfo->message->isMultipart() ) {
            $this->processContent( $message, $messageInfo->message );

            return $message;
        }

        // Add all of the message parts. This will save attachments to
        // the message object. We can iterate over the Message object
        // and get each part that way.
        $partNum = 1;

        foreach ( $messageInfo->message as $part ) {
            $part->partNum = $partNum++;
            $this->processPart( $message, $part );
        }

        return $message;
    }

    /**
     * Takes in a headers object and returns an array of addresses
     * for a particular field. If $returnString is true, then the
     * comma-separated list of RFC822 name/emails is returned.
     * @param \Headers $headers
     * @param string $field
     * @return array|string
     */
    protected function getAddresses( $headers, $field, $returnString = FALSE )
    {
        $addresses = [];

        if ( isset( $headers->$field ) && count( $headers->$field ) ) {
            foreach ( $headers->$field->getAddressList() as $address ) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    protected function processPart( Message &$message, Part $part )
    {
        $headers = $part->getHeaders();
        $contentType = ( $headers->has( 'content-type' ) )
            ? $part->getHeaderField( 'content-type' )
            : NULL;

        // If it's a file attachment we want to process all of
        // the attachments and save them to $message->attachments.
        // RFC822 parts are almost ALWAYS wrapping something else
        // so if that's the content type then skip out.
        if ( ( $headers->has( 'x-attachment-id' )
                || $headers->has( 'content-disposition' ) )
            && ! $this->isTextType( $contentType )
            && ! $contentType === Mime::MESSAGE_RFC822 )
        {
            $this->processAttachment( $message, $part );
        }
        // Check if the part is text/plain or text/html and save
        // those as properties on $message.
        else {
            $this->processContent( $message, $part );
        }
    }

    protected function processContent( Message &$message, Part $part )
    {
        $headers = $part->getHeaders();
        $contentType = ( $headers->has( 'content-type' ) )
            ? strtolower( $part->getHeaderField( 'content-type' ) )
            : NULL;

        if ( $this->isMultipartType( $contentType ) ) {
            $boundary = $part->getHeaderField( 'content-type', 'boundary' );

            if ( $boundary ) {
                $subStructs = Decode::splitMessageStruct(
                    $part->getContent(),
                    $boundary );
                $subPartNum = 1;
                $subStructs = ( $subStructs ) ?: [];

                foreach ( $subStructs as $subStruct ) {
                    $subPart = new Part([
                        'content' => $subStruct[ 'body' ],
                        'headers' => $subStruct[ 'header' ]
                    ]);
                    // Recursive call
                    $subPart->partNum = $part->partNum .".". $subPartNum++;
                    $this->processContent( $message, $subPart );
                }
            }
            // Most likely an RFC822 wrapper
            else {
                $wrappedPart = new Part([
                    'raw' => $part->getContent()
                ]);
                $wrappedPart->partNum = $part->partNum;
                $this->processContent( $message, $wrappedPart );
            }
        }
        elseif ( $this->isTextType( $contentType )
            || ! $contentType )
        {
            $this->processTextContent(
                $message,
                $contentType,
                self::convertContent( $part->getContent(), $part->getHeaders() ),
                ( $contentType
                    ? $part->getHeaderField( 'content-type', 'charset' )
                    : 'US-ASCII' ));
        }
        else {
            $this->processAttachment( $message, $part );
        }
    }

    protected function processTextContent( Message &$message, $contentType, $content, $charset )
    {
        if ( $contentType === Mime::TYPE_HTML ) {
            $message->textHtml .= File::convertEncoding( $content, $charset, 'UTF-8' );
        }
        else {
            // Set the charset here if we have one
            $message->charset = $charset;
            $message->textPlain .= File::convertEncoding( $content, $charset, 'UTF-8' );
        }
    }

    protected function processAttachment( Message &$message, Part $part )
    {
        // If attachments are disabled, skip out now
        if ( $this->options[ self::OPT_SKIP_ATTACHMENTS ] === TRUE ) {
            return;
        }

        $name = NULL;
        $filename = NULL;
        $contentType = NULL;
        $headers = $part->getHeaders();
        $attachment = new Attachment( $part );

        // Get the filename and/or name for the attachment. Try the
        // disposition first.
        if ( $headers->has( 'content-disposition' ) ) {
            $name = $part->getHeaderField( 'content-disposition', 'name' );
            $filename = $part->getHeaderField( 'content-disposition', 'filename' );
        }

        if ( $headers->has( 'content-type' ) ) {
            $contentType = strtolower( $part->getHeaderField( 'content-type' ) );
            $name = $name ?: $part->getHeaderField( 'content-type', 'name' );
            $filename = $filename ?: $part->getHeaderField( 'content-type', 'filename' );
        }

        // Store this before overwriting
        $attachment->origName = $name;
        $attachment->origFilename = $filename;

        // Certain mime types don't provide name info but we can try
        // to infer it from the mime type.
        if ( ! $filename && $headers->has( 'content-id' ) ) {
            $filename = trim( $part->getHeaderField( 'content-id' ), " <>" );
        }

        if ( ! $filename && $headers->has( 'x-attachment-id' ) ) {
            $filename = trim( $part->getHeaderField( 'x-attachment-id' ), " <>" );
        }

        // Content-Location can be the URL path to a file. If this exists
        // then try to get the filename from this path.
        if ( ! $filename && $headers->has( 'content-location' ) ) {
            $filename = basename( $part->getHeaderField( 'content-location' ) );
        }

        // If it's a calendar event then let's give it a nice name
        if ( ! $filename && $contentType === 'text/calendar' ) {
            $filename = 'event.ics';
        }

        if ( ! $filename ) {
            $filename = $name;
        }

        if ( ! $filename ) {
            $filename = 'noname';
        }

        // Try to add an extension if it's missing one
        File::addExtensionIfMissing( $filename, $contentType );

        // If we are fortunate enough to get an attachment ID, then
        // use that. Otherwise we want to create on in a deterministic
        // way.
        $attachment->name = $name;
        $attachment->filename = $filename;
        $attachment->mimeType = $contentType;
        $attachment->generateId( $message );

        // Attachments are saved in YYYY/MM directories
        if ( $this->attachmentsDir ) {
            $attachment->generateFilepath( $message, $this->attachmentsDir );
            $this->debug( "Before writing attachment to disk" );
            $attachment->saveToFile();
            $this->debug( "After file_put_contents finished" );
        }

        $message->addAttachment( $attachment );
        $this->debug( "New attachment created" );
    }

    static public function convertContent( $content, $headers, $failOnNoEncode = FALSE )
    {
        $data = NULL;

        if ( $headers->has( 'content-transfer-encoding' ) ) {
            $encoding = $headers
                ->get( 'content-transfer-encoding' )
                ->getFieldValue();

            if ( $encoding === 'base64' ) {
                $data = preg_replace(
                    '~[^a-zA-Z0-9+=/]+~s',
                    '',
                    $content );
                $data = base64_decode( $data );
            }
            elseif ( $encoding === '7bit' ) {
                $data = File::decode7bit( $content );
            }
            elseif ( $encoding === '8bit' ) {
                $data = $content;
            }
            elseif ( $encoding === 'quoted-printable' ) {
                $data = quoted_printable_decode( $content );
            }
        }

        if ( is_null( $data ) ) {
            if ( $failOnNoEncode === TRUE ) {
                throw new RuntimeException(
                    "Missing Content-Transfer-Encoding header. ".
                    "Unsure about how to decode." );
            }

            // Depending on file extension, we may need to base64_decode
            // the data.
            $decoded = base64_decode( $content, TRUE );
            $data = $decoded ?: $content;
        }

        return $data;
    }

    private function isMultipartType( $contentType )
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

    private function isTextType( $contentType )
    {
        return in_array(
            $contentType, [
                Mime::TYPE_XML,
                Mime::TYPE_TEXT,
                Mime::TYPE_HTML,
                Mime::TYPE_ENRICHED,
                Mime::MESSAGE_DELIVERY_STATUS
            ]);
    }

    private function getSingleHeader( $headers, $key )
    {
        $header = $headers->get( $key );

        if ( count( $header ) > 1 ) {
            return current( $header );
        }

        return $header;
    }

    private function getMemoryLimit()
    {
        $val = trim( ini_get( 'memory_limit' ) );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );

        if ( $val == -1 ) {
            return NULL;
        }

        switch ( $last ) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    private function checkMessageSize( $size )
    {
        if ( $this->messageSizeLimit && $size > $this->messageSizeLimit ) {
            throw new MessageSizeLimitException(
                $this->messageSizeLimit,
                $size,
                $this->memoryLimit );
        }
    }

    public function debug( $message )
    {
        if ( ! $this->options[ self::OPT_DEBUG_MODE ] ) {
            return;
        }

        $date = new \DateTime;

        echo sprintf(
            "[%s] %s MB peak, %s MB real, %s MB cur -- %s%s",
            $date->format( 'Y-m-d H:i:s' ),
            number_format(
                memory_get_peak_usage( TRUE ) / 1024 / 1024,
                2 ),
            number_format(
                memory_get_usage( TRUE ) / 1024 / 1024,
                2 ),
            number_format(
                memory_get_usage() / 1024 / 1024,
                2 ),
            $message,
            PHP_EOL );
    }
}
