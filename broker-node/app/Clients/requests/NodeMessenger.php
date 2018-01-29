<?php

require_once("IriData.php");

class NodeMessenger
{

    private $headers = array(
        'Content-Type: application/x-www-form-urlencoded',
    );
    public $nodeUrl;
    private $userAgent = 'Codular Sample cURL Request';
    private $apiVersionHeaderString = 'X-IOTA-API-Version: ';

    public function __construct()
    {

        array_push($this->headers, $this->apiVersionHeaderString . IriData::$apiVersion);
    }

    public function sendMessageToNode($commandObject, $nodeUrl)
    {
        $payload = http_build_query($commandObject);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_URL => $nodeUrl,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_CONNECTTIMEOUT => 0,
            CURLOPT_TIMEOUT => 1000
        ));

        $response = json_decode(curl_exec($curl));

        curl_close($curl);

        return $response;
    }
}


