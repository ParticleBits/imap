<?php

namespace Pb\Imap;

use Exception;
use Zend\Mail\Storage\Part;

class Attachment
{
    public $id;
    public $name;
    public $filename;
    public $filepath;
    public $mimeType;
    public $origName;
    public $origFilename;

    // Zend\Mail\Storage\Part
    private $part;

    // Created during generate attachment file paths
    private $baseDir;
    private $fileSysName;
    private $fileSysPath;
    private $fileDatePath;

    public function __construct(Part $part)
    {
        $this->part = $part;
    }

    /**
     * Generate a new, reproducible attachment ID for a message's
     * attachment part.
     *
     * @param Message $message
     */
    public function generateId(Message $message)
    {
        if (! $this->part) {
            throw new Exception('Part not set on attachment.');
        }

        $headers = $this->part->getHeaders();
        $hasAttachmentId = $headers->has('x-attachment-id');
        $attachmentId = $hasAttachmentId
            ? trim($this->part->getHeaderField('x-attachment-id'), ' <>')
            : null;
        $this->id = $hasAttachmentId && $attachmentId
            ? $attachmentId
            : self::generateAttachmentId($message, $this->part->partNum);
    }

    /**
     * Create an ID for a message attachment. This takes the attributes
     * name, date, filename, etc and hashes the result.
     *
     * @param Message $message
     * @param int $partNum
     *
     * @return string
     */
    protected static function generateAttachmentId(Message $message, int $partNum)
    {
        // Unique ID is a concatenation of the unique ID and a
        // hash of the combined date, from address, subject line,
        // part number, and message ID.
        return md5(sprintf('%s-%s-%s-%s-%s',
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
     *
     * @param Message $message
     * @param string $baseDir
     */
    public function generateFilepath(Message $message, string $baseDir)
    {
        $replace = [
            '/\s/' => '_',
            '/[^0-9a-zа-яіїє_\.]/iu' => '',
            '/_+/' => '_',
            '/(^_)|(_$)/' => ''
        ];
        $timestamp = strtotime($message->date);
        $fileSysName = preg_replace(
            '~[\\\\/]~',
            '',
            $message->id.'_'.$this->id.'_'.preg_replace(
                array_keys($replace),
                $replace,
                $this->filename
            ));
        $this->baseDir = $baseDir;
        // Truncate the system name if it's too long. This will throw
        // an error in file_put_contents.
        $fileSysName = substr($fileSysName, 0, 250);
        // Create the YYYY/MM directory to put the attachment into
        $this->fileDatePath = sprintf(
            '%s%s%s',
            date('Y', $timestamp),
            DIRECTORY_SEPARATOR,
            date('m', $timestamp));
        $this->filepath = $this->fileDatePath
            .DIRECTORY_SEPARATOR
            .$fileSysName;
        $this->fileSysPath = $this->baseDir
            .DIRECTORY_SEPARATOR
            .$this->filepath;
        $this->fileSysName = $fileSysName;
    }

    /**
     * Save the attachment part as a file to disk.
     */
    public function saveToFile()
    {
        if (! $this->id) {
            throw new Exception('This attachment has no ID!');
        }

        if (! $this->fileSysPath) {
            throw new Exception('This attachment has no file path!');
        }

        // Get the content into a decoded format
        $data = Mailbox::convertContent(
            $this->part->getContent(),
            $this->part->getHeaders());
        $dateDir = $this->baseDir.DIRECTORY_SEPARATOR.$this->fileDatePath;

        // Check if base directory exists and is writable
        $this->checkDirWriteable($this->baseDir);
        $this->checkDirWriteable($dateDir);

        file_put_contents($this->fileSysPath, $data);
    }

    /**
     * Concert the object to a JSON string.
     */
    public function toJson()
    {
        return json_encode($this->toArray());
    }

    public function toArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'filename' => $this->filename,
            'filepath' => $this->filepath,
            'mimeType' => $this->mimeType,
            'origName' => $this->origName,
            'origFilename' => $this->origFilename
        ];
    }

    private function checkDirWriteable(string $dir, bool $create = true, int $permission = 0755)
    {
        if (file_exists($dir)) {
            if (! is_writable($dir)) {
                throw new Exception("Directory exists but is not writeable: $dir ");
            }
        } else {
            // Create the date directory and save the file
            if ($create && ! @mkdir($dir, $permission, true)) {
                throw new Exception("Couldn't create directory: $dir");
            }
        }
    }
}
