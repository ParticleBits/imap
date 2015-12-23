<?php

namespace Pb\Imap;

use Exception
  , Pb\Imap\Message
  , Zend\Mail\Storage\Part;

class Attachment
{
    public $id;
    public $name;
    public $filename;
    public $filepath;
    public $origName;
    public $mimeType;
    public $origFilename;

    // Zend\Mail\Storage\Part
    private $part;

    // Created during generate attachment file paths
    protected $baseDir;
    protected $fileSysName;
    protected $fileDatePath;

    public function __construct( Part $part )
    {
        $this->part = $part;
    }

    /**
     * Generate a new, reproducible attachment ID for a message's
     * attachment part.
     * @param Message $message
     */
    public function generateId( Message $message )
    {
        if ( ! $this->part ) {
            throw new Exception( "Part not set on attachment." );
        }

        $headers = $this->part->getHeaders();
        $this->id = ( $headers->has( 'x-attachment-id' ) )
            ? trim( $this->part->getHeaderField( 'x-attachment-id' ), " <>" )
            : self::generateAttachmentId( $message, $this->part->partNum );
    }

    /**
     * Create an ID for a message attachment. This takes the attributes
     * name, date, filename, etc and hashes the result.
     * @param Message $message
     * @param integer $partNum
     * @return string
     */
    static protected function generateAttachmentId( Message $message, $partNum )
    {
        // Unique ID is a concatenation of the unique ID and a
        // hash of the combined date, from address, subject line,
        // part number, and message ID.
        return md5(
            sprintf(
                "%s-%s-%s-%s-%s",
                $message->date,
                $message->fromAddress,
                $message->subject,
                $partNum,
                $message->messageId
            ));
    }

    /**
     * Generate a new, reproducible filepath for a message's
     * attachment part. This will set the path to be inside a
     * folder like 'YYYY/MM' into the $baseDir location. The
     * date part is determined from the $message's date.
     * @param Message $message
     * @param string $baseDir
     */
    public function generateFilepath( Message $message, $baseDir )
    {
        $replace = [
            '/\s/' => '_',
            '/[^0-9a-zа-яіїє_\.]/iu' => '',
            '/_+/' => '_',
            '/(^_)|(_$)/' => ''
        ];
        $fileSysName = preg_replace(
            '~[\\\\/]~',
            '',
            $message->id .'_'. $this->id .'_'. preg_replace(
                array_keys( $replace ),
                $replace,
                $this->filename
            ));
        $this->baseDir = $baseDir;
        // Truncate the sys name if it's too long. This will throw an
        // error in file_put_contents.
        $fileSysName = substr( $fileSysName, 0, 250 );
        // Create the YYYY/MM directory to put the attachment into
        $this->fileDatePath = sprintf(
            "%s%s%s%s%s",
            $this->baseDir,
            DIRECTORY_SEPARATOR,
            date( 'Y', strtotime( $message->date ) ),
            DIRECTORY_SEPARATOR,
            date( 'm', strtotime( $message->date ) ));
        $this->filepath = $this->fileDatePath
            . DIRECTORY_SEPARATOR
            . $fileSysName;
        $this->fileSysName = $fileSysName;
    }

    /**
     * Save the attachment part as a file to disk.
     */
    public function saveToFile()
    {
        if ( ! $this->id ) {
            throw new Exception( 'This attachment has no ID!' );
        }

        if ( ! $this->filepath || ! $this->fileDatePath ) {
            throw new Exception( 'This attachment has no file path!' );
        }

        // Get the content into a decoded format
        $data = Mailbox::convertContent(
            $this->part->getContent(),
            $this->part->getHeaders(),
            $failOnNoEncode = TRUE );

        // Check if base directory exists and is writable
        $this->checkDirWriteable( $this->baseDir );
        $this->checkDirWriteable( $this->fileDatePath );

        file_put_contents( $this->filepath, $data );
    }

    private function checkDirWriteable( $dir, $create = TRUE, $permission = 0755 )
    {
        if ( file_exists( $dir ) ) {
            if ( ! is_writable( $dir ) ) {
                throw new Exception( "Directory exists but is not writeable: $dir " );
            }
        }
        else {
            // Create the date directory and save the file
            if ( $create && ! @mkdir( $dir, $permission, TRUE ) ) {
                throw new Exception( "Couldn't create directory: $dir" );
            }
        }
    }
}