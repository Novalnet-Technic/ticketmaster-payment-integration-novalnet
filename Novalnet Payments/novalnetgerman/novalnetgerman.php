<?php
## no direct access
error_reporting(0);
defined( '_JEXEC' ) or die( 'Restricted access' );
 
## Import library dependencies
jimport('joomla.plugin.plugin');
 
class plgRDmediaNovalnetGerman extends JPlugin
{
/**
 * Constructor
 *
 * For php4 compatability we must not use the __constructor as a constructor for
 * plugins because func_get_args ( void ) returns a copy of all passed arguments
 * NOT references.  This causes problems with cross-referencing necessary for the
 * observer design pattern.
 */
 function plgRDmediaNovalnetGerman( &$subject, $params  ) {
 
    parent::__construct( $subject , $params  );
	
	## Loading language:	
	$lang = JFactory::getLanguage();
	$lang->load('plg_rdmedia_novalnetgerman', JPATH_ADMINISTRATOR);	

	## load plugin params info
 	$plugin =& JPluginHelper::getPlugin('novalnetgerman', 'novalnetgerman');

	$this->vendor_id = trim($this->params->def('merchant_id'));
	$this->auth_code = trim($this->params->def('merchant_authcode'));
	$this->product_id = trim($this->params->def('product_id'));
	$this->tariff_id = trim($this->params->def('tariff_id'));
	$this->test_mode = $this->params->def('test_mode');
	$this->manual_limit = intval(trim($this->params->def('manual_limit')));
	$this->second_product_id = trim($this->params->def('second_product_id'));
	$this->second_tariff_id = trim($this->params->def('second_tariff_id'));
	$this->layout = $this->params->def('layout');
	$this->success_tpl = $this->params->def('success_tpl');
	$this->failure_tpl = $this->params->def('failure_tpl');
	$this->currency = $this->params->def('currency');
	$this->elv_de_acdc = $this->params->def('elv_de_acdc');
	$this->key = '2';
	$this->novalnet_response = array();
	$this->novalnet_request = array();
	$this->payment_type = 'novalnet_german';
	$this->paygate_url='https://payport.novalnet.de/paygate.jsp';
	$this->nnSiteUrl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']?'https://':'http://';

	## Including required paths to calculator.
	$path_include = JPATH_SITE.DS.'components'.DS.'com_ticketmaster'.DS.'assets'.DS.'helpers'.DS.'get.amount.php';
	include_once( $path_include );

	## Getting the global DB session
	$session =& JFactory::getSession();
	## Gettig the orderid if there is one.
	$this->ordercode = $session->get('ordercode');
	
	## Getting the amounts for this order.
	$this->amount = _getAmount($this->ordercode);
	$this->fees	  = _getFees($this->ordercode); 

	## ROY >> Including required paths to get more datails about donator
	$path_include = JPATH_SITE.DS.'components'.DS.'com_ticketmaster'.DS.'assets'.DS.'helpers'.DS.'roy.php';
	include_once( $path_include );
		
	$user = JFactory::getUser();	
	$db = JFactory::getDBO();
	$donatordetails = _getDonatorEmail($this->ordercode);
	
	$sql = 'SELECT * FROM #__ticketmaster_country WHERE country_id = "'.(int)$donatordetails->country_id.'" ';
	$db->setQuery($sql);
	$country = $db->loadObject();
	$this->country = $country->country_2_code;
	
	preg_match("/-(.*)/", $_GET['language'], $language);
	$this->language = $language[1];
	$ip=$this->_getRealIpAddr();
	$this->remoteip = ($ip == '::1') ? '127.0.0.1': $ip;

	$this->orderid = $donatordetails->orderid;
	$this->customerno = $donatordetails->userid;
	$this->firstname = $donatordetails->firstname;
	$this->lastname = $donatordetails->name;
	$this->street = $donatordetails->address;	
	$this->city = $donatordetails->city;	
	$this->zipcode = $donatordetails->zipcode;	
	$this->telephone = $donatordetails->phonenumber;
	$this->email = $donatordetails->emailaddress;
	$this->uniqid = uniqid();	

	## Return URLS to your website after processing the order.
	$this->return_url = JURI::root().'index.php?option=com_ticketmaster&view=transaction&payment_type=novalnetgerman&Itemid='.$this->ordercode;
	$this->cancel_url = JURI::root().'index.php?option=com_ticketmaster&view=transaction&payment_type=novalnetgerman_failed&Itemid='.$this->ordercode;

	$this->form_url = JURI::root().'index.php?option=com_ticketmaster&view=transaction&payment_type=novalnetgerman_form&Itemid='.$this->ordercode;	
 }
 
/**
 * Plugin method with the same name as the event will be called automatically.
 * You have to get at least a function called display, and the name of the processor
 * Now you should be able to display and process transactions.
 * 
*/

