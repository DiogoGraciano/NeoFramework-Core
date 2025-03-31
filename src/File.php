<?php

namespace NeoFramework\Core;

use finfo;

class File extends \SplFileObject
{
    public function getMimeType():string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->getRealPath());
    }
}
