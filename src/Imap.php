<?php

namespace Pb\Imap;

use Exception\RuntimeException
  , Zend\Mail\Storage\Imap as ZendImap;

class Imap extends ZendImap
{
    /**
     * Examine given folder. Folder must be selectable!
     *
     * @param  \Zend\Mail\Storage\Folder|string $globalName
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
                'Cannot examine folder, maybe it does not exist' );
        }

        return $examine;
    }

    public function search( array $params )
    {
        return $this->protocol->search( $params );
    }
}