<?php

/************************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

class SMSNotifier_ESMSProvider_Provider implements SMSNotifier_ISMSProvider_Model
{

    private $api_key;
    private $secret_key;
    private $parameters = array();

    const SEND_SMS_RANDOM = 3;
    const SEND_SMS_FIX = 6;
    const SEND_SMS_1900X = 4;

    const SMS_RESPONSE_SUCCESS = "100";
    const SMS_RESPONSE_UNKNOW = "99";
    const SMS_RESPONSE_LOGIN_FAIL = "101";
    const SMS_RESPONSE_ACCOUNT_LOCK = "102";
    const SMS_RESPONSE_NOT_ENOUGH_MONEY = "103";
    const SMS_RESPONSE_BRANDNAME_FAIL = "104";


    const SMS_STATUS_WAITING_VALID = 1;
    const SMS_STATUS_WAITING_SENT = 2;
    const SMS_STATUS_SENDING = 3;
    const SMS_STATUS_REJECT = 4;
    const SMS_STATUS_SENT = 5;
    const SMS_STATUS_DELETED = 6;

    const SERVICE_URI = 'http://rest.esms.vn/MainService.svc/xml';
    private static $REQUIRED_PARAMETERS = array();

    /**
     * Function to get provider name
     * @return <String> provider name
     */
    public function getName()
    {
        return 'ESMSProvider';
    }

    /**
     * Function to get required parameters other than (userName, password)
     * @return <array> required parameters list
     */
    public function getRequiredParams()
    {
        return self::$REQUIRED_PARAMETERS;
    }

    /**
     * Function to get service URL to use for a given type
     * @param <String> $type like SEND, PING, QUERY
     */
    public function getServiceURL($type = false)
    {
        if ($type) {
            switch (strtoupper($type)) {
                case self::SERVICE_AUTH:
                    return self::SERVICE_URI . '/http/auth';
                case self::SERVICE_SEND:
                    return self::SERVICE_URI . '/SendMultipleMessage_V4/';
                case self::SERVICE_QUERY:
                    return self::SERVICE_URI . '/GetSmsStatus/';
            }
        }
        return false;
    }

    /**
     * Function to set authentication parameters
     * @param <String> $userName
     * @param <String> $password
     */
    public function setAuthParameters($userName, $password)
    {
        $this->api_key = $userName;
        $this->secret_key = $password;
    }

    /**
     * Function to set non-auth parameter.
     * @param <String> $key
     * @param <String> $value
     */
    public function setParameter($key, $value)
    {
        $this->parameters[$key] = $value;
    }

    /**
     * Function to get parameter value
     * @param <String> $key
     * @param <String> $defaultValue
     * @return <String> value/$default value
     */
    public function getParameter($key, $defaultValue = false)
    {
        if (isset($this->parameters[$key])) {
            return $this->parameters[$key];
        }
        return $defaultValue;
    }

    /**
     * Function prepare data post to send sms
     * @param $content - Content message
     * @param array $phones - list phone want to send
     * @return String - params
     */
    protected function prepareParametersToSend($content, $phones, $typeSend = self::SEND_SMS_RANDOM)
    {
        $rawPost = "<RQST>"
            . "<APIKEY>" . $this->api_key . "</APIKEY>"
            . "<SECRETKEY>" . $this->secret_key . "</SECRETKEY>"
            . "<ISFLASH>0</ISFLASH>"
            . "<UNICODE>0</UNICODE>" 
			. "<SMSTYPE>3</SMSTYPE>" //3 là gui tin ngau nhien, 4 la gui tin dau so 19001534, 6 là gui dau so co dinh 8755
            . "<CONTENT>" . $content . "</CONTENT>"
            . "<CONTACTS>";

        foreach($phones as $phone){
        $rawPost .= "<CUSTOMER>"
            . "<PHONE>" . $phone . "</PHONE>"
            . "</CUSTOMER>";
        }
        $rawPost.= "</CONTACTS>"
            . "</RQST>";
        return $rawPost;
    }

    /**
     * Function thuc hien query status message tu ESMS provider
     * @param $smsid
     * @return string
     */
    protected function prepareParametersToQuery($smsid)
    {
        $rawPost = "<RQST>"
            . "<APIKEY>" . $this->api_key . "</APIKEY>"
            . "<SECRETKEY>" . $this->secret_key . "</SECRETKEY>"
            . "<SMSID>" . $smsid . "</SMSID>"
            . "</RQST>";
        return $rawPost;
    }

    /**
     * Function to handle SMS Send operation
     * @param <String> $message
     * @param <Mixed> $toNumbers One or Array of numbers
     */
    public function send($message, $toNumbers)
    {
        global $log;
        if (!is_array($toNumbers)) {
            $toNumbers = array($toNumbers);
        }

        $dataPost = $this->prepareParametersToSend($message, $toNumbers);
        $log->debug("POST Provider: " . $dataPost);
        $serviceURL = $this->getServiceURL(self::SERVICE_SEND);
        $httpClient = new Vtiger_Net_Client($serviceURL);
        $httpClient->setHeaders(array('Content-Type: text/plain'));
        $response = $httpClient->doPost($dataPost);
        $log->debug("Response: " . $response);
        $sendResult = simplexml_load_string(trim($response));

        $results = array();
        $i = 0;

        foreach($toNumbers as $number){
            if($sendResult == null || $sendResult->CodeResult != self::SMS_RESPONSE_SUCCESS){
                $result['error'] = true;
                $result['statusmessage'] = isset($sendResult->ErrorMessage)?$sendResult->ErrorMessage:'';
            }else{
                $result['to'] = $number;
                $result['id'] = isset($sendResult->SMSID)?$sendResult->SMSID:'';
                $result['status'] = self::MSG_STATUS_PROCESSING;
            }
            $results[] = $result;
        }
        return $results;
    }

    /**
     * Function to get query for status using messgae id
     * @param <Number> $messageId
     */
    public function query($messageId)
    {
        global $log;
        $rawPost = $this->prepareParametersToQuery($messageId);
        $log->debug("POST query: " . $rawPost);
        $serviceURL = $this->getServiceURL(self::SERVICE_QUERY);
        $httpClient = new Vtiger_Net_Client($serviceURL);
        $response = $httpClient->doPost($rawPost);
        $log->debug("Response: " . $response);
        $queryResult = simplexml_load_string(trim($response));

        $result = array('error' => false, 'needlookup' => 1, 'statusmessage' => '');
        if($queryResult == null){
            $result['error'] = true;
            $result['needlookup'] = 0;
            $result['statusmessage'] = "Provider error";
        }else{
            $result['id'] = $queryResult->SmsID;

            switch(intval($queryResult->SendStatus)){
                case self::SMS_STATUS_WAITING_SENT:
                case self::SMS_STATUS_WAITING_VALID:
                case self::SMS_STATUS_SENDING:
                    $result['status'] = self::MSG_STATUS_PROCESSING;
                    $result['statusmessage'] = "CODE: ".$queryResult->SendStatus;
                    break;
                case self::SMS_STATUS_DELETED:
                case self::SMS_STATUS_SENT:
                    $result['status'] = self::MSG_STATUS_DISPATCHED;
                    $result['needlookup'] = 0;
                    break;
                case self::SMS_STATUS_REJECT:
                    $result['error'] = true;
                    $result['needlookup'] = 0;
                    $result['statusmessage'] = "Message Reject";
                    break;
                default:
                    $result['error'] = true;
                    $result['needlookup'] = 0;
                    $result['statusmessage'] = "CODE: ".$queryResult->SendStatus;
            }
        }

        return $result;
    }
}

?>
