<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
include_file('core', 'mesVin', 'class', 'CaveVin');
class CaveVin extends eqLogic {
	public static $_widgetPossibility = array('custom' => array(
	        'visibility' => true,
	        'displayName' => true,
	        'optionalParameters' => true,
	));
	public static function AddCommande($eqLogic,$Name) {
		$Commande = CaveVinCmd::byEqLogicIdCmdName($eqLogic->getId(),$Name);//$eqLogic->getCmd(null,$_logicalId);
		if (!is_object($Commande))
		{
			$Commande = new CaveVinCmd();
			$Commande->setId(null);
			$Commande->setEqLogic_id($eqLogic->getId());
			$Commande->setType("info");
			$Commande->setSubType("binary");
			$Commande->setName($Name);
			$Commande->setTemplate('dashboard','Bouteille');
			$Commande->setTemplate('mobile','Bouteille');
			$Commande->setEventOnly(true);
			$Commande->setIsVisible(true);
			$Commande->save();
		}
		return $Commande;
	}
	public static function ImportVins($File) {
		$dir=dirname(__FILE__) .'/../../images/';
		if (!is_dir($dir)) 
			mkdir($dir);
		$zip = new ZipArchive(); 
		// On ouvre l’archive.
		if($zip->open($File) == TRUE)
		{
			$zip->extractTo($dir);
			$zip->close();
			$ListeVins=json_decode(file_get_contents($dir.'mesVin.sql'),true);
			foreach($ListeVins as $vin){
				$mesVin = new mesVin();
				utils::a2o($mesVin, jeedom::fromHumanReadable($vin));
				$mesVin->save();
			}
			unlink($dir.'mesVin.sql');
			return true;
		}
        	return false;
	}
	public static function ExportVins() {	
		$zipFile='/var/www/html/tmp/mesVin.zip';
		if(file_exists($zipFile))
			unlink($zipFile);
		$zip = new ZipArchive; 
		if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) { 
			log::add('CaveVin','debug','Création du fichier d\'export');	
			$zip->addFromString('mesVin.sql', json_encode(utils::o2a(mesVin::all())));
			$dir=dirname(__FILE__) .'/../../images/';
			$dh = opendir($dir); 
			while($file = readdir($dh)) { 	
				if ($file != '.' && $file != '..') { 
					log::add('CaveVin','debug','Ajout a l\'export:'.$dir.$file);	
					$zip->addFile($dir.$file,'etiquette/'.$file); 
				} 
			} 
			closedir($dh); 
			$zip -> close(); 
        		return $zipFile;
		}
        	return false;
	}
	public static function pull($_option) {
		log::add('CaveVin', 'debug', 'Objet mis à jour => ' . json_encode($_option));
		$Volet = CaveVin::byId($_option['CaveVin_id']);
		if (is_object($CaveVin) && $CaveVin->getIsEnable()) {
			$Commande = cmd::byId($_option['event_id']);
			if(is_object($Commande)){
				$array = utils::o2a($Commande);
				event::add('CaveVin::change', $array);
				//$Commande->setCollectDate('');
				//$Commande->event($_option['value']);
				//$Commande->save();
			}	
		}
	}
    	public function preSave() {
		$listener = listener::byClassAndFunction('CaveVin', 'pull', array('CaveVin_id' => intval($this->getId())));
		if (!is_object($listener))
		    $listener = new listener();
		$listener->setClass('CaveVin');
		$listener->setFunction('pull');
		$listener->setOption(array('CaveVin_id' => intval($this->getId())));
		$listener->emptyEvent();
		foreach($this->getCmd() as $cmd){
			$listener->addEvent(str_replace('#','',$cmd->getConfiguration('SortieBoutielle')));
		}
		$listener->save();	
		for($heightCase=1;$heightCase<=$this->getConfiguration('heightCase');$heightCase++){
			for($widthCase=1;$widthCase<=$this->getConfiguration('widthCase');$widthCase++){
				$Name=$this->getName().'_'.$widthCase."x".$heightCase;
				self::AddCommande($this,$Name);
			}
		}
    	}
  	public function toHtml($_version = 'mobile',$Dialog=true) {
		$_version = jeedom::versionAlias($_version);
		$replace = array(
			'#id#' => $this->getId(),
			'#name#' => ($this->getIsEnable()) ? $this->getName() : '<del>' . $this->getName() . '</del>',
			'#eqLink#' => $this->getLinkToConfiguration(),
			'#background#' => $this->getBackgroundColor($_version),				
			'#height#' => $this->getDisplay('height', 'auto'),
			'#width#' => $this->getDisplay('width', '250'),
			'#dialog#' => $Dialog,
		);	
		$HtmlCasier='';
		for($heightCase=1;$heightCase<=$this->getConfiguration('heightCase');$heightCase++){
			$HtmlCasier.= '<tr>';
			for($widthCase=1;$widthCase<=$this->getConfiguration('widthCase');$widthCase++){
					$HtmlCasier.='<td>#'.$this->getName().'_'.$widthCase.'x'.$heightCase.'#</td>';
				}
			$HtmlCasier.='</tr>';
		}
		if ($this->getIsEnable()) {
			foreach ($this->getCmd(null, null, true) as $cmd) {
				 $vin=mesVin::byId($cmd->getLogicalId());
				 if(is_object($vin)){
					$replaceCasierInfo['#Vigification'] = $vin->getVinification();
				 	$replaceCasierInfo['#Couleur#'] = $vin->getCouleur();
				 	$replaceCasierInfo['#NbBouteille#'] = $vin->getNbVin();
				 }else{
					$replaceCasierInfo['#Vigification'] = "";
				 	$replaceCasierInfo['#Couleur#'] = "Rouge";
				 	$replaceCasierInfo['#NbBouteille#'] = "0";
				 }
				 	
				 $replaceCasier['#'.$cmd->getName().'#'] = template_replace($replaceCasierInfo,$cmd->toHtml($_version));
			}
		}   
		$replace['#Casier#']=template_replace($replaceCasier,$HtmlCasier) ;
		return template_replace($replace, getTemplate('core', $_version, 'eqLogic','CaveVin'));
	}
}
class CaveVinCmd extends cmd {
	public function preSave() {
		$url = network::getNetworkAccess('external') . '/plugins/reveil/core/api/jeeCaveVin.php?apikey=' . jeedom::getApiKey('CaveVin') . '&id=' . $this->getId();
		$this->setConfiguration('url', $url);
	}
	public function execute($_options = array()) {
	}
}
?>
