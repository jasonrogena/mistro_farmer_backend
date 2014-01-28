<?php
include '../common/log.php';
include '../common/database.php';

class SMSProxy{
    private $TAG = "sms_router.php";
    private $ROOT = "../../";
    private $settingsDir;
    private $settings;
    private $codes;
    private $logHandler;
    private $sender;
    private $smsMessage;
    private $database;
    
    public function __construct(){
        $this->settingsDir = $this->ROOT."config/settings.ini";
	$this->logHandler = new LogHandler;
	$this->logHandler->log(3, $this->TAG,"Starting SMSProxy");
        $this->getSettings();
	$this->getCodes();
        $this->database = new DatabaseHandler;
        
        //split message
        $this->sender = $_GET['phone'];
        $this->smsMessage = $_GET['text'];
        $this->logHandler->log(4, $this->TAG,"SMS text is ".$this->smsMessage);
        
        $smsContent = explode($this->settings['sms_delimiter'], $this->smsMessage);
        $this->logHandler->log(3, $this->TAG,"Split sms to ".  sizeof($smsContent) ." fragments using sms delimiter");
        if(sizeof($smsContent) === 2){
            $this->logHandler->log(3, $this->TAG,"Message appears to be first fragment of json ");
            
            //means that this is the first message in a multipart message
            $appendedURI = $smsContent[0];
            $messageFragment =$smsContent[1];
            $this->logHandler->log(4, $this->TAG,"Message is ".$messageFragment);
            
            //check if $messageFragment is a valid json object
            if($this->isJson($messageFragment)){
                $this->logHandler->log(3, $this->TAG,"Message appears to be a valid json string. Sending it to ".$appendedURI);
                $this->logHandler->log(4, $this->TAG,"Message is ".$messageFragment);
                
                //send message fragment to respective uri as is
                $response = $this->sendJsonString($appendedURI, $messageFragment);
                $this->sendSMS($response);
            }
            else{
                $this->logHandler->log(3, $this->TAG,"Message is not a valid json string. Caching it to db");
                //insert messageFragments into table. make sure you replace row that contains same mobile number
                $query = "SELECT * FROM `kannel_cache` WHERE sender = '{$this->sender}'";
                $result = $this->database->runMySQLQuery($query, true);
                if(sizeof($result)===1){
                    $escapedMessage = mysql_real_escape_string($messageFragment);
                    $query = "UPDATE `kannel_cache` SET message = '$escapedMessage', uri = '$appendedURI' WHERE sender = '$this->sender'";
                    $result = $this->database->runMySQLQuery($query, false);
                }
                else{
                    $escapedMessage = mysql_real_escape_string($messageFragment);
                    $query = "INSERT INTO `kannel_cache`(sender, message, uri) VALUES('{$this->sender}', '$escapedMessage', '$appendedURI')";
                    $result = $this->database->runMySQLQuery($query, false);
                }
            }
        }
        else if(sizeof($smsContent) === 1){
            $this->logHandler->log(3, $this->TAG,"Only one fragment from sms. checking if there are more fragments in db");
            //means that there was definately another fragment sent before this
            //get fragment saved in db and add this fragment to the end
            $query = "SELECT message, uri FROM `kannel_cache` WHERE sender = '{$this->sender}'";
            $result = $this->database->runMySQLQuery($query, true);
            if(sizeof($result)===1){
                $this->logHandler->log(3, $this->TAG,"More fragments found in db. Appending new message to cached message");
                $appendedURI = $result[0]['uri'];
                $cachedMessage = $result[0]['message'];
                $cachedMessage = $cachedMessage.$smsContent;
                //check if newFragment is json
                if($this->isJson($cachedMessage)){
                    $this->logHandler->log(3, $this->TAG,"Message is a valid json. sending it to ".$appendedURI);
                    $this->logHandler->log(4, $this->TAG,"valid json string is ".$cachedMessage);
                    
                    //send message to respective uri 
                    $response = $this->sendJsonString($appendedURI, $cachedMessage);
                    $this->sendSMS($response);
                }
                else{
                    $this->logHandler->log(3, $this->TAG,"Is not a valid json string. Caching it to database ".$appendedURI);
                    $this->logHandler->log(4, $this->TAG,"Message so far is  ".$cachedMessage);
                    $escapedMessage = mysql_real_escape_string($escapedMessage);
                    $query = "UPDATE `kannel_cache` SET message = '$escapedMessage' WHERE sender = '$this->sender'";
                    $result = $this->database->runMySQLQuery($query, false);
                }
            }
        }
    }
    
