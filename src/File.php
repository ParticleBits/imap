<?php

/**
 * Mapping of mime type to file extension(s).
 */

namespace Pb\Imap;

class File
{
    /**
     * Looks for the extension on the filename. If none is found,
     * it tries to find one from the lookup array. If none is still
     * found use the specified default.
     * @param string $filename
     * @param string $mimeType
     * @param string $default
     */
    static public function addExtensionIfMissing( &$filename, $mimeType, $default = NULL )
    {
        if ( ! $filename || ! strlen( $filename ) ) {
            return NULL;
        }

        $pieces = explode( '.', $filename );
        $extension = ( strpos( $filename, "." ) !== FALSE )
            ? end( $pieces )
            : NULL;

        if ( $extension ) {
            return NULL;
        }

        if ( isset( self::$mimeExtensions[ $mimeType ] ) ) {
            $filename = $filename .".". self::$mimeExtensions[ $mimeType ];
        }
        elseif ( $default ) {
            $filename = $filename .".". $default;
        }
    }

    /**
     * Converts a string from one encoding to another.
     * @param string $string
     * @param string $fromEncoding
     * @param string $toEncoding
     * @return string Converted string if conversion was successful, or the
     *   original string if not
     */
    static public function convertEncoding( $string, $fromEncoding, $toEncoding )
    {
        if ( ! $fromEncoding ) {
            return $string;
        }

        $convertedString = NULL;

        if ( $string && $fromEncoding != $toEncoding ) {
            $convertedString = @iconv(
                $fromEncoding,
                $toEncoding . '//IGNORE',
                $string );

            if ( ! $convertedString && extension_loaded( 'mbstring' ) ) {
                $convertedString = @mb_convert_encoding(
                    $string,
                    $toEncoding,
                    $fromEncoding );
            }
        }

        return $convertedString ?: $string;
    }

    /**
     * Decodes 7bit text.
     * @param string $string
     * @return string
     */
    static public function decode7Bit( $string )
    {
        // If there are no spaces on the first line, assume that the
        // body is actually base64-encoded, and decode it.
        $lines = explode( "\r\n", $string );
        $firstLineWords = explode( ' ', $lines[ 0 ] );

        if ( $firstLineWords[ 0 ] == $lines[ 0 ] ) {
            $string = base64_decode( $string );
        }

        // Manually convert common encoded characters into their
        // UTF-8 equivalents.
        $characters = [
            '=20' => ' ', // space.
            '=E2=80=99' => "'", // single quote.
            '=0A' => "\r\n", // line break.
            '=A0' => ' ', // non-breaking space.
            '=C2=A0' => ' ', // non-breaking space.
            "=\r\n" => '', // joined line.
            '=E2=80=A6' => '…', // ellipsis.
            '=E2=80=A2' => '•', // bullet.
        ];

        // Loop through the encoded characters and replace any that
        // are found.
        foreach ( $characters as $key => $value ) {
            $string = str_replace( $key, $value, $string );
        }

        return $string;
    }

    static private $mimeExtensions = [
        "application/ics" => "ics",
        "application/illustrator" => "ai",
        "application/java-archive" => "jar",
        "application/java-vm" => "class",
        "application/javascript" => "js",
        "application/json" => "json",
        "application/msword" => "doc",
        "application/octet-stream" => "bin",
        "application/pdf" => "pdf",
        "application/pkcs7-signature" => "p7s",
        "application/pgp-encrypted" => "pgp",
        "application/pgp-signature" => "asc",
        "application/photoshop" => "psd",
        "application/postscript" => "ps",
        "application/rtf" => "rtf",
        "application/vnd.ms-excel" => "xls",
        "application/vnd.ms-powerpoint" => "ppt",
        "application/vnd.ms-xpsdocument" => "xps",
        "application/vnd.openxmlformats-officedocument.presentationml.presentation" => "pptx",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" => "xlsx",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document" => "docx",
        "application/vnd.oasis.opendocument.text" => "odt",
        "application/x-font-ttf" => "ttf",
        "application/x-javascript" => "js",
        "application/x-msword" => "doc",
        "application/x-php" => "php",
        "application/x-zip-compressed" => "zip",
        "application/xml" => "xml",
        "application/zip" => "zip",
        "audio/mp4" => "mp4a",
        "audio/mpeg" => "mpga",
        "audio/ogg" => "ogg",
        "audio/x-wav" => "wav",
        "image/bmp" => "bmp",
        "image/gif" => "gif",
        "image/jpg" => "jpg",
        "image/jpeg" => "jpeg",
        "image/png" => "png",
        "image/tiff" => "tiff",
        "image/vnd.adobe.photoshop" => "psd",
        "image/x-icon" => "ico",
        "message/rfc822" => "mime",
        "text/calendar" => "ics",
        "text/css" => "css",
        "text/csv" => "csv",
        "text/html" => "html",
        "text/plain" => "txt",
        "text/tab-separated-values" => "tsv",
        "text/vcard" => "vcard",
        "text/x-sql" => "sql",
        "text/x-vcard" => "vcard",
        "video/h264" => "h264",
        "video/jpeg" => "jpgv",
        "video/mp4" => "mp4",
        "video/mpeg" => "mpeg",
        "video/ogg" => "ogv",
        "video/quicktime" => "mov",
        "video/webm" => "webm",
        "video/x-flv" => "flv",
        "video/x-m4v" => "m4v",
        "video/x-matroska" => "mkv",
        "video/x-ms-wmv" => "wmv",
        "video/x-msvideo" => "avi"
    ];
};