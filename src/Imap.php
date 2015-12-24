<?php

namespace Pb\Imap;

use Exception\RuntimeException
  , Zend\Mail\Storage\Imap as ZendImap;

class Imap extends ZendImap
{
    /**
     * Examine given folder. Folder must be selectable!
     *
     * @param \Zend\Mail\Storage\Folder|string $globalName
     *   global name of folder or instance for subfolder
     * @throws Exception\RuntimeException
     * @return NULL|array
     */
    public function examineFolder( $globalName )
    {
        $this->currentFolder = $globalName;
        $examine = $this->protocol->examine( $this->currentFolder );

        if ( ! $examine ) {
            $this->currentFolder = '';
            throw new RuntimeException(
                "Cannot examine folder, maybe it doesn't exist" );
        }

        return $examine;
    }

    /**
     * Select given folder.
     *
     * @param \Zend\Mail\Storage\Folder|string $globalName
     *   global name of folder or instance for subfolder
     * @throws Exception\RuntimeException
     * @return NULL|array
     */
    public function selectFolder( $globalName )
    {
        $this->currentFolder = $globalName;
        $select = $this->protocol->select( $this->currentFolder );

        if ( ! $select ) {
            $this->currentFolder = '';
            throw new RuntimeException(
                "Cannot select folder, maybe it doesn't exist" );
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
     * @return array
     */
    public function search( array $params, $uid = FALSE )
    {
        return $this->protocol->search( $params, $uid );
    }
}