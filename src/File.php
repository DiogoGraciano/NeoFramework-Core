<?php

namespace NeoFramework\Core;

use finfo;
use NeoFramework\Core\Enums\fileStorageDisk;

class File extends \SplFileObject
{
    public function getMimeType():string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($this->getRealPath());
    }

    public function save(?string $name = null,string $folder = "public/assets",fileStorageDisk $disk = fileStorageDisk::LOCAL):bool
    {
        $fileStorage = new FileStorage($disk,$folder);

        if(is_null($name)){
            $name = Functions::generateId();
        }

        return $fileStorage->saveFromString($name,$this->toString());
    }

    public function toString():string
    {
        return file_get_contents($this->getRealPath())?:"";
    }

    public function toArray():array
    {
        return json_decode($this->toString(),true)?:[];
    }
}
