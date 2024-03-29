<?php

/**
 * Test script. This checks for memory leaks.
 */
include __DIR__.'/../vendor/autoload.php';

gc_enable();

function usage()
{
    echo 'Please edit the contents of secret.ini with '
        ."your email credentials\n";
    exit;
}

// Check if secret file exists. If not create a basic one
// and tell the user to save their password and email
// address in the file.
if (! file_exists(__DIR__.'/secret.ini')) {
    file_put_contents(
        __DIR__.'/secret.ini',
        sprintf(
            "%s =\n%s =\n%s =\n%s =",
            'email',
            'password',
            'folder',
            'startfrom'
        ));
    usage();
}

// Create attachments folder if it doesn't exist
if (! file_exists(__DIR__.'/attachments')) {
    mkdir(__DIR__.'/attachments', 0755, true);
}

// Display the pid and confirm to proceed
$pid = getmypid();
echo <<<STR
My PID is: $pid
Run this in another terminal:
$> watch -n 1 ps -o vsz $pid\n
This script only works with a GMail address!\n\n
STR;

$index = 1;
$config = parse_ini_file(__DIR__.'/secret.ini');
$email = isset($config['email']) ? $config['email'] : '';
$folder = isset($config['folder']) ? $config['folder'] : '';
$password = isset($config['password']) ? $config['password'] : '';
$startFrom = isset($config['startfrom']) ? $config['startfrom'] : 0;

try {
    $mailbox = new \Pb\Imap\Mailbox(
        'imap.gmail.com',
        $email,
        $password,
        $folder,
        __DIR__.'/attachments', [
            \Pb\Imap\Mailbox::OPT_DEBUG_MODE => true
        ]);

    $mailbox->debug('Starting search');
    $messageIds = $mailbox->search('ALL');
    $count = count($messageIds);
    $mailbox->debug("Fetched $count message IDs");

    foreach ($messageIds as $messageId) {
        if ($messageId < $startFrom) {
            ++$index;
            continue;
        }

        $mailbox->debug("Fetching message $index of $count (ID $messageId)");
        // Pull the contents of the message
        $message = $mailbox->getMessage($messageId);
        $mailbox->debug('After message info was downloaded');
        $message = null;
        unset($message);
        $mailbox->debug('After message was unset()');
        // Cleanup
        $mailbox->debug(str_repeat('-', 20));
        ++$index;
        gc_collect_cycles();
        usleep(100000);
    }

    $mailbox->disconnect();
} catch (Exception $e) {
    echo 'Error: ', $e->getMessage(), "\n";
    usage();
}
