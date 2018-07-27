# \Pb\Imap

\Pb\Imap is a library for downloading and parsing IMAP email messages. For
performance reasons, the PHP IMAP extension is specifically *not* used, in
favour of the Zend Mail library.

### Features

* Connect to mailbox via IMAP
* Get a listing of all folders
* Download email messages and save attachments to disk
* Search emails by custom criteria
* Get status information on each folder

### Installation by Composer

```
{
    "require": {
        "particlebits/imap": "^1.2"
    }
}
```

*or*

```
$> composer require particlebits/imap ^1.2
```

### Usage Example

```php
$mailbox = new \Pb\Imap\Mailbox(
    "imap.gmail.com",
    "something@gmail.com",
    "**********",
    "INBOX",
    __DIR__ .'/attachments',
    [
        \Pb\Imap\Mailbox::OPT_DEBUG_MODE => true
    ]);
$messageIds = $mailbox->searchMailBox('ALL');

foreach ( $messageIds as $messageId) {
    $message = $mailbox->getMessage($messageId);
    print_r($message);
    print_r($message->getAttachments());
}
```

### Available Options

You can use the following options in the final argument of the
Mailbox constructor:

 - `OPT_DEBUG_MODE`
   This will write memory info to the screen
 - `OPT_SKIP_ATTACHMENTS`
   Skips downloading message attachments. Useful for saving disk space.

### Notes

This project originally used the PHP IMAP extension for fetching and
parsing email messages. The extension is not very memory efficient
for long-running processes, which is my intended use. Instead, this
library uses the Zend-Mail package. Zend-Mail retrieves messages through
a socket connection and parses the headers. \Pb\Imap takes this raw
message object and converts into something more user-friendly.

For more examples see the scripts inside the `tests/` folder.