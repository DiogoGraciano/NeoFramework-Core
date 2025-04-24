<?php
namespace NeoFramework\Core\Jobs\Entity;

use DateTime;
use DateTimeInterface;
use JsonSerializable;

class JobEntity implements JsonSerializable
{
    private string $id;
    private int $attempts = 0;
    private ?string $result = null;
    private ?string $error = null;
    private string $status = 'pending'; // pending, processing, completed, failed

    public function __construct(
        private string $class,
        private array $args = [],
        private DateTime|DateTimeInterface|null $schedule = null
    ) {
        $this->id = uniqid('job_', true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getSchedule(): DateTime|DateTimeInterface|null
    {
        return $this->schedule;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): self
    {
        $this->attempts++;
        return $this;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function setResult(?string $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function isDue(): bool
    {
        if (!$this->schedule) {
            return true;
        }
        
        return $this->schedule <= new DateTime();
    }

    public function toArray(): array
    {
        $schedule = null;
        if ($this->schedule instanceof DateTime || $this->schedule instanceof DateTimeInterface) {
            $schedule = $this->schedule->format("Y-m-d H:i:s");
        }

        return [
            "id" => $this->id,
            "class" => $this->class,
            "args" => $this->args,
            "schedule" => $schedule,
            "attempts" => $this->attempts,
            "status" => $this->status,
            "result" => $this->result,
            "error" => $this->error
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public static function fromArray(array $data): self
    {
        $schedule = null;
        if (!empty($data['schedule'])) {
            $schedule = DateTime::createFromFormat("Y-m-d H:i:s", $data['schedule']);
        }

        $job = new self($data['class'], $data['args'] ?? [], $schedule);
        
        if (isset($data['id'])) {
            $job->setId($data['id']);
        }
        
        if (isset($data['attempts'])) {
            $job->setAttempts($data['attempts']);
        }
        
        if (isset($data['status'])) {
            $job->setStatus($data['status']);
        }
        
        if (isset($data['result'])) {
            $job->setResult($data['result']);
        }
        
        if (isset($data['error'])) {
            $job->setError($data['error']);
        }
        
        return $job;
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(json_decode($json, true));
    }
}