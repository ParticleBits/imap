<?php

/**
 * Test script. This tests flag setting.
 */
include __DIR__.'/../vendor/autoload.php';

gc_enable();

function usage()
{
    echo 'Please edit the contents of secret.ini with '
        ."your email credentials\n";
    exit;
}

function getFlags($flags)
{
    $set = [];

    foreach ($flags as $flag => $value) {
        if (1 === (int) $value) {
            $set[] = $flag;
        }
    }

    return $set;
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
This script only works with a GMail address!\n
This script modifies the flags on message 1 of the INBOX!\n
STR;

$line = readline('Do you want to continue? [y/N] ');

if ('y' !== $line && 'Y' !== $line) {
    exit(0);
}

$flags = ['\Seen', '\Flagged'];
$config = parse_ini_file(__DIR__.'/secret.ini');
$email = isset($config['email']) ? $config['email'] : '';
$folder = isset($config['folder']) ? $config['folder'] : '';
$password = isset($config['password']) ? $config['password'] : '';

try {
    $mailbox = new \Pb\Imap\Mailbox(
        'imap.gmail.com',
        $email,
        $password,
        $folder,
        __DIR__.'/attachments', [
            \Pb\Imap\Mailbox::OPT_DEBUG_MODE => true
        ]);

    $message = $mailbox->getMessage(1);
    $mailbox->debug('Current flags: '.implode(', ', getFlags($message->flags)));

    foreach ($flags as $flag) {
        $mailbox->debug('Setting message as '.$flag);
        $newFlags = $mailbox->addFlags([1], [$flag]);
        $mailbox->debug('Unsetting message as '.$flag);
        $newFlags = $mailbox->removeFlags([1], [$flag]);
        gc_collect_cycles();
        usleep(100000);
    }

    $mailbox->disconnect();
} catch (Exception $e) {
    echo 'Error: ', $e->getMessage(), "\n";
    usage();
}
