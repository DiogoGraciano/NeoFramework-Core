<?php

namespace NeoFramework\Core\Jobs\Entity;

use DateTime;
use DateTimeInterface;

class JobEntity
{
    public function __construct(private string $class,private array $args,private DateTime|DateTimeInterface|null $schedule)
    {
    }

    public function toArray():array
    {
        $schedule = null;
        if($this->$schedule instanceof DateTime || $this->$schedule instanceof DateTimeInterface){
            $schedule = $this->$schedule->format("Y-m-d H:i:s");
        }

        return ["class" => $this->class,"args" => $this->args,"schedule" => $schedule];
    }

    public function toJson(){
        return json_encode($this->toArray());
    }
}
