<?php
// JsonException.php
namespace LayBot\Request\Exception;
class JsonException extends RequestException
{
    public function __construct(string $msg,int $code,string $raw){
        parent::__construct($msg,$code);
        $this->raw=$raw;
    }
    public string $raw;
}
