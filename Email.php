<?php

class ImapServer{
	
	private $error;
	private $imap_stream;
	
	public function __construct($mailbox, $email, $pwd){
		
		$this->imap_stream=imap_open($mailbox, $email, $pwd);
		$this->error=$this->imap_stream==false;
		
	}
	
	public function isConnected(){
		return !$this->error;
	}
	
	public function getError(){
		return imap_last_error();
	}
	
	public function getMessageCount(){
		return imap_num_msg($this->imap_stream);
	}
	public function getEmail($i){
		return new Email($i, $this);
	}
	
	public function markEmailAsToBeDeleted($i){
		imap_delete($this->imap_stream, $i);
	}
	
	public function deleteMarkedEmails(){
		imap_expunge($this->imap_stream);
	}
	
	public function getEmailHeaders($i){
		return imap_headerinfo($this->imap_stream, $i);
	}
	
	public function getContent($i, $section){
		return imap_fetchbody($this->imap_stream, $i, $section);
	}
	
	public function getEmailStructure($i){
		return imap_fetchstructure($this->imap_stream, $i);
	}
	
	public function getEmailUid($i){
		return imap_uid($this->imap_stream, $i);
	}
	
	public function __destruct(){
		imap_close($this->imap_stream);
	}
	
	
}


class Email{
	
	private $structure;
	private $index;
	private $imapServer;
	private $headers;
	private $attachmentsMap=array();
	
	private $html = null;
	private $htmlImageCidToData;
	private $plaintext;
	private $mua;
	private $imagesCid = array();
	
	private $bodySize;
	
	
	public function __construct($index,ImapServer $imapServer){
		
		$this->imapServer=$imapServer;
		$this->index=$index;
		
		$this->structure=$imapServer->getEmailStructure($index);
		
		if(isset($this->structure->parameters)){
			
			foreach($this->structure->parameters as $param){
				
				if($param->attribute == "boundary"){	
									
					if(strpos($param->value, "Apple-Mail") === 0){
						
						$this->mua = "Apple-Mail";
						
					}
					
					break;
				}
			}
			
		}
		
		//print_r($this->structure);
		
		$this->headers=$imapServer->getEmailHeaders($index);
		
	}
	
	public function markToBeDeleted(){
		
		$this->imapServer->markEmailAsToBeDeleted($this->index);
		
	}
	
	public function getUid(){
		
		return $this->imapServer->getEmailUid($this->index);
		
	}
	
	public static function decodeContent($content, $encoding){
		
		switch($encoding){
			
			case 0:
				
			case 1:
				
			case 2:
			//nothing to do
			return $content;
			break;
			
			case 3:
			return base64_decode($content);
			break;
			
			case 4:
			return quoted_printable_decode($content);
			break;
			
			case 5:
			//bel problema, chissï¿½ com'ï¿½ fatto...*/
			return $content;
			break;
			
		}
		
	}
	
	private static function decodeHeaderString($string){
		
		$elements = imap_mime_header_decode($string);
		$res = "";
		foreach($elements as $e){
			if(isset($e->charset) && $e->charset!=null && $e->charset != "default"){
				$res .= iconv($e->charset, "UTF-8//TRANSLIT", $e->text);
			}else{
				$res .= $e->text;
			}
			
		}
		return $res;
				
	}
	
	public function getAttachmentContent($filename){
		//print_r($this->attachmentsMap[$filename]);exit;
		return $this->getSectionContent($this->attachmentsMap[$filename]);
		
	}
	
	
	public function getSectionContent($sectionDescriptor){
		
		$content = $this->imapServer->getContent($this->index, $sectionDescriptor["section"]);
		
		if(isset($sectionDescriptor["charset"]) && $sectionDescriptor["charset"]!=null &&$sectionDescriptor["charset"]!="default"){

			$content = iconv(strtoupper($sectionDescriptor["charset"]), "UTF-8//TRANSLIT", $content);

		}
		
		return Email::decodeContent($content, $sectionDescriptor["encoding"]);
		
	}
	
	public function getAttachmentsInfos(){
		
		$this->getBody("HTML");
		
		
		return $this->recurs_getAttachments($this->structure);
		
	}
	
	
	
	// Nuova recurs_getAttachments new da Verificare Marco ( da rinominare mantenuta anche versione con nome originale)
	private function recurs_getAttachments($msg, $section = ""){
		
		$result=array();
		
		$currentSection=$section;
		
		//se esistono ancora delle sottoparti della parte corrente del messaggio, prossimo passo di ricorsione;
		//altrimenti controlla la parte corrente
		
		if(isset($msg->parts)){
			$i=0;
			foreach($msg->parts as $part){
				
				if($msg->type==2 && $msg->subtype=='RFC822'){
					$currentSection=$section;
				}else{
					$i++;
					if($section == "")
						$currentSection=$i;
					else
						$currentSection=$section.".".$i;
				}
				
				$result=array_merge($result, $this->recurs_getAttachments($part, $currentSection));
	
			}
		}else{
			
			// controlla se ï¿½ un allegato o un file inline
			// con ifdisposition vuoto si elimina (Outlook)
			// filtro i file selezionando verificando se risulta
			// se il file in considerazione ï¿½ un inline di tipo cid 
			// in questo caso non lo considero allegato a meno che 
			// il MUA non sia di tipo Apple-Mail in questo caso salvo
			// l'immagine anche come allegato 
			
			if($msg->ifdisposition 
					&& ($msg->disposition=="attachment" || $msg->disposition=="inline")) {

						$filename=Email::decodeHeaderString($this->getFilenameFromSectionDescriptor($msg));
						
						$loadAttachment = true;
						
						
						foreach($this->imagesCid as $cid){
							
							if( ($msg->ifid == "1" && ($msg->id == "<$cid>" || $msg->id == $cid )) ||  ("section_".$section == $cid) ){

								$loadAttachment = false;
								
								break;
								
							}
								
						}
						
						if($filename == "daticert.xml" || $filename == "smime.p7s"){
							$loadAttachment = false;
						}
						
						
						if($loadAttachment == true){
							
							$result[]= array(
									'section' => $section,
									'type' => $msg->subtype,
									'encoding' => $msg->encoding,
									'filename' => $filename,
									'size' => $msg->bytes
							);													
							
							$this->attachmentsMap[$filename]=array(
									'section' => $section,
									'type' => $msg->subtype,
									'encoding' => $msg->encoding,
									'filename' => $filename								
							);
							
							// il charset viene preso solo se è un file di testo
							// type == 0 significa primary MIME type = text
							if($msg->type==0 && $msg->ifparameters == 1 ){
								foreach($msg->parameters as $p){
									if($p->attribute == "charset"){
										
										$this->attachmentsMap[$filename]["charset"]=$p->value;
										
									}
								}
							}
							
						}	
						
			}
			
		}
		
		return $result;
	
	}
	
	
	private function getFilenameFromSectionDescriptor($sectionDescriptor){
		
		if($sectionDescriptor->ifdparameters == 1){
			foreach($sectionDescriptor->dparameters as $param){
				if($param->attribute == "filename"){
					return $param->value;
				}
			}
		}
		if($sectionDescriptor->ifparameters == 1){
			foreach($sectionDescriptor->parameters as $param){
				if($param->attribute == "name"){
					return $param->value;
				}
			}
		}
		if($sectionDescriptor->ifdparameters == 1){
			foreach($sectionDescriptor->dparameters as $param){
				if($param->attribute == "filename*"){
					return $param->value;
				}
			}
		}
		if($sectionDescriptor->ifparameters == 1){
			foreach($sectionDescriptor->parameters as $param){
				if($param->attribute == "name*"){
					return $param->value;
				}
			}
		}
		return $sectionDescriptor->dparameters[0]->value;
	}
	
	
	
	
	
