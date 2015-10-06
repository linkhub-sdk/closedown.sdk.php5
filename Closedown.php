<?php
/**
* =====================================================================================
* Class for base module for Closedown API SDK. It include base functionality for
* RESTful web service request and parse json result. It uses Linkhub module
* to accomplish authentication APIs.
*
* This module uses curl and openssl for HTTPS Request. So related modules must
* be installed and enabled.
*
* http://www.linkhub.co.kr
* Author : Jeong yo han (yhjeong@linkhub.co.kr)
* Written : 2015-06-23
*
* Thanks for your interest.
* We welcome any suggestions, feedbacks, blames or anythings.
* ======================================================================================
*/
require_once 'Linkhub/linkhub.auth.php';

class Closedown
{	
	const ServiceID = 'CLOSEDOWN';
	const ServiceURL = 'https://closedown.linkhub.co.kr';
    const Version = '1.0';
    
    private $Linkhub;
	private $token;
	private $__requestMode = LINKHUB_COMM_MODE;
		    
    public function __construct($LinkID,$SecretKey) {
    	$this->Linkhub = Linkhub::getInstance($LinkID,$SecretKey);
    	$this->scopes[] = '170';
    }

    private function getsession_Token($ForwardIP) {
		$Refresh = true;

		if(!is_null($this->token)) {
            $Expiration = new DateTime($this->token->expiration,new DateTimeZone("UTC"));
            $now = $this->Linkhub->getTime();
            $Refresh = $Expiration < $now;
    	}

    	if($Refresh) {
    		try
    		{
    			$this->token = $this->Linkhub->getToken(Closedown::ServiceID,null,$this->scopes,$ForwardIP);
    		}catch(LinkhubException $le) {
    			throw new ClosedownException($le->getMessage(),$le->getCode());
    		}
        }
    	return $this->token->session_token;
    }
         
    // 회원 잔여포인트 확인
    public function GetBalance() {
    	try {
    		return $this->Linkhub->getPartnerBalance($this->getsession_Token(null),Closedown::ServiceID);
    	}catch(LinkhubException $le) {
    		throw new ClosedownException($le->message,$le->code);
    	}
    }

	// 조회단가 확인
	public function GetUnitCost(){
		try{
			return $this->executeCURL('/UnitCost')->unitCost;
		}catch(LinkhubException $le){
			throw new ClosedownException($le->message,$le->code);
		}
	}

	//휴폐업조회 - 단건
	public function checkCorpNum($corpNum){
		
		if(is_null($corpNum) || $corpNum === ""){
			throw new ClosedownException(-99999999, '사업자번호가 입력되지 않았습니다.');
		}

		$url = '/Check?CN='.$corpNum;

		$result = $this->executeCURL($url);

		$CorpStateInfo = new CorpState();
		$CorpStateInfo->fromJsonInfo($result);
		
		return $CorpStateInfo;
	}

	//휴폐업조회 - 대량
	public function checkCorpNums($corpNumList = array()){
		
		if(is_null($corpNumList) || empty($corpNumList)){
			throw new ClosedownException(-99999999, '사업자번호 배열이 입력되지 않았습니다.');
		}
		
		$postdata = json_encode($corpNumList);

		$url = '/Check';

		$result = $this->executeCURL($url, $postdata, true);
		
		$CorpStateList = array();
		
		for($i=0; $i<Count($result); $i++){
			$CorpState = new CorpState();
			$CorpState->fromJsonInfo($result[$i]);
			$CorpStateList[$i] = $CorpState;
		}

		return $CorpStateList;
	}
         
    protected function executeCURL($uri, $postdata = null, $isPost = false) {
    	if($this->__requestMode != "STREAM") {
			$http = curl_init((Closedown::ServiceURL).$uri);
			$header = array();

			$header[] = 'Authorization: Bearer '.$this->getsession_Token(null);
			$header[] = 'x-api-version: '.Closedown::Version;
			$header[] = 'Content-Type: Application/json';

			if($isPost){
				curl_setopt($http, CURLOPT_POST,1);
				curl_setopt($http, CURLOPT_POSTFIELDS, $postdata);   
			}
		
			curl_setopt($http, CURLOPT_HTTPHEADER,$header);
			curl_setopt($http, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($http, CURLOPT_ENCODING, 'gzip,deflate');
			
			$responseJson = curl_exec($http);

			$http_status = curl_getinfo($http, CURLINFO_HTTP_CODE);
		
			curl_close($http);

			if($http_status != 200) {
				throw new ClosedownException($responseJson);
			}
			return json_decode($responseJson);
		} else {
			$header = array();
			$header[] = 'Accept-Encoding: gzip,deflate';
			$header[] = 'Connection: close';
			$header[] = 'Authorization: Bearer '.$this->getsession_Token(null);
			$header[] = 'x-api-version: '.Closedown::Version;

			$params = array('http' => array(
					 'ignore_errors' => TRUE,
					 'protocol_version' => '1.1',
					 'method' => 'GET'
	                ));
	        	    
			if($isPost) {
				$params['http']['method'] = 'POST';
				$params['http']['content'] = $postdata;
	        } 
	  	
	  		if($header !== null) {
				$head = "";
				foreach($header as $h) {
		  			$head = $head . $h . "\r\n";
		    	}
		    	$params['http']['header'] = substr($head,0,-2);
		  	}

	  		$ctx = stream_context_create($params);
	  		$response = file_get_contents((Closedown::ServiceURL).$uri, false, $ctx);

			$is_gzip = 0 === mb_strpos($response , "\x1f" . "\x8b" . "\x08");

			if($is_gzip){
				$response = $this->Linkhub->gzdecode($response);		
			}

	  		if ($http_response_header[0] != "HTTP/1.1 200 OK") {
	    		throw new ClosedownException($response);
	  		}
	  		
			return json_decode($response);
			
		}
	}
}

class CorpState
{
	public $corpNum;
	public $state;
	public $type;
	public $stateDate;
	public $checkDate;
		
	public function fromJsonInfo($jsonInfo){
		isset($jsonInfo->corpNum) ? ($this->corpNum = $jsonInfo->corpNum): null;
		isset($jsonInfo->state) ? ($this->state = $jsonInfo->state) : null;
		isset($jsonInfo->type) ? ($this->type = $jsonInfo->type) : null;
		isset($jsonInfo->stateDate) ? ($this->stateDate = $jsonInfo->stateDate) : null;
		isset($jsonInfo->checkDate) ? ($this->checkDate = $jsonInfo->checkDate) : null;	
	}
}


class ClosedownException extends Exception
{
	public function __construct($response,$code = -99999999, Exception $previous = null) {
       $Err = json_decode($response);
       if(is_null($Err)) {
       		parent::__construct($response, $code );
       }
       else {
       		parent::__construct($Err->message, $Err->code);
       }
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
?>