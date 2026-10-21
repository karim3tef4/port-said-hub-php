<?php
declare(strict_types=1);
namespace App;

final class ApiException extends \RuntimeException
{
    public string $errCode;
    public int $status;
    public function __construct(string $errCode, string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->errCode = $errCode;
        $this->status = $status;
    }
}