	public function getBody($subtype = "HTML"){
		
		if(strtoupper($subtype) == "HTML"){

			if($this->html == null){

				$this->html = $this->recurs_getBody(strtoupper($subtype), $this->structure);
				
			}
			
			//$this->getImagesFromHTML();
			
			return $this->html;
			
		}
				
		if(strtoupper($subtype) == "PLAIN"){
			
			if($this->plaintext == null){
				
				$this->plaintext = $this->recurs_getBody(strtoupper($subtype), $this->structure);
				
			}
			
			return $this->plaintext;				
		}

	}
	
	
	private function recurs_getBody($subtype, $msg, $section = ""){
		
		$currentSection=$section;

		if($msg->type == 2 && $msg->subtype == "RFC822" && isset($msg->parts)){
			
			$i=1;
			
			foreach($msg->parts as $part){
				
				//$i++;
				
				//$currentSection=$i;
				
				$res = $this->recurs_getBody($subtype, $part, $currentSection);
								
				if(($res != null)){
											
					return $res;
									
				}
				
			}
			
		}else if($msg->type == 1 && $msg->subtype == "ALTERNATIVE" && isset($msg->parts)){
			$i=0;
			
			$selectedSection == null;
			$selectedPart = null;
			
			foreach($msg->parts as $part){
					
				$i++;
				if($section == ""){
			
					$currentSection=$i;
			
				}else{
						
					$currentSection=$section.".".$i;
			
				}
					
				if($part->type==0){
					$selectedSection = $currentSection;
					$selectedPart = $part;
					if($part->subtype==$subtype){
						break;
					}
				}
					
			}
			
			
			
			if($selectedSection !== null){
				$res = $this->recurs_getBody($subtype, $selectedPart, $selectedSection);
					
				if(($res != null)){
					return $res;
				}
			}else{
				foreach($msg->parts as $part){
						
					$i++;
					if($section == ""){
							
						$currentSection=$i;
							
					}else{
							
						$currentSection=$section.".".$i;
							
					}
						
					$res = $this->recurs_getBody($subtype, $part, $currentSection);
			
					if(($res != null)){
						return $res;
					}
						
				}
			}
			
		}else if($msg->type == 1 && $msg->subtype != "MIXED" && isset($msg->parts)){

			$i=0;
				
			foreach($msg->parts as $part){
			
				$i++;
				if($section == ""){
						
					$currentSection=$i;
						
				}else{
			
					$currentSection=$section.".".$i;
						
				}
				$res = $this->recurs_getBody($subtype, $part, $currentSection);
			
				if(($res != null)){
						
					return $res;
						
				}
			
			}
		}else if($msg->type == 1 && $msg->subtype == "MIXED" ) {
			
			$i=0;
			$result = "";
			foreach($msg->parts as $part){
				
				$i++;
				if($section == ""){
					
					$currentSection=$i;
					
				}else{

					$currentSection=$section.".".$i;
					
				}

				if($part->type == 0 ){
					if(!($part->subtype == "HTML" && $subtype == "PLAIN") && !($part->ifdisposition == 1 && $part->disposition == "attachment") ){
						
						$sectionDescriptor=array();
						$sectionDescriptor["encoding"] = $part->encoding;
						$sectionDescriptor["section"] = $currentSection;
						if($part->ifparameters == 1 ){
							foreach($part->parameters as $p){
								if($p->attribute == "charset"){
									$sectionDescriptor["charset"]=$p->value;
								}
							}
						}						
						
						
						
						$s=$this->getSectionContent($sectionDescriptor);
						
						$s=$this->getImagesFromHTML($s);
						$result.=$s;
					}
				}elseif($part->type == 5 && $part->ifdisposition == 1 && $part->disposition == "inline" && $subtype == "HTML"){
					
					$img = $this->getSectionContent(array("section"=>$currentSection, "encoding"=>$part->encoding));
					
					$res = imagecreatefromstring($img);
					
					$domImg = new DOMElement("img");
					
					$attr = new DOMAttr("width", imagesx($res));
					$domImg->setAttribute($attr);
					$attr = new DOMAttr("height", imagesy($res));
					$domImg->setAttribute($attr);
					
					imagedestroy($res);
					
					if($this->isInlineElem($domImg)){
						array_push($this->imagesCid, "section_$currentSection");
					}
					
					$s ="<br><img src=\""."data:".Email::decodeType(5)."/".strtolower($part->subtype).";base64,".base64_encode($img)."\">";
					$result.=$s;

				}elseif(($part->type == 1 ||( $part->type == 2 && $part->subtype == "RFC822")) && isset($part->parts)){
						
					$s = "<br>".$this->recurs_getBody($subtype, $part, $currentSection);
					$result.=$s;

				}

			}
			
			return $result;
		}else{
			 
			if($msg->type==0 && !($msg->subtype == "HTML" && $subtype == "PLAIN")){
				
				$sectionDescriptor=array();
				
				if($msg->ifparameters == 1 ){
					foreach($msg->parameters as $p){
						if($p->attribute == "charset"){
							$sectionDescriptor["charset"]=$p->value;
						}
					}
				}
				
				$sectionDescriptor["encoding"]=$msg->encoding;
				$sectionDescriptor["section"]=$section;
				print_r($sectionDescriptor);
				$body=$this->getSectionContent($sectionDescriptor);
				
				$this->bodySize = $msg->bytes;
				if($msg->subtype == "HTML"){
					$body=$this->getImagesFromHTML($body);
				}
				return $body;
			}
			
			return null;
		}
		
	}
	
	
	
	public function getPlainTextBody(){
		
		return $this->recurs_getPlainTextBody($this->structure);
		
	}
	
	private function recurs_getPlainTextBody($msg, $section = ""){
	
		$currentSection=$section;
		
		if(isset($msg->parts)){
			
			$i=0;
			
			foreach($msg->parts as $part){
				
				$i++;
				
				if($section == "")
					$currentSection=$i;
				else
					$currentSection=$section.".".$i;
					
				return $this->recurs_getPlainTextBody($part, $currentSection);
				
			}
		}else{
			
			if($msg->type==0 && $msg->subtype=="PLAIN"){
				
				//$body=$this->imapServer->getContent($this->index, $section);
				$sectionDescriptor=array();
				$sectionDescriptor["encoding"]=$msg->encoding;
				$sectionDescriptor["section"]=$section;
				//charset TODO
				$body=$this->getSectionContent($sectionDescriptor);
				
				return $body;
				
			}
		}
	}
	
	
	public function getSender(){
		
		return Email::decodeHeaderString($this->headers->from[0]->mailbox."@".$this->headers->from[0]->host);
		
	}
		
	
	public function getSubject(){
		
		return Email::decodeHeaderString($this->headers->subject);
		
	}
	