	 function display()
	 {
		$app = &JFactory::getApplication();
		
		## Loading the CSS file.
		$document = &JFactory::getDocument();
		$document->addStyleSheet( JURI::root(true).'/plugins/rdmedia/novalnetgerman/rdmedia_novalnetgerman/css/novalnetgerman.css' );	
		$session =& JFactory::getSession();
		$user =& JFactory::getUser();
		$ordertotal = round($this->amount, 2) * 100;		

			## Check if this is Joomla 2.5 or 3.0.+
			$isJ30 = version_compare(JVERSION, '3.0.0', 'ge');
			
			## This will only be used if you use Joomla 2.5 with bootstrap enabled.
			## Please do not change!
			
			if(!$isJ30){
				if($config->load_bootstrap == 1){
					$isJ30 = true;
				}
			}

			if((isset($this->firstname) && empty($this->lastname)) || (isset($this->lastname) && empty($this->firstname))){
				if(empty($this->lastname)){			
					$name = preg_split("/[\s]+/", $this->firstname, 2);
				}elseif(empty($this->firstname)){	
					$name = preg_split("/[\s]+/", $this->lastname, 2);	
				}
				$this->firstname = $name[0];
				if(isset($name[1])){
					$this->lastname = $name[1];
				}else{
					$this->lastname = $name[0];
				}	
			}else{
				$this->firstname = $this->firstname;
				$this->lastname = $this->lastname;
			}
			if($this->doValidation()){			
				if ($this->manual_limit && $this->second_product_id && $this->second_tariff_id && ($ordertotal >= $this->manual_limit)) {
					$this->product_id = $this->second_product_id;
					$this->tariff_id = $this->second_tariff_id;
				}
		
				$this->novalnet_request = array(
					'vendor' 		=> $this->vendor_id,
					'product' 		=> $this->product_id,
					'tariff' 		=> $this->tariff_id,
					'auth_code' 		=> $this->auth_code,
					'test_mode'		=> $this->test_mode,	
					'amount' 		=> $ordertotal,
					'order_id' 		=> $this->orderid,
					'order_no' 		=> $this->orderid,
					'first_name' 		=> $this->firstname,
					'last_name' 		=> $this->lastname,
					'email' 		=> $this->email,
					'gender' 		=> 'u',
					'street' 		=> $this->street,
					'search_in_street' 	=> '1',
					'city' 			=> $this->city,
					'zip' 			=> $this->zipcode,
					'tel' 			=> $this->telephone,
					'fax' 			=> '',
					'birthday' 		=> '',
					'country_code' 		=> $this->country,
					'country' 		=> $this->country,
					'language' 		=> $this->language,
					'lang'			=> $this->language,
					'currency' 		=> $this->currency,
					'remote_ip' 		=> $this->remoteip,
					'use_utf8' 		=> 1,
					'customer_no' 		=> $this->customerno,
					'key' 			=> $this->key,		
				);
				$this->novalnet_request = array_map("trim", $this->novalnet_request);
			if(!isset($this->novalnet_response['tid']) && empty($this->novalnet_response['tid'])){				
				if($this->layout == 1 && $isJ30 == true ){			 
					echo '<img src="'.$this->nnSiteUrl.'www.novalnet.de/img/NN_Logo_T.png" alt="'.JText::_('PLG_NOVALNETGERMAN_NOVALNET_LOGO_ALT').'" title="Novalnet AG" />'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_NAME_WT_NOVALNET');
					if($this->test_mode == '1'){
						echo "<br /><span style='color:red;'>".JText::_('PLG_NOVALNETGERMAN_FRONTEND_TESTMODE_DESC')."</span>";
					}
					echo '<form action="'.$this->form_url.'" method="post" name="novalnetForm">';
					foreach($this->novalnet_request as $k => $v) {		
						$inputvalues[] = '<input type="hidden" name="' . $k . '" value="' . $v . '" />' . "\n";		
					}
					echo implode('', $inputvalues);				
					echo    '<button class="btn btn-block btn-success" alt="'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_LOGO_ALT').'" title="'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_LOGO_TITLE').'" style="margin-top: 8px;" type="submit" id="enter_german" name="enter_german">'.JText::_( 'Make Payment' ).'</button>';			
					echo 	'</form>';
				}else{
					echo '<form action="'.$this->form_url.'" method="post" name="novalnetForm">';
					echo '<div id="plg_rdmedia_novalnetgerman" alt="'.JText::_('PLG_NOVALNETGERMAN_NOVALNET_LOGO_ALT').'" title="Novalnet AG">';
					echo '<div id="plg_rdmedia_novalnetgerman_cards">'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_NAME_WT_NOVALNET');
					if($this->test_mode == '1'){
						echo "<br /><span style='color:red;'>".JText::_('PLG_NOVALNETGERMAN_FRONTEND_TESTMODE_DESC')."</span>";
					}
					echo '</div>';
					foreach($this->novalnet_request as $k => $v) {		
						$inputvalues[] = '<input type="hidden" name="' . $k . '" value="' . $v . '" />' . "\n";		
					}
					echo implode('', $inputvalues);				
					echo '<div id="plg_rdmedia_novalnetgerman_confirmbutton">';
					echo    '<input type="submit" id="enter_german" name="enter_german" value="" class="novalnetgerman_button" alt="'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_LOGO_ALT').'" title="'.JText::_('PLG_NOVALNETGERMAN_PAYMENT_LOGO_TITLE').'" style="width: 116px;">';
					echo '</div>';	
					echo '</div>';
					echo '</form>';
				}
			}
			return true;
		}
	 }

	function novalnetgerman_failed(){
		$db = JFactory::getDBO();
		$session =& JFactory::getSession();
		$this->novalnet_response = $session->get('novalnet_response_german');
		$error =  JError::raiseWarning(500, JText::_($this->novalnet_response['status_desc']));

		$note = $this->Novalnet_prepareComment();
		$note = nl2br($note); 

		$search_query = 'SELECT * FROM #__ticketmaster_remarks WHERE ordercode = '.$this->ordercode.'';
		$db->setQuery($search_query);
		$data = $db->loadObject();

		if($data->ordercode == $this->ordercode && $this->novalnet_response['tid'] != ''){
			$sql = "UPDATE #__ticketmaster_remarks SET remarks = CONCAT(remarks, '<br /><br /><br />', '".$note."'), ordercode = '".$this->ordercode."'WHERE ordercode = '".$this->ordercode."'";
			$db->setQuery($sql);
			$update_comments = $db->loadObject();
		}elseif($this->novalnet_response['tid'] != ''){
			$sql = "INSERT INTO #__ticketmaster_remarks (remarks, ordercode) VALUES ('".$note."', '".$this->ordercode."')";
			$db->setQuery($sql);
			$update_comments = $db->loadObject();
		}

		$session->clear('ordercode');
		$session->clear('novalnet_request_german');
		$session->clear('novalnet_response_german');	
		$session->clear($this->ordercode);
		$session->clear('coupon');
	}	

	/**
	 * Get client Ip address
	 *
	 * @param
	 */
	function _getRealIpAddr()
	{
		if($this->isPublicIP(@$_SERVER['HTTP_X_FORWARDED_FOR'])) return @$_SERVER['HTTP_X_FORWARDED_FOR'];
		if($iplist=explode(',', @$_SERVER['HTTP_X_FORWARDED_FOR'])) {
			if($this->isPublicIP($iplist[0])) return $iplist[0];
		}
		if ($this->isPublicIP(@$_SERVER['HTTP_CLIENT_IP'])) return @$_SERVER['HTTP_CLIENT_IP'];
		if ($this->isPublicIP(@$_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) return @$_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
		if ($this->isPublicIP(@$_SERVER['HTTP_FORWARDED_FOR']) ) return @$_SERVER['HTTP_FORWARDED_FOR'];
		
		return $_SERVER['REMOTE_ADDR'];
	}

	function isPublicIP($value) {
		return (count(explode('.', $value)) == 4 && !preg_match('~^((0|10|172\.16|192\.168|169\.254|255|127\.0)\.)~', $value));
	}

	function doValidation() {
		 if(!$this->is_digits($this->vendor_id) || !$this->is_digits($this->product_id) || !$this->auth_code || !$this->is_digits($this->tariff_id)){
		$error =  JError::raiseWarning(500, JText::_('PLG_NOVALNETGERMAN_BACK_END_ERR'));
		return false;	
 		}	
 		elseif($this->manual_limit && (!preg_match('/^\d+$/',$this->manual_limit) || !preg_match('/^\d+$/',$this->second_product_id) || !preg_match('/^\d+$/',$this->second_tariff_id))){
			$error =  JError::raiseWarning(500, JText::_('PLG_NOVALNETGERMAN_MANUAL_LIMIT_ERR'));
			return false;
		}
		if(!$this->email || !$this->firstname || !$this->lastname){
			$error =  JError::raiseWarning(500, JText::_('PLG_NOVALNETGERMAN_CUST_NAME_EMAIL_ERR'));
			return false;
		}
		return true;	   
	}
	
	public function is_digits($element) {
	  return preg_match("/^\d+$/", $element);
	}

	function perform_https_request($url, $form) {
		global $globaldebug;		
		## requrl: the URL executed later on
		if($globaldebug) print "<BR>perform_https_request: $url<BR>\n\r\n";
		if($globaldebug) print "perform_https_request: $form<BR>\n\r\n";
		
		## some prerquisites for the connection
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);  // a non-zero parameter tells the library to do a regular HTTP post.
		curl_setopt($ch, CURLOPT_POSTFIELDS, $form);  // add POST fields
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);  // don't allow redirects
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);  // decomment it if you want to have effective ssl checking
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // decomment it if you want to have effective ssl checking
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);  // return into a variable
		curl_setopt($ch, CURLOPT_TIMEOUT, 240);  // maximum time, in seconds, that you'll allow the CURL functions to take
		
		## establish connection
		$data = curl_exec($ch);
		
		## determine if there were some problems on cURL execution
		$errno = curl_errno($ch);
		$errmsg = curl_error($ch);
		
		###bug fix for PHP 4.1.0/4.1.2 (curl_errno() returns high negative value in case of successful termination)
		if($errno < 0) $errno = 0;
		##bug fix for PHP 4.1.0/4.1.2
		
		if($globaldebug)
		{
			print_r(curl_getinfo($ch));
			echo "<BR><BR>\n\n\nperform_https_request: cURL error number:" . $errno . "<BR>\n";
			echo "\n\n\nperform_https_request: cURL error:" . $error . "<BR>\n";
		}
		
		#close connection
		curl_close($ch);
		## read and return data from novalnet paygate
		if($globaldebug) print "<BR>\n" . $data;
		
		return array ($errno, $errmsg, $data);
   	}
	
	function Novalnet_TestOrder() {
    		$test_order_status = (((isset($this->novalnet_response['test_mode']) && $this->novalnet_response['test_mode'] == 1) || (isset($this->test_mode) && $this->test_mode == 1)) ? 1 : 0 );
    		if($test_order_status == '1'){
			return '1';
		}
       			return '0';
	}

	function _postBackParam(){
		$callBackParams = array(
            'vendor' => $this->novalnet_request['vendor'],
		    'auth_code' => $this->novalnet_request['auth_code'],
            'product' => $this->novalnet_request['product'],
            'tariff' => $this->novalnet_request['tariff'],
            'test_mode' => (($this->Novalnet_TestOrder() == 1) ? 1 : 0),
            'key' => '2',
           	'status' => '100',
            'tid' => $this->novalnet_response['tid'],
            'order_no' => $this->novalnet_response['order_no'],
        );
	$callBackParams = array_map("trim", $callBackParams);
        //Set order number for last success transaction
        if(!array_search('', $callBackParams)){
		if(preg_match('/^\d+$/',$callBackParams['vendor']) && 
			preg_match('/^\d+$/',$callBackParams['product']) &&
				preg_match('/^\d+$/',$callBackParams['tariff']) &&
					!empty($callBackParams['auth_code'])){
 						list($errno, $errmsg, $data) = $this->perform_https_request($this->paygate_url, $callBackParams);
			}
		}	
	}
	
	function novalnetgerman_form(){
		$session =& JFactory::getSession();
		if(isset($_REQUEST['enter_german'])){
			$session->set("novalnet_request_german", $_POST);
		} 
		echo '<div class=component-content">
		      <div class=item-page">
		      <form action="" method="post" name="novalnetPaymentForm">
  		      <fieldset>
	  		<legend>'.JText::_('PLG_NOVALNETGERMAN_REQUIRED_FIELD_LABLE').' :</legend>
    			<div style="vertical-align:top">
			<div style="display:inline-block;vertical-align:top;padding:0px 10px 10px;width:250px;">				
				<label for="kontoinhaber">'.JText::_('PLG_NOVALNETGERMAN_ACC_HOLDER').'*:</label>
				  <input type="text" name="kontoinhaber" id="kontoinhaber" value="" autocomplete=OFF /><br />
				<label for="kontonr">'.JText::_('PLG_NOVALNETGERMAN_ACC_NUMBER').'*:</label>
				  <input type="text" name="kontonr" id="kontonr" value="" autocomplete=OFF /><br />
				<label for="blz">'.JText::_('PLG_NOVALNETGERMAN_BANK_CODE').'*:</label>
				  <input type="text" name="blz" id="blz" value="" autocomplete=OFF /><br />';
			if(isset($this->elv_de_acdc) && $this->elv_de_acdc == 1){
				echo "<input type='checkbox' name='elvdeacdc' id='elvdeacdc' value='1' autocomplete=OFF /><a href='https://www.novalnet.de/img/acdc_info.png' target='_blank' onClick='popup(this)'>*".JText::_('PLG_NOVALNETGERMAN_ACDC_FRONTEND')."</a>";
			}		  
			echo '</div>
  			</div>
		     </fieldset>
  		     <input type="submit" name="elv_de_form" id="elv_de_form" value="'.JText::_('PLG_NOVALNETGERMAN_BUTTON_PAYMENT_PROCEED').'"/>
		     </form>
		     </div>
		     </div>';
		if(isset($_REQUEST['elv_de_form'])){
			$this->novalnet_request = $_POST;
			$this->novalnet_request = array_map("trim", $this->novalnet_request);
			if($this->doFormValidation()){
				$payment_params = array(
						'bank_account_holder' => $this->novalnet_request['kontoinhaber'],
						'bank_account' => $this->novalnet_request['kontonr'],
						'bank_code' => $this->novalnet_request['blz'],
						);
				if(isset($this->novalnet_request['elvdeacdc']) && $this->novalnet_request['elvdeacdc'] == 1){
								$payment_params['acdc'] = 1;
						}        			
				$this->novalnet_request = array_merge($session->get('novalnet_request_german'), $payment_params);
				$this->novalnet_request = array_map("trim", $this->novalnet_request);
				list($errno, $errmsg, $data) = $this->perform_https_request($this->paygate_url, $this->novalnet_request);
				parse_str($data, $novalnet_response);
				$this->novalnet_response = $novalnet_response;
				$response = $session->set("novalnet_response_german", $this->novalnet_response);
				if($this->novalnet_response['status'] == 100){
					header( "Location: ". $this->return_url);
				}else{
					header( "Location: ". $this->cancel_url);
				}
			}
		}
	}

	function doFormValidation(){
		if(!preg_match("/^\d{5,}$/",$this->novalnet_request['kontonr']) || !preg_match("/^\d{3,}$/",$this->novalnet_request['blz']) || preg_match("/[#%\^<>@$=*!]+/i",$this->novalnet_request['kontoinhaber'])){
			$error =  JError::raiseWarning(500, JText::_('PLG_NOVALNETGERMAN_PAYMENT_ERR'));
			return false;
		}
		if (($this->elv_de_acdc == 1) && (!isset($this->novalnet_request['elvdeacdc']) || $this->novalnet_request['elvdeacdc'] != 1)) { 
			$error =  JError::raiseWarning(500, JText::_('PLG_NOVALNETGERMAN_ACDC_ERR'));
			return false;
		}
		return true;
	}			

	function Novalnet_prepareComment() {	
		$sNewLine = "\n";
		$note = '';
		if($this->novalnet_response['status'] == '100'){	
			if($this->Novalnet_TestOrder() == 1) {
				$note .= "<b>" . JText::_('PLG_NOVALNETGERMAN_TEST_ORDER') . "</b>" . $sNewLine;
			}
				$note .= "<b>" . JText::_('PLG_NOVALNETGERMAN_PAYMENT_NAME') . "</b>" . $sNewLine;	
				$note .= JText::_('PLG_NOVALNETGERMAN_TRANSACTION_ID_MESSAGE') . ' ' . "<b>" . $this->novalnet_response['tid'] ."</b>" . $sNewLine . $sNewLine;
		}else{
			if(isset($this->novalnet_response['status_text']) || isset($this->novalnet_response['status_desc'])){
				$status = $this->novalnet_response['status_text'] ? $this->novalnet_response['status_text'] : $this->novalnet_response['status_desc'];
		}
			$note .= "<b>" . JText::_('PLG_NOVALNETGERMAN_PAYMENT_NAME') . "</b>" . $sNewLine;	
			$note .= JText::_('PLG_NOVALNETGERMAN_TRANSACTION_ID_MESSAGE') . ' ' . "<b>" . $this->novalnet_response['tid'] ."</b>" . $sNewLine;
			$note .= $status . $sNewLine . $sNewLine;
		}
		return $note;
	}

	function novalnetgerman() {		
		## Getting the global DB session
		$session =& JFactory::getSession();
		## Gettig the orderid if there is one.
		$this->novalnet_response = $session->get('novalnet_response_german');
		$this->novalnet_request = $session->get('novalnet_request_german');			

		$db = JFactory::getDBO();
		// Load user_profile plugin language
		$lang = JFactory::getLanguage();
		$lang->load('plg_rdmedia_novalnetgerman', JPATH_ADMINISTRATOR);
	
		## Include the confirmation class to sent the tickets. 
		$path = JPATH_ADMINISTRATOR.DS.'components'.DS.'com_ticketmaster'.DS.'classes'.DS.'createtickets.class.php';
		$override = JPATH_ADMINISTRATOR.DS.'components'.DS.'com_ticketmaster'.DS.'classes'.DS.'override'.DS.'createtickets.class.php';	

		if($this->novalnet_response['status'] == 100){

		$note = $this->Novalnet_prepareComment();
		$note = nl2br($note);	

		$search_query = 'SELECT * FROM #__ticketmaster_remarks WHERE ordercode = '.$this->ordercode.'';
		$db->setQuery($search_query);
		$data = $db->loadObject();

		if($data->ordercode == $this->ordercode && $this->novalnet_response['tid'] != ''){
			$sql = "UPDATE #__ticketmaster_remarks SET remarks = CONCAT(remarks, '<br /><br /><br />".$note."'), ordercode = '".$this->ordercode."'WHERE ordercode = '".$this->ordercode."'";
			$db->setQuery($sql);
			$update_comments = $db->loadObject();
		}elseif($this->novalnet_response['tid'] != ''){
			$sql = "INSERT INTO #__ticketmaster_remarks (remarks, ordercode) VALUES ('".$note."', '".$this->ordercode."')";
			$db->setQuery($sql);
			$update_comments = $db->loadObject();
		}

		$query = 'UPDATE #__ticketmaster_orders SET paid = 1, published = 1 WHERE ordercode = '.(int)$this->ordercode.'';
		$db->setQuery($query);
		$update_order = $db->loadObject();
					
		## Getting the latest logged in user.
		$user = & JFactory::getUser();
		
		JTable::addIncludePath(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_ticketmaster'.DS.'tables');
		$row =& JTable::getInstance('transaction', 'Table');	
				
		## Pickup All Details and create foo=bar&baz=boom&cow=milk&php=hypertext+processor
		$payment_details = http_build_query($this->novalnet_response);
		$payment_type = $this->payment_type;
		$orderid = $this->ordercode;
						
		## Now store all data in the transactions table
		$row->transid = $this->novalnet_response['tid'];
		$row->userid = $this->customerno;
		$row->details = $payment_details;
		$row->amount = $this->novalnet_response['amount'];
		$row->type = $this->payment_type;
		$row->email_paypal = $this->email;
		$row->orderid = $this->ordercode;

		$search_query = 'SELECT * FROM #__ticketmaster_transactions WHERE transid = '.$this->novalnet_response['tid'].'';
		$db->setQuery($search_query);
		$data = $db->loadObject();	
		$transactions = count($data);

		if($transactions == 0 && $this->novalnet_response['tid'] != ''){
			$row->store();
		}	
		
		$this->_postBackParam();					
						
		$query = 'SELECT * FROM #__ticketmaster_orders WHERE ordercode = '.(int)$this->ordercode.'';
	
		## Do the query now	
		$db->setQuery($query);
		$data = $db->loadObjectList();
						
		$k = 0;
		for ($i = 0, $n = count($data); $i < $n; $i++ ){
							
		$row  = &$data[$i];
				
		## Check if the override is there.
		if (file_exists($override)) {
			## Yes, now we use it.
			require_once($override);
		} else {
			## No, use the standard
			require_once($path);
		}	
				
		if(isset($row->orderid)) {  
			$creator = new ticketcreator( (int)$row->orderid );  
			$creator->doPDF();
		}  				
		$k=1 - $k;
		}
						
		## Include the confirmation class to sent the tickets. 
		$path_include = JPATH_ADMINISTRATOR.DS.'components'.DS.'com_ticketmaster'.DS.'classes'.DS.'sendonpayment.class.php';
		include_once( $path_include );
						
		## Sending the ticket immediatly to the client.
		$creator = new sendonpayment( (int)$this->ordercode );  
		$creator->send();
					
		## Removing the session, it's not needed anymore.
		$session =& JFactory::getSession();
		$session->clear('ordercode');
		$session->clear('novalnet_request_german');
		$session->clear('novalnet_response_german');
		$session->clear('ordercode');		
		$session->clear($this->ordercode);
		$session->clear('coupon');
	
		## Getting the desired info from the configuration table
		$sql = "SELECT * FROM #__ticketmaster_emails WHERE emailid = ".(int)$this->success_tpl."";
		$db->setQuery($sql);
		$config = $db->loadObject();

		## Getting the desired info from the configuration table
		$sql = "SELECT * FROM #__users WHERE id = ".(int)$this->customerno."";
		$db->setQuery($sql);
		$user = $db->loadObject();							
				
		echo '<h1>'.$config->mailsubject.'</h1>';
			
		$message = str_replace('%%NOVALNETTRANSACTIONDETAILS%%', $note, $config->mailbody);
										
		## Imaport mail functions:
		jimport( 'joomla.mail.mail' );
											
		## Set the sender of the email:
		$sender[0] = $config->from_email;
		$sender[1] = $config->from_name;					
		## Compile mailer function:			
		$obj = JFactory::getMailer();
		$obj->setSender( $sender );
		$obj->isHTML( true );
		$obj->setBody ( $message );				
		$obj->addRecipient($this->email);
		## Send blind copy to site admin?
		if ($config->receive_bcc == 1){
			if ($config->reply_to_email != ''){
				$obj->addRecipient($obj->reply_to_email);
			}	
		}					
		## Add reply to and subject:					
		$obj->addReplyTo($config->reply_to_email);
		$obj->setSubject($config->mailsubject);
						
		if ($config->published == 1){
			$sent = $obj->Send();						
		}								
						
		echo $note;
		$session->clear($this->ordercode);
		$session->clear('ordercode');
		$session->clear('novalnet_request_german');
		$session->clear('novalnet_response_german');
		$session->clear('ordercode');
		$session->clear('coupon');								
		}
	}
}	 
?>
