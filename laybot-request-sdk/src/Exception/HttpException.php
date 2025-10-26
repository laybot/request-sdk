<?php
// HttpException.php
namespace LayBot\Request\Exception;
class HttpException extends RequestException
{
    public function __construct(string $msg,int $code,string $body=''){
        parent::__construct($msg,$code);
        $this->responseBody=$body;
    }
    public string $responseBody;
}