	public function getBodySize(){
	
		return Email::decodeHeaderString($this->bodySize);
	
	}
		
	
	private function getImagesFromHTML($html){

		$doc = new DOMDocument();
		$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		
		$head = null;
		
		foreach($doc->getElementsByTagName("meta") as $meta){
			foreach($meta->attributes as $attribute){
				if(strtolower($attribute->name) == "http-equiv" &&  $attribute->value == "Content-Type"){
					$head = $meta->parentNode;
					$meta->parentNode->removeChild($meta);
					break;
				}
			}
		
		}
		
		if($head != null){
			$newMeta = $doc->createElement("meta");
			
			$domAttribute = $doc->createAttribute('http-equiv');
			$domAttribute->value = 'Content-Type';
			
			array_push($newMeta->attributes, $domAttribute);
			
			$domAttribute = $doc->createAttribute('content');
			$domAttribute->value = 'text/html; charset=UTF-8';
				
			array_push($newMeta->attributes, $domAttribute);
			
			$head->appendChild($newMeta);
			
		}
		
		
		foreach($doc->getElementsByTagName("img") as $img){
			
			foreach($img->attributes as $attribute){
				
				if(strtolower($attribute->name) == "src"){
					
					$imageSrcValueTemp =  $attribute->value;
					
					if(substr($imageSrcValueTemp, 0, 3) == "cid"){
						
						// Carico l'array contenente gli "src" contenenti immagini con prefisso "cid"
						// cioï¿½ immagini inline (tranne nel caso MUA Apple dove anche gli allegati sono 
						// riportanti inline con prefisso cid seguito da codice)
						
						if($this->isInlineElem($img)){
						
							array_push($this->imagesCid, substr($imageSrcValueTemp, 4));
						
						}
						
						$imageSrcValueTemp = $this->convertToDataInline(substr($attribute->value, 4));
						
						$attribute->value = $imageSrcValueTemp;
						
					}
					
				}
			}
				
		}
					
		return $doc->saveHTML();
		
	}
	
	
	private static function decodeType($type){
		
		switch ($type) {
			
			case 0:
			return "text";
			
			case 1:
			return "multipart";
			
			case 2:
			return "message";
			
			case 3:
			return "application";
			
			case 4:
			return "audio";
			
			case 5:
			return "image";
			
			case 6:
			return "video";
			
			case 7:
			return "other";
			
		}
		
	}

	private function convertToDataInline($cid){
		
		$element = $this->recurs_findById(trim($cid), $this->structure);
		
		if($element != null){
			
			return "data:".Email::decodeType($element["type"])."/".strtolower($element["subtype"]).";base64,".base64_encode($this->getSectionContent($element));
			
		}
		
	}
	
	private function recurs_findById($id, $msg, $section){
		
		$currentSection=$section;
		//se esistono ancora delle sottoparti della parte corrente del messaggio, prossimo passo di ricorsione;
		//altrimenti controlla la parte corrente
		
		if(isset($msg->parts)){
			
			$i=0;
			
			foreach($msg->parts as $part){
				
				if($msg->type==2 && $msg->subtype=='RFC822'){
					
					$currentSection=$section;
					
				}else{
					
					$i++;
					
					if($section == "")
						
						$currentSection=$i;
					
					else
						
						$currentSection=$section.".".$i;
					
				}
				
				$result = $this->recurs_findById($id, $part, $currentSection);
				
				if($result != null){
					
					return $result;
					
				}
	
			}
		}else{
			
			// check se l'id ï¿½ quello cercato			
			if($msg->ifid == 1 &&  ($msg->id == "<$id>" || $msg->id == $id ) ) {
				
				$filename=Email::decodeHeaderString($this->getFilenameFromSectionDescriptor($msg));
			
				return array(
						
					'section' => $section,
					'type' => $msg->type,
					'subtype' => $msg->subtype,
					'encoding' => $msg->encoding,
					'filename' => $filename
						
				);		
				
			}
		}
	}
	
	
	private function isInlineElem($img){
		return true;
	}
	
	
	public function dump( ){
		echo "<pre>FUNCTION dump - ";
		print_r( $this->structure);
		echo "</pre>";
	}
	
	
}




?>
