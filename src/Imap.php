<?php

namespace Pb\Imap;

use Laminas\Mail\Storage\Imap as LaminasImap;
use RuntimeException;

class Imap extends LaminasImap
{
    /**
     * Examine given folder. Folder must be selectable!
     *
     * @param \Laminas\Mail\Storage\Folder|string $globalName
     *   global name of folder or instance for subfolder
     *
     * @throws RuntimeException
     *
     * @return null|array
     */
    public function examineFolder($globalName)
    {
        $this->currentFolder = $globalName;
        $examine = $this->protocol->examine($this->currentFolder);

        if (! $examine) {
            $this->currentFolder = '';

            throw new RuntimeException(
                "Cannot examine folder, maybe it doesn't exist"
            );
        }

        return $examine;
    }

    /**
     * Select given folder.
     *
     * @param \Laminas\Mail\Storage\Folder|string $globalName
     *   global name of folder or instance for subfolder
     *
     * @throws RuntimeException
     * @throws Protocol\Exception\RuntimeException
     *
     * @return null|array
     */
    public function selectFolder($globalName)
    {
        $this->currentFolder = $globalName;
        $select = $this->protocol->select($this->currentFolder);

        if (! $select) {
            $this->currentFolder = '';

            throw new RuntimeException(
                "Cannot select folder, maybe it doesn't exist"
            );
        }

        return $select;
    }

    /**
     * Forwards the search request to the protocol. If the UID
     * option is specified, perform a UID search instead.
     * See https://tools.ietf.org/search/rfc4731
     * Returns an array of integers, either the message numbers
     * or the UIDs if that option is enabled.
     *
     * @param array $params
     * @param bool $uid
     *
     * @return array
     */
    public function search(array $params, bool $uid = false)
    {
        return $this->protocol->search($params, $uid);
    }

    /**
     * Adds flags for a message. This wrapper properly handles the
     * extra arguments that the protocol method takes.
     *
     * NOTE: this method can't set the recent flag.
     *
     * @param int $id number of message
     * @param array $flags new flags for message
     *
     * @throws RuntimeException
     */
    public function addFlags(int $id, array $flags)
    {
        if (! $this->protocol->store($flags, $id, null, '+', true)) {
            throw new RuntimeException(
                'Cannot add flags; have you tried to set the '.
                'recent flag or special chars?'
            );
        }
    }

    /**
     * Removes flags for a message. This wrapper properly handles the
     * extra arguments that the protocol method takes.
     *
     * NOTE: this method can't remove the recent flag.
     *
     * @param int $id number of message
     * @param array $flags flags to remove from message
     *
     * @throws RuntimeException
     */
    public function removeFlags(int $id, array $flags)
    {
        if (! $this->protocol->store($flags, $id, null, '-', true)) {
            throw new RuntimeException(
                'Cannot remove flags; have you tried to set the '.
                'recent flag or special chars?'
            );
        }
    }

    /**
     * Wrapper around the expunge command.
     *
     * @throws RuntimeException
     */
    public function expunge()
    {
        if (! $this->protocol->expunge()) {
            throw new RuntimeException('Failed to expunge folder');
        }
    }
}
