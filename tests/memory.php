<?php

/**
 * Test script. This checks for memory leaks.
 */
include __DIR__ .'/../vendor/autoload.php';
gc_enable();

// Check if secret file exists. If not create a basic one
// and tell the user to save their password and email
// address in the file.
if ( ! file_exists( __DIR__ . '/secret.ini' ) ) {
    file_put_contents(
        __DIR__ . '/secret.ini',
        sprintf(
            "%s =\n%s =\n%s =\n%s =",
            "email",
            "password",
            "folder",
            "startfrom"
        ));
    echo "Please edit the contents of secret.ini\n";
    exit;
}

// Create attachments folder if it doesn't exist
if ( ! file_exists( __DIR__ .'/attachments' ) ) {
    mkdir( __DIR__ .'/attachments', 0755, TRUE );
}

$pid = getmypid();
$message = <<<STR
My PID is: $pid
Run this in another terminal:
$> watch -n 1 ps -o vsz $pid

Press [Enter] to continue...
STR;

if ( isset( $argv, $argv[ 1 ] ) && $argv[ 1 ] == 'ps' ):
    echo $message;
    fgetc( STDIN );
endif;

$index = 1;
$config = parse_ini_file( __DIR__ .'/secret.ini' );
$email = ( isset( $config[ 'email' ] ) ) ? $config[ 'email' ] : "";
$folder = ( isset( $config[ 'folder' ] ) ) ? $config[ 'folder' ] : "";
$password = ( isset( $config[ 'password' ] ) ) ? $config[ 'password' ] : "";
$startFrom = ( isset( $config[ 'startfrom' ] ) ) ? $config[ 'startfrom' ] : 0;
$mailbox = new \Pb\Imap\Mailbox(
    "imap.gmail.com",
    $email,
    $password,
    $folder,
    __DIR__ .'/attachments', [
        \Pb\Imap\Mailbox::OPT_DEBUG_MODE => TRUE
    ]);
$mailbox->debug( "Starting search" );
$messageIds = $mailbox->search( 'ALL' );
$count = count( $messageIds );
$mailbox->debug( "Fetched $count message IDs" );

foreach ( $messageIds as $messageId ) {
    if ( $messageId < $startFrom ) {
        $index++;
        continue;
    }

    $mailbox->debug( "Fetching message $index of $count" );
    // Pull the contents of the message
    $message = $mailbox->getMessage( $messageId );
    $mailbox->debug( "After message info was downloaded" );
    $message = NULL;
    unset( $message );
    $mailbox->debug( "After message was unset()" );
    // Cleanup
    $mailbox->debug( str_repeat( "-", 20 ) );
    $index++;
    gc_collect_cycles();
    usleep( 100000 );
}