    private function getSettings(){
        $this->logHandler->log(3, $this->TAG,"getting settings from: ".$this->settingsDir);
        if(file_exists($this->settingsDir)) {
            $settings = parse_ini_file($this->settingsDir);
            $mysqlCreds = parse_ini_file($settings['mysql_creds']);
            $settings['mysql_creds'] = $mysqlCreds;
            $this->settings = $settings;
            $this->logHandler->log(4, $this->TAG,"settings obtained: ".print_r($this->settings, true));
        }
        else {
            $this->logHandler->log(1, $this->TAG,"unable to get settings from ".$this->settingsDir.", exiting");
            die();
        }
    }
    
    private function getCodes() {
        $responseCodesLocation = $this->ROOT."config/".$this->settings['response_codes'];
	$this->logHandler->log(3, $this->TAG,"getting response codes from: ".$responseCodesLocation);
	if(file_exists($responseCodesLocation)) {
            $this->codes = parse_ini_file($responseCodesLocation);
            $this->logHandler->log(4, $this->TAG,"response codes are: ".print_r($this->codes, true));
	}
	else {
            $this->logHandler->log(1, $this->TAG,"unable to get response codes from ".$responseCodesLocation.", exiting");
            die();
        }
    }
    
     /**
    * This file determines if provided string is a valid json string
    * 
    * @param    mixed       $json       The object to be determined if is json object
    * @return   boolean     returns true if provided object is json object
    */
    private function isJson($jsonString) {
        json_decode($jsonString);
        return (json_last_error() == JSON_ERROR_NONE);
   }
   
   private function sendJsonString($uri, $jsonString){
       $url = $this->settings['base_url'].$uri;
       $this->logHandler->log(3, $this->TAG,"using curl to send data to ".$url);
       $postFields = array("json"=>  urlencode($jsonString));
       
       //url-ify the post data
       foreach ($postFields as $key=>$value){
           $postDataString .= $key.'='.$value.'&';
       }
       rtrim($postDataString, '&');
       
       //open curl connection
       $ch = curl_init();
       
       curl_setopt($ch, CURLOPT_URL, $url);
       curl_setopt($ch, CURLOPT_POST, count($postFields));
       curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataString);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);//return web page
       curl_setopt($ch, CURLOPT_HEADER, FALSE);//dont return headers
       
       //execute post
       $result = curl_exec($ch);
       curl_close($ch);
       
       $this->logHandler->log(3, $this->TAG,"response gotten from ".$url);
       $this->logHandler->log(3, $this->TAG,"server's response is  ".$result);
       return $result;
   }
   
   private function sendSMS($message){
       $this->logHandler->log(3, $this->TAG,"sending sms to ".$this->sender." using kannel");
       $sendSmsURL = $this->settings['sendsms_url'];
       $sendSmsUser = $this->settings['sendsms_user'];
       $sendSmsPass = $this->settings['sendsms_pass'];
       $sendSmsURL = $sendSmsURL."?username=".$sendSmsUser."&password=".$sendSmsPass;
       $sendSmsURL = $sendSmsURL."&to=".$this->sender;
       $sendSmsURL = $sendSmsURL."&text=".urlencode($message);
       
       $this->logHandler->log(4, $this->TAG,"final GET request is ".$sendSmsURL);
       $ch = curl_init();
       
       curl_setopt($ch, CURLOPT_URL, $sendSmsURL);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
       curl_setopt($ch, CURL, TRUE);
       curl_setopt($ch, CURLOPT_HEADER, FALSE);
       
       $result = curl_exec($ch);
       curl_close($ch);
       
       $this->logHandler->log(3, $this->TAG,"Response gotten from kannel");
       $this->logHandler->log(4, $this->TAG,"response from kannel is ".$result);
       
       return $result;
   }
}
$obj = new SMSProxy;
?>