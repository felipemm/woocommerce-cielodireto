<?php
/*
Plugin Name: WooCommerce Cielo Direto
Plugin URI: http://felipematos.com/loja
Description: Adiciona o gateway de pagamento para a Cielo no WooCommerce
Version: 2.0
Author: Felipe Matos <chucky_ath@yahoo.com.br>
Author URI: http://felipematos.com
License: Commercial
Requires at least: 3.4
Tested up to: 3.4.1
*/

add_action( 'plugins_loaded', 'f2m_gateway_cielodireto' );


function f2m_gateway_cielodireto() {

//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
//=======>>> ADMIN PANEL <<<=======
//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
	// Admin Gateway Option Actions
	//add_action('woocommerce_order_actions', array(&$this, 'cielodireto_order_options')); //create the action buttons in the order page
	add_action('woocommerce_order_actions_end', 'cielodireto_order_options'); //create the action buttons in the order page
	add_action('save_post', 'cielodireto_process_options'); //execute the action selected in the order page

	
	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_process_options
	//Descrição: Irá executar a ação que foi selecionada dentre as ações disponíveis na página do pedido
	//           dentro do painel de controle.
	//---------------------------------------------------------------------------------------------------
	function cielodireto_process_options($post_id){
		// don't run the echo if this is an auto save
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
			return;
		
		$post_object = get_post( $post_id );
		// don't run the echo if the function is called for saving revision.
		if ( $post_object->post_type == 'revision' )
			return;

		remove_action('save_post', 'cielodireto_process_options');

		global $woocommerce;

		//get the gateway settings
		$form_field_settings = ( array ) get_option( 'woocommerce_cielodireto_settings' );

		//get the TID for the order
		$tid = get_post_meta($post_id, 'cielodireto_tid', true);

		//create the cielo order object
		$pedido = new Pedido(plugin_dir_path(__FILE__).'cielodireto_admin.log');
		$pedido->tid = $tid;
		$pedido->dadosEcNumero = $form_field_settings['credential'];
		$pedido->dadosEcChave = $form_field_settings['token'];

		//create woocommerce order object
		$order = &new WC_Order( $post_id );

		//check which action the user selected and do appropriate processing
		if(isset($_POST['cielodireto_authorize']) && $_POST['cielodireto_authorize']){
			$objResposta = $pedido->RequisicaoAutorizacaoTid();
			update_post_meta($order->id, 'cielodireto_autorizacao' , $objResposta->autorizacao->asXML());

		}elseif(isset($_POST['cielodireto_capture']) && $_POST['cielodireto_capture']){
			$objResposta = $pedido->RequisicaoCaptura(null, null);
			update_post_meta($order->id, 'cielodireto_captura' , $objResposta->captura->asXML());

		}elseif(isset($_POST['cielodireto_cancel']) && $_POST['cielodireto_cancel']){
			$objResposta = $pedido->RequisicaoCancelamento();
			update_post_meta($order->id, 'cielodireto_cancelamento' , $objResposta->cancelamento->asXML());

		}elseif(isset($_POST['cielodireto_check']) && $_POST['cielodireto_check']){
			$objResposta = $pedido->RequisicaoConsulta();
			update_post_meta($order->id, 'cielodireto_autenticacao' , $objResposta->autenticacao->asXML());
			update_post_meta($order->id, 'cielodireto_autorizacao' , $objResposta->autorizacao->asXML());
			update_post_meta($order->id, 'cielodireto_captura' , $objResposta->captura->asXML());
			update_post_meta($order->id, 'cielodireto_cancelamento' , $objResposta->cancelamento->asXML());
			//var_dump($objResposta->asXML());
		}

		if(isset($objResposta) && $objResposta->getName() != "erro"){
			update_post_meta($order->id, 'cielodireto_status' , (int)$objResposta->status);
			
			// We are here so lets check status and do actions
			update_order($order, (int)$objResposta->status, $Pedido);
		}
		add_action('save_post', 'cielodireto_process_options');
	} //Fim da função cielodireto_process_options




	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_order_options
	//Descrição: Cria os botões dentro da página do pedido no painel de controle.
	//---------------------------------------------------------------------------------------------------
	function cielodireto_order_options($order_id){
		$cielo_status = get_post_meta($order_id, 'cielodireto_status', true);

		$actions  = "<li class='wide'><table>";
		$actions .= 	"<tr><h3>Ações Cielo</h3>";
		$actions .= 		"<td><input type='submit' style='width:120px;' class='button tips' name='cielodireto_capture'   value='Capturar'  ".(in_array($cielo_status, array(4,12)) ? '' : 'disabled')." data-tip='Realiza a captura do pagamento feito na Cielo.' /> </td>";
		$actions .= 		"<td><input type='submit' style='width:120px;' class='button tips' name='cielodireto_authorize' value='Autorizar' ".(in_array($cielo_status, array(2,3)) ? '' : 'disabled')." data-tip='Autoriza a transação na Cielo.' /> </td>";
		$actions .= 	"</tr>";
		$actions .= 	"<tr>";
		$actions .= 		"<td><input type='submit' style='width:120px;' class='button tips' name='cielodireto_cancel'  value='Cancelar'  ".(in_array($cielo_status, array(4,6)) ? '' : 'disabled')." data-tip='Cancela a transação na Cielo.' /> </td>";
		$actions .= 		"<td><input type='submit' style='width:120px;' class='button tips' name='cielodireto_check'   value='Consultar' data-tip='Realiza uma consulta da transação na Cielo.' /> </td>";
		$actions .= 	"</tr>";
		$actions .= "</table></li>";
		
		
		echo $actions;
	} //Fim da função cielodireto_order_options

	//---------------------------------------------------------------------------------------------------
	//Função: update_order
	//Descrição: atualiza o status do pedido de acordo com o status da cielo e o status definido no
	//           painel de controle do plugin.
	//---------------------------------------------------------------------------------------------------
	function update_order($order, $status, $PedidoCielo){
		global $woocommerce;
		
		$new_status = '';
		$note = '';

		$settings = array();
		foreach ($woocommerce->payment_gateways->payment_gateways as $key => $object){
			if(get_class($object) == 'F2M_Cielo_Direto'){
				$settings = $object;
				break;
			}
		}	
		
		
		switch ($status){

			case 0: //CRIADA
				$new_status = $settings->settings['status_criada'];
				$note = 'A transação foi criada.';
				break;
			case 1: //EM ANDAMENTO
				$new_status = $settings->settings['status_em_andamento'];
				$note = 'O processo de autenticação está em andamento.';
				break;
			case 2: //AUTENTICADA
				$new_status = $settings->settings['status_autenticada'];
				$note = 'A transação foi autenticada pela Cielo.';
				break;
			case 3: //NÃO AUTENTICADA
				$new_status = $settings->settings['status_nao_autenticada'];
				$note = 'A transação não foi autenticada pela Cielo.';
				send_email($order, 'falha_autenticacao');
				break;
			case 4: //AUTORIZADA
				$new_status = $settings->settings['status_autorizada'];
				$note = 'A transação foi autorizada pela Cielo. Para receber o valor, é necessário realizar a captura.';
				break;
			case 5: //NÃO AUTORIZADA
				$new_status = $settings->settings['status_nao_autorizada'];
				$note = 'A transação não foi autorizada pela Cielo.';
				send_email($order, 'falha_autorizacao');
				break;
			case 6: //CAPTURADA
				$new_status = $settings->settings['status_capturada'];
				$note = 'A transação foi capturada com sucesso. O ciclo de pagamento está concluído!';
				break;
			case 9: //CANCELADA
				$new_status = $settings->settings['status_cancelada'];
				$note = 'A transação foi Cancelada (ou pela cielo ou através do painel de controle).';
				send_email($order, 'cancelada');
				break;
			case 10: //EM AUTENTICAÇÃO
				$new_status = $settings->settings['status_em_autenticacao'];
				$note = 'A transação está em autenticação.';
				break;
			case 12: //EM CANCELAMENTO
				$new_status = $settings->settings['status_em_cancelamento'];
				$note = 'A transação está em processo de cancelamento pela Cielo.';
				send_email($order, 'em_cancelamento');
				break;
		}
		
		
		//need to remove this action to avoid duplicated saves into the order
		remove_action('woocommerce_process_shop_order_meta', 'woocommerce_process_shop_order_meta');
		
		//if the status or the same, just add a note to the order. Otherwise update it accordingly.
		if($order->status != $new_status && $new_status != $settings->settings['status_capturada'] && !in_array($status, array(12,9,6))){
			$order->update_status($new_status, __($note, 'woothemes') );
		} else {
			$order->add_order_note( __($note, 'woothemes') );

			//if new status is the one where we capture the money from cielo, then complete de order
			if($new_status == $settings->settings['status_capturada'] && in_array($status, array(6))){
				$order->payment_complete();
				// Store MoIP Details
				update_post_meta( $order->id, '_f2m_cielodireto_tid', $PedidoCielo->tid);
				update_post_meta( $order->id, '_f2m_cielodireto_tipo_pagto', $PedidoCielo->formaPagamentoProduto);
				update_post_meta( $order->id, '_f2m_cielodireto_parcelas', $PedidoCielo->formaPagamentoParcelas);
			}
			if($new_status == $settings->settings['status_cancelada'] && in_array($status, array(12,9))){ //CANCELADA, EM CANCELAMENTO
				$order->cancel_order(__($note, 'woothemes'));
			}
		}
		
		//rollback the action
		add_action('woocommerce_process_shop_order_meta', 'woocommerce_process_shop_order_meta');					


		if ($settings->settings['debug']=='yes') $settings->log->add( $settings->id, 'Pedido = '.$order->id.' / Status = '.$new_status.' / Nota: '.$note);
	} //Fim da função update_order
	
	
	
	
	//---------------------------------------------------------------------------------------------------
	//Função: send_email
	//Descrição: Irá enviar um e-mail para o cliente sempre que houver algum problema com o pagamento.
	//---------------------------------------------------------------------------------------------------
	function send_email($order, $message_type){
		global $woocommerce;
		
		switch(strtoupper($message_type)){
			case 'FALHA_AUTENTICACAO':
				$message  = "<h1>Olá $order->billing_first_name</h1>";
				$message .= "<br>";
				$message .= "<p>Houve uma falha ao processar o pagamento do seu pedido nº $order->id. Isto significa que o pagamento não foi autorizado pela operadora do seu cartão.</p>";
				$message .= "<p>Por favor entre <a href='".get_permalink(woocommerce_get_page_id( 'view_order' ))."'>na sua conta em nosso site</a> e tente realizar o pagamento mais uma vez. Certifique-se que todos os dados do cartão são corretamente informados.</p>";
				$message .= "<br>";
				$message .= "<p>Pedimos desculpas pelo transtorno.</p>";
				$message .= "<br>";
				$message .= "<p>Atenciosamente,</p>";
				$message .= "<p>Equipe ".get_bloginfo( 'name', 'display' )."</p>";
				woocommerce_mail( $order->billing_email, "Pedido $order->id - Pagamento Falhou", $message, $headers = "Content-Type: text/html\r\n", $attachments = "" );
				break;
			case 'EM_CANCELAMENTO':
				$message  = "<h1>Olá $order->billing_first_name</h1>";
				$message .= "<br>";
				$message .= "<p>Seu pedido de nº <a href='".get_permalink(woocommerce_get_page_id( 'view_order' ))."&order=$order->id'>$order->id</a> está em processo de cancelamento pela Cielo. Ele pode ter sido cancelado por você ou por sua operadora do cartão.</p>";
				$message .= "<p>Caso queira, poderá realizar um novo pedido em nossa loja sempre que quiser.</p>";
				$message .= "<br>";
				$message .= "<p>Pedimos desculpas pelo transtorno.</p>";
				$message .= "<br>";
				$message .= "<p>Atenciosamente,</p>";
				$message .= "<p>Equipe ".get_bloginfo( 'name', 'display' )."</p>";
				woocommerce_mail( $order->billing_email, "Pedido $order->id - Pagamento Falhou", $message, $headers = "Content-Type: text/html\r\n", $attachments = "" );
				break;
			case 'CANCELADA':
				$message  = "<h1>Olá $order->billing_first_name</h1>";
				$message .= "<br>";
				$message .= "<p>Seu pedido de nº <a href='".get_permalink(woocommerce_get_page_id( 'view_order' ))."&order=$order->id'>$order->id</a> foi cancelado. Ele pode ter sido cancelado por você ou por sua operadora do cartão.</p>";
				$message .= "<p>Caso queira, poderá realizar um novo pedido em nossa loja sempre que quiser.</p>";
				$message .= "<br>";
				$message .= "<p>Pedimos desculpas pelo transtorno.</p>";
				$message .= "<br>";
				$message .= "<p>Atenciosamente,</p>";
				$message .= "<p>Equipe ".get_bloginfo( 'name', 'display' )."</p>";
				woocommerce_mail( $order->billing_email, "Pedido $order->id - Pagamento Falhou", $message, $headers = "Content-Type: text/html\r\n", $attachments = "" );
				break;
			case 'FALHA_AUTORIZACAO':
				$message  = "<h1>Olá $order->billing_first_name</h1>";
				$message .= "<br>";
				$message .= "<p>Seu pedido de nº <a href='".get_permalink(woocommerce_get_page_id( 'view_order' ))."&order=$order->id'>$order->id</a> não foi autorizado por sua operadora do cartão.</p>";
				$message .= "<p>Caso queira, poderá realizar um novo pedido em nossa loja sempre que quiser.</p>";
				$message .= "<br>";
				$message .= "<p>Pedimos desculpas pelo transtorno.</p>";
				$message .= "<br>";
				$message .= "<p>Atenciosamente,</p>";
				$message .= "<p>Equipe ".get_bloginfo( 'name', 'display' )."</p>";
				woocommerce_mail( $order->billing_email, "Pedido $order->id - Pagamento Falhou", $message, $headers = "Content-Type: text/html\r\n", $attachments = "" );
				break;
		}
	} //Fim da função send_email
	
	

//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
//=======>>> PLUGIN AUTO UPDATE CODE <<<=======
//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
	//Plugin Update API Filters
	add_filter('http_request_args', 'cielodireto_prevent_update_check', 10, 2); //Making sure wordpress does not check this plugin into their repository
	add_filter('pre_set_site_transient_update_plugins', 'cielodireto_check_for_plugin_update'); // Take over the update check
	add_filter('plugins_api', 'cielodireto_plugin_api_call', 10, 3); // Take over the Plugin info screen

	//Plugin update parameters
	$api_url     = 'http://update.wooplugins.com.br';
	$plugin_slug = basename(dirname(__FILE__));

	// TEMP: Enable update check on every request. Normally you don't need this! This is for testing only!
	// NOTE: The
	//	if (empty($checked_data->checked))
	//		return $checked_data;
	// lines will need to be commented in the check_for_plugin_update function as well.
	//get_site_transient( 'update_plugins' ); // unset the plugin
	//set_site_transient( 'update_plugins', null ); // reset plugin database information
	// TEMP: Show which variables are being requested when query plugin API
	//add_filter('plugins_api_result', 'cielodireto_result', 10, 3);
	//function cielodireto_result($res, $action, $args) {
	//	print_r($res);
	//	return $res;
	//}
	// NOTE: All variables and functions will need to be prefixed properly to allow multiple plugins to be updated
	
	
	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_prevent_update_check
	//Descrição: Evita que o wordpress tente verificar o update deste plugin no repositório deles
	//---------------------------------------------------------------------------------------------------
	function cielodireto_prevent_update_check( $r, $url ) {
		if ( 0 === strpos( $url, 'http://api.wordpress.org/plugins/update-check/' ) ) {
			$my_plugin = plugin_basename( __FILE__ );
			$plugins = unserialize( $r['body']['plugins'] );
			unset( $plugins->plugins[$my_plugin] );
			unset( $plugins->active[array_search( $my_plugin, $plugins->active )] );
			$r['body']['plugins'] = serialize( $plugins );
		}
		return $r;
	} //Fim da função cielodireto_prevent_update_check




	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_check_for_plugin_update
	//Descrição: Verificar a existência de atualizações em nossa API
	//---------------------------------------------------------------------------------------------------
	function cielodireto_check_for_plugin_update($checked_data) {
		global $api_url, $plugin_slug;

		//Comment out these two lines during testing.
		if (empty($checked_data->checked))
			return $checked_data;

		$args = array(
			'slug' => $plugin_slug,
			'version' => $checked_data->checked[$plugin_slug .'/'. $plugin_slug .'.php'],
		);
		$request_string = array(
				'body' => array(
					'action' => 'basic_check',
					'request' => serialize($args),
					'api-key' => md5(get_bloginfo('url'))
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo('url')
			);

		// Start checking for an update
		$raw_response = wp_remote_post($api_url, $request_string);

		if (!is_wp_error($raw_response) && ($raw_response['response']['code'] == 200))
			$response = unserialize($raw_response['body']);

		if (is_object($response) && !empty($response)) // Feed the update data into WP updater
			$checked_data->response[$plugin_slug .'/'. $plugin_slug .'.php'] = $response;

		return $checked_data;
	} //Fim da função cielodireto_check_for_plugin_update



	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_plugin_api_call
	//Descrição: Controla a janela de informação do plugin
	//---------------------------------------------------------------------------------------------------
	function cielodireto_plugin_api_call($def, $action, $args) {
		global $plugin_slug, $api_url;

		if ($args->slug != $plugin_slug)
			return false;

		// Get the current version
		$plugin_info = get_site_transient('update_plugins');
		$current_version = $plugin_info->checked[$plugin_slug .'/'. $plugin_slug .'.php'];
		$args->version = $current_version;

		$request_string = array(
				'body' => array(
					'action' => $action,
					'request' => serialize($args),
					'api-key' => md5(get_bloginfo('url'))
				),
				'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo('url')
			);

		$request = wp_remote_post($api_url, $request_string);

		if (is_wp_error($request)) {
			$res = new WP_Error('plugins_api_failed', __('An Unexpected HTTP Error occurred during the API request.</p> <p><a href="?" onclick="document.location.reload(); return false;">Try again</a>'), $request->get_error_message());
		} else {
			$res = unserialize($request['body']);

			if ($res === false)
				$res = new WP_Error('plugins_api_failed', __('An unknown error occurred'), $request['body']);
		}

		return $res;
	} //Fim da função cielodireto_plugin_api_call



//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
//=======>>> DEPENDENCY CHECK <<<=======
//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@

	require 'includes/pedido.php';

	//check if woocommerce is installed
	if ( !class_exists( 'WC_Payment_Gateway' ) || !class_exists( 'WC_Order_Item_Meta' ) ) {
		add_action( 'admin_notices', 'cielodireto_woocommerce_fallback_notice' );
		return;
	}

  	//Add the gateway to WooCommerce
  	add_filter( 'woocommerce_payment_gateways', 'add_gateway_cielodireto' );

  	function add_gateway_cielodireto( $methods ) {
		$methods[] = 'F2M_Cielo_Direto'; 
		return $methods;
	}

	//---------------------------------------------------------------------------------------------------
	//Função: cielodireto_woocommerce_fallback_notice
	//Descrição: Exibe um mensagem de erro no painel de controle se o woocommerce não estiver instalado
	//---------------------------------------------------------------------------------------------------
	function cielodireto_woocommerce_fallback_notice() {
		$message = '<div class="error">';
		$message .= '<p>' . __( 'WooCommerce Cielo Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'cielodireto' ) . '</p>';
		$message .= '</div>';

		echo $message;
	} //Fim da função cielodireto_woocommerce_fallback_notice





//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@
//=======>>> GATEWAY CLASS <<<=======
//@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@

	//---------------------------------------------------------------------------------------------------
	//Classe: F2M_Cielo_Direto
  	//Descrição: classe de implementação do gateway cielodireto
  	//---------------------------------------------------------------------------------------------------
	class F2M_Cielo_Direto extends WC_Payment_Gateway {


		//---------------------------------------------------------------------------------------------------
  		//Função: __construct
  		//Descrição: cria e inicializa o objeto da classe
  		//---------------------------------------------------------------------------------------------------
		public function __construct() {
      		global $woocommerce;

      		$this->id                 = 'cielodireto';
			$this->icon               = apply_filters('woocommerce_'.$this->id.'_icon', $url = plugin_dir_url(__FILE__).$this->id.'.png');
      		$this->has_fields         = false;
            $this->method_title       = __( 'Cielo Direto', 'woothemes' );
            $this->method_description = __( 'Método de pagamento diretamente pela Cielo.', 'woothemes' );
			$this->notify_url         = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'F2M_Cielo_Direto', home_url( '/' ) ) );
			
      		// Load the form fields.
      		$this->init_form_fields();

      		// Load the settings.
      		$this->init_settings();

      		// Define user set variables
      		$this->title                   = $this->get_option('title');
      		$this->description             = $this->get_option('description');
      		$this->credential              = $this->get_option('credential');
      		$this->token                   = $this->get_option('token');
      		$this->forma_pagto             = $this->get_option('forma_pagto');
      		$this->capturar_auto           = $this->get_option('capturar_auto');
      		$this->autorizar_auto          = $this->get_option('autorizar_auto');
      		$this->bandeira                = $this->get_option('bandeira');
      		$this->num_parcelas            = $this->get_option('num_parcelas');
      		$this->taxa_juros              = $this->get_option('taxa_juros');
      		$this->status_criada           = $this->get_option('status_criada');
      		$this->status_em_andamento     = $this->get_option('status_em_andamento');
      		$this->status_em_autenticacao  = $this->get_option('status_em_autenticacao');
      		$this->status_autenticada      = $this->get_option('status_autenticada');
      		$this->status_nao_autenticada  = $this->get_option('status_nao_autenticada');
      		$this->status_autorizada       = $this->get_option('status_autorizada');
      		$this->status_nao_autorizada   = $this->get_option('status_nao_autorizada');
      		$this->status_capturada        = $this->get_option('status_capturada');
      		$this->status_em_cancelamento  = $this->get_option('status_em_cancelamento');
      		$this->status_cancelada        = $this->get_option('status_cancelada');
      		$this->debug                   = $this->get_option('debug');
			
      		// Logs
      		if ($this->debug=='yes') $this->log = $woocommerce->logger();

			//Payment Gateway Actions			
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      		add_action('woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));
			add_action('woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_ipn_response' ) );
			add_action('admin_notices', array(&$this, 'cielodireto_check_missing_fields_message')); //validate configuration
			add_action('wp_loaded', array(&$this, 'cielodireto_admin_status')); //fix issue with init_form_fields for the statuses parameter

			//Check if everything is OK to enable the plugin
            $this->enabled = ('yes' == $this->settings['enabled']) && !empty($this->credential) && !empty($this->token) && $this->is_valid_for_use();
					
  		} //Fim da função __construct



  		//---------------------------------------------------------------------------------------------------
  		//Função: is_valid_for_use
  		//Descrição: checa se o gateway está habilitado a disponível para o país do usuário
  		//---------------------------------------------------------------------------------------------------
  		function is_valid_for_use() {
      		if (!in_array(get_option('woocommerce_currency'), array('BRL'))){
				if (!isset($this->credential) || $this->credential == ''){
					if (!isset($this->token) || $this->token == ''){
						return false;
					}
				}
			}
      		return true;
  		} //Fim da função is_valid_for_use



  		//---------------------------------------------------------------------------------------------------
  		//Função: init_form_fields
  		//Descrição: função do woocommerce que inicializa as variáveis a serem exibidas no painel de
  		//           configuração do woocommerce.
  		//---------------------------------------------------------------------------------------------------
		function init_form_fields() {
  			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Habilita/Desabilita', 'woothemes' ),
					'type' => 'checkbox',
					'label' => __( 'Habilita o pagamento com a Cielo', 'woothemes' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Título', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'Título que será exibido da forma de pagamento durante o checkout.', 'woothemes' ),
					'default' => __( 'Pague com Cielo', 'woothemes' ),
					'desc_tip'      => true,
				),
				'description' => array(
					'title' => __( 'Mensagem', 'woothemes' ),
					'type' => 'textarea',
					'description' => __( 'Exibe uma mensagem de texto ao selecionar o meio de pagamento (opcional).', 'woothemes' ),
					'default' => '',
					'desc_tip'      => true,
				),
				'credential' => array(
					'title' => __( 'Credencimento', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'Número de credenciamento da Loja na Cielo.', 'woothemes' ),
					'desc_tip'      => true,
				),
				'token' => array(
					'title' => __( 'Chave de Segurança', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'Chave de segurança fornecido pela Cielo após homologação.', 'woothemes' ),
					'desc_tip'      => true,
				),
				'forma_pagto' => array(
					'title' => __( 'Formas de Pagamento', 'woothemes' ),
					'type' => 'multiselect',
					'description' => __( 'Selecione as formas de pagamento que deseja trabalhar.', 'woothemes' ),
					'options' => array(
						'A' => "Débito à vista",
						'1' => "Crédito à vista",
						'2' => "Parcelado Loja",
						//'3' => "Parcelado Administradora"
					),
					'default' => array('1'),
					'desc_tip'      => true,
				),
				'capturar_auto' => array(
					'title' => __( 'Capturar Automaticamente?', 'woothemes' ),
					'type' => 'checkbox',
					'description' => __( 'Deseja capturar a transação se autorizada pela administradora?.', 'woothemes' ),
					'default' => 'no',
					'desc_tip'      => true,
				),
				'autorizar_auto' => array(
					'title' => __( 'Autorização Automática', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione como você deseja autorizar a transação.', 'woothemes' ),
					'options' => array(
						'0' => "Somente autenticar a transação",
						'1' => "Autorizar transação somente se autenticada",
						'2' => "Autorizar transação autenticada e não-autenticada",
						'3' => "Autorizar Direto",
					),
					'default' => array('1'),
					'desc_tip'      => true,
				),
				'bandeira' => array(
					'title' => __( 'Bandeiras disponíveis', 'woothemes' ),
					'type' => 'multiselect',
					'description' => __( 'Selecione as bandeiras que deseja trabalhar.', 'woothemes' ),
					'options' => array(
						'visa' => "VISA",
						'mastercard' => "MasterCard",
						'diners' => "Diners",
						'discover' => "Discover",
						'elo' => "Elo",
						'amex' => "American Express",
					),
					'default' => array('visa'),
					'desc_tip'      => true,
				),
				'num_parcelas' => array(
					'title' => __( 'Forma de Pagamento', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Seleciona o número máximo de parcelas.', 'woothemes' ),
					'options' => array(
						'1' => "Sem parcelamento",
						'2' => "2x",
						'3' => "3x",
						'4' => "4x",
						'5' => "5x",
						'6' => "6x",
						'7' => "7x",
						'8' => "8x",
						'9' => "9x",
						'10' => "10x",
						'11' => "11x",
						'12' => "12x",
						'13' => "13x",
						'14' => "14x",
						'15' => "15x",
						'16' => "16x",
						'17' => "17x",
						'18' => "18x",
						'19' => "19x",
						'20' => "20x",
						'21' => "21x",
						'22' => "22x",
						'23' => "23x",
						'24' => "24x",
					),
					'default' => array('1'),
					'desc_tip'      => true,
				),
				'taxa_juros' => array(
					'title' => __( 'Taxa de juros de parcelamento', 'woothemes' ),
					'type' => 'text',
					'description' => __( 'Informe o valor da taxa de juros ao mês a ser cobrado nos parcelamentos (0 = sem juros).', 'woothemes' ),
					'default' => '0',
					'desc_tip'      => true,
				),
				'status_criada' => array(
					'title' => __( 'Status Criada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'pending',
					'desc_tip'      => true,
				),
				'status_em_andamento' => array(
					'title' => __( 'Status Em Andamento', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'pending',
					'desc_tip'      => true,
				),
				'status_em_autenticacao' => array(
					'title' => __( 'Status Em Autenticação', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'pending',
					'desc_tip'      => true,
				),
				'status_autenticada' => array(
					'title' => __( 'Status Autenticada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'on-hold',
					'desc_tip'      => true,
				),
				'status_nao_autenticada' => array(
					'title' => __( 'Status Não Autenticada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'failed',
					'desc_tip'      => true,
				),
				'status_autorizada' => array(
					'title' => __( 'Status Autorizada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'on-hold',
					'desc_tip'      => true,
				),
				'status_nao_autorizada' => array(
					'title' => __( 'Status Não Autorizada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'failed',
					'desc_tip'      => true,
				),
				'status_capturada' => array(
					'title' => __( 'Status Capturada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'processing',
					'desc_tip'      => true,
				),
				'status_em_cancelamento' => array(
					'title' => __( 'Status Em Cancelamento', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'canceled',
					'desc_tip'      => true,
				),
				'status_cancelada' => array(
					'title' => __( 'Status Cancelada', 'woothemes' ),
					'type' => 'select',
					'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
					'options' => $this->statuses,
					'default' => 'canceled',
					'desc_tip'      => true,
				),
				'debug' => array(
					'title' => __( 'Debug', 'woothemes' ),
					'type' => 'checkbox',
					'label' => __( 'Habilita a escrita de log (<code>woocommerce/logs/'.$this->id.'.txt</code>)', 'woothemes' ),
					'default' => 'no',
					'desc_tip'      => true,
				)
			);
		} //Fim da função init_form_fields



  		//---------------------------------------------------------------------------------------------------
  		//Função: admin_options
  		//Descrição: gera o formulário a ser exibido no painel deconfiguração
  		//---------------------------------------------------------------------------------------------------
		public function admin_options() {
			$this->statuses = (array) get_terms('shop_order_status', array('hide_empty' => 0, 'orderby' => 'id'));
			$status_arr = array();
			foreach($this->statuses as $status) {
				$status_arr[$status->slug] = $status->name;
			}

			$this->form_fields['status_criada'] = array(
										'title' => __( 'Status Criada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'pending',
										'desc_tip'      => true,
									);
			$this->form_fields['status_em_andamento'] = array(
										'title' => __( 'Status Em Andamento', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'pending',
										'desc_tip'      => true,
									);
			$this->form_fields['status_em_autenticacao'] = array(
										'title' => __( 'Status Em Autenticação', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'pending',
										'desc_tip'      => true,
									);
			$this->form_fields['status_autenticada'] = array(
										'title' => __( 'Status Autenticada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'on-hold',
										'desc_tip'      => true,
									);
			$this->form_fields['status_nao_autenticada'] = array(
										'title' => __( 'Status Não Autenticada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'failed',
										'desc_tip'      => true,
									);
			$this->form_fields['status_autorizada'] = array(
										'title' => __( 'Status Autorizada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'on-hold',
										'desc_tip'      => true,
									);
			$this->form_fields['status_nao_autorizada'] = array(
										'title' => __( 'Status Não Autorizada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'failed',
										'desc_tip'      => true,
									);
			$this->form_fields['status_capturada'] = array(
										'title' => __( 'Status Capturada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'processing',
										'desc_tip'      => true,
									);
			$this->form_fields['status_em_cancelamento'] = array(
										'title' => __( 'Status Em Cancelamento', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'canceled',
										'desc_tip'      => true,
									);
			$this->form_fields['status_cancelada'] = array(
										'title' => __( 'Status Cancelada', 'woothemes' ),
										'type' => 'select',
										'description' => __( 'Selecione o status  que o pedido deverá ter para o status da Cielo', 'woothemes' ),
										'options' => $status_arr,
										'default' => 'canceled',
										'desc_tip'      => true,
									);

			?>
	      		<h3><?php _e($this->method_title, 'woothemes'); ?></h3>
	      		<p><?php _e('Opção para pagamento através da Cielo', 'woothemes'); ?></p>
	      		<table class="form-table">
	      		<?php
	        		// Generate the HTML For the settings form.
	        		$this->generate_settings_html();
	      		?>
	      		</table><!--/.form-table-->
      		<?php
		} //Fim da função admin_options



    	//---------------------------------------------------------------------------------------------------
  		//Função: payment_fields
  		//Descrição: Exibe a Mensagem ao selecionar a forma de pagamento se ela estiver definida
  		//---------------------------------------------------------------------------------------------------
  		function payment_fields() {
      		if ($this->description)
      			echo wpautop(wptexturize($this->description));
    	} //Fim da função payment_fields



    	//---------------------------------------------------------------------------------------------------
  		//Função: process_payment
  		//Descrição: processa o pagamento e retorna o resultado
  		//---------------------------------------------------------------------------------------------------
		function process_payment( $order_id ) {
      		$order = &new WC_Order( $order_id );

      		return array(
        		'result'    => 'success',
        		'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay' ))))
      		);
    	} //Fim da função process_payment



    	//---------------------------------------------------------------------------------------------------
  		//Função: receipt_page
  		//Descrição: Página final antes de redirecionar para a página de pagamento do cielodireto
  		//---------------------------------------------------------------------------------------------------
    	function receipt_page( $order ) {
    		echo '<p>'.__('Obrigado pelo seu pedido. Por favor clique no botão "Pagar com Cielo" para finalizar o pagamento.', 'woothemes').'</p>';
    		echo $this->generate_cielodireto_form( $order );
    	} //Fim da função receipt_page
		


    	//---------------------------------------------------------------------------------------------------
  		//Função: generate_cielodireto_form
  		//Descrição: gera o formulário de pagamento e envia os dados para a Cielo
  		//---------------------------------------------------------------------------------------------------
    	function generate_cielodireto_form($order_id){
      		global $woocommerce;
      		$order = &new WC_Order( $order_id );
			
			if(isset($_POST['is_postback']) && $_POST['is_postback'] == true){
				
				//save return url into user session for later use
				$woocommerce->session->return_url = $this->get_return_url($order);
				
				
				//create the cielodireto request object
				$Pedido = new Pedido(plugin_dir_path(__FILE__).$this->id.'.log');
				$Pedido->capturar = ($this->capturar_auto == 'yes' ? 'true' : 'false');
				$Pedido->autorizar = $this->autorizar_auto;
				$Pedido->dadosEcNumero = $this->credential;
				$Pedido->dadosEcChave = $this->token;
				$Pedido->urlRetorno = urlencode(htmlspecialchars($this->notify_url));

				//get data from postback
				if($_POST["formaPagamento"] != "A" && $_POST["formaPagamento"] != "1" && in_array("2", $this->forma_pagto)){
					$Pedido->formaPagamentoProduto = 2;
					$Pedido->formaPagamentoParcelas = $_POST["formaPagamento"];
				} else {
					$Pedido->formaPagamentoProduto = $_POST["formaPagamento"];
					$Pedido->formaPagamentoParcelas = 1;
				}

				$Pedido->formaPagamentoBandeira = $_POST["codigoBandeira"];

				$Pedido->dadosPedidoNumero = $order->id;
				$Pedido->dadosPedidoValor = number_format($order->get_total(),2,'','');

				//SUBMIT REQUEST TO CIELO
				$objResposta = $Pedido->RequisicaoTransacao(false);

				//if the order already exists and this is a re-pay try from the user, update the TID number for future checking
				$old_tid = get_post_meta($order_id, 'cielodireto_id', true);
				$new_tid = (string)$Pedido->tid;
				if(strcasecmp($old_tid,$new_tid) == 0 && !empty($old_tid)){
					$order->add_order_note( __('O usuário realizou uma outra tentativa de pagamento. o TID foi alterado de '.$old_tid.' para '.$new_tid.'.', 'woothemes') );
					update_post_meta($order_id, 'cielodireto_tid' , $new_tid);
				}
				
				//UPDATE TID AND ORDER STATUS
				$Pedido->tid = $objResposta->tid;
				$Pedido->pan = $objResposta->pan;
				$Pedido->status = $objResposta->status;

				//GET AUTHENTICATION URL SO THE USER CAN ENTER CREDIT CARD INFORMATION
				$urlAutenticacao = "url-autenticacao";
				$Pedido->urlAutenticacao = $objResposta->$urlAutenticacao;

				//STORE THE REQUEST INTO USER SESSION
				$StrPedido = $Pedido->ToString();
				$woocommerce->session->pedido = $StrPedido;
				//$_SESSION["pedido"] = $StrPedido;

				//JAVASCRIPT CODE TO REDIRECT USER TO CIELO URL
				$woocommerce->add_inline_js('
					jQuery("body").block({
						message: "<img src=\"'.esc_url( $woocommerce->plugin_url() ).'/assets/images/ajax-loader.gif\" alt=\"Redirecting...\" style=\"float:left; margin-right: 10px;\" />'.__('Obrigado pela compra. Estamos transferindo para o cielodireto para realizar o pagamento.', 'woothemes').'",
							overlayCSS: {
								background: "#fff",
								opacity: 0.6
							},
							css: {
								padding:        20,
								textAlign:      "center",
								color:          "#555",
								border:         "3px solid #aaa",
								backgroundColor:"#fff",
								cursor:         "wait",
								lineHeight:    "32px"
							}
						});
					jQuery("#submit_cielodireto_payment_form").click();
				');

				//FORM TO ACTION TO REDIRECT USER TO CIELO
				$payment_form = '<form action="'.esc_url( $Pedido->urlAutenticacao ).'" method="post" id="paypal_payment_form">
									<input type="submit" class="button" id="submit_cielodireto_payment_form" value="'.__('Pague com Cielo', 'woothemes').'" />
									<a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido', 'woothemes').'</a>
								</form>';

				if ($this->debug=='yes') $this->log->add( $this->id, "Pedido gerado com sucesso. Abaixo código HTML do formulário:");
				if ($this->debug=='yes') $this->log->add( $this->id, $payment_form);
			} else {


				$formaPagto .= !in_array("A", $this->forma_pagto) ? '' : '<input type="radio" '.($formaPagto =='' ? 'checked' : '').' name="formaPagamento" value="A">Débito - R$ '. number_format($order->get_total(),2,',','.').'<br>';
				$formaPagto .= !in_array("1", $this->forma_pagto) ? '' : '<input type="radio" '.($formaPagto =='' ? 'checked' : '').' name="formaPagamento" value="1">Crédito à Vista - R$ '. number_format($order->get_total(),2,',','.').'<br>';

				if((in_array("2", $this->forma_pagto) || in_array("3", $this->forma_pagto) && $this->num_parcelas > 1)){
					$max_parcelas = (int)$this->num_parcelas;

					while ($order->get_total() / $max_parcelas < 5){
						$max_parcelas--;
					}
					$formaPagto .= "<br>";
					if($this->taxa_juros > 0){
						for($i = 2; $i <= $max_parcelas; $i++){
							$I = $this->taxa_juros / 100.00;
							$valor_parcela = $order->get_total() * $I * pow((1 + $I), $i) / (pow((1 + $I), $i) - 1);
							$formaPagto .= '<input type="radio" '.($formaPagto =='' ? 'checked' : '').' name="formaPagamento" value="'.$i.'">'.$i.'x R$ '. number_format($valor_parcela,2,',','.').' (c/ juros) - R$ '.number_format($i*$valor_parcela,2,',','.').'<br>';
						}
					} else {
						for($i = 2; $i <= $max_parcelas; $i++){
							$formaPagto .= '<input type="radio" '.($formaPagto =='' ? 'checked' : '').' name="formaPagamento" value="'.$i.'">'.$i.'x R$ '. number_format($order->get_total()/$max_parcelas,2,',','.').' (s/ juros)<br>';
						}
					}
				}


				$img_src = plugin_dir_url(__FILE__).'flags/';
				$img_style = "style='margin: auto;padding: 0;border: 0px;'";

				$bandeira .= in_array('visa',       $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="visa"/><br>Visa<img '.$img_style.' src="'.$img_src.'visa.png" /></td>' : '';
				$bandeira .= in_array('mastercard', $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="mastercard"><br>MasterCard<img '.$img_style.' src="'.$img_src.'mastercard.png" /></td>' : '';
				$bandeira .= in_array('diners',     $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="diners"><br>Diners<img '.$img_style.' src="'.$img_src.'diners.png" /></td>' : '';
				$bandeira .= in_array('discover',   $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="discover"><br>Discover<img '.$img_style.' src="'.$img_src.'discover.png" /></td>' : '';
				$bandeira .= in_array('elo',        $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="elo"><br>Elo<img '.$img_style.' src="'.$img_src.'elo.png" /></td>' : '';
				$bandeira .= in_array('amex',       $this->bandeira) ? '<td style="vertical-align:middle;text-align:center;"><input type="radio" '.($bandeira =='' ? 'checked' : '').' name="codigoBandeira" value="amex"><br>Amex<img '.$img_style.' src="'.$img_src.'amex.png" /></td>' : '';
				$bandeira  = '<table style="margin-bottom:0 !important;"><tr>'.$bandeira.'</tr></table>';

				$payment_form = '<form action="" method="post" id="payment_form">
									<table>
										<tr>
											<td>Forma de pagamento</td>
											<td>'.$bandeira.'</td>
										</tr><tr>
											<td>Parcelamento</td>
											<td>'.$formaPagto.'</td>
										</tr>
									</table>
									<input type="hidden" id="is_postback" name="is_postback" value="true" />
									<input type="submit" class="button" id="submit_payment_form" value="'.__('Pague com Cielo', 'woothemes').'" />
									<a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido', 'woothemes').'</a>
								</form>';
			}
			return $payment_form;
    	} //Fim da função generate_cielodireto_form

		
    	//---------------------------------------------------------------------------------------------------
  		//Função: check_ipn_response
  		//Descrição: Verifica se o retorno do cielodireto é válido, se for, atualiza o pedido com o novo status.
		//           Atualiza o pedido com a notificação enviada pelo cielodireto. Se a notificação for de
  		//           transação concluída, finaliza o pedido (status = completo para downloads e processing
  		//           para produtos físicos (o produto já pode ser enviado pela transportadora)
  		//---------------------------------------------------------------------------------------------------
		function check_ipn_response(){
      		global $woocommerce;

			//recreate order object
			$Pedido = new Pedido(plugin_dir_path(__FILE__).$this->id.'.log');
			$Pedido->FromString($woocommerce->session->pedido);
			$Pedido->dadosEcNumero = $this->credential;
			$Pedido->dadosEcChave = $this->token;

			// Consulta situação da transação
			$objResposta = $Pedido->RequisicaoConsulta();

			//update order status
			$Pedido->status = $objResposta->status;

			$tid = (string)$objResposta->tid;
			$order_id = (int)$Pedido->dadosPedidoNumero;

			//update order with tid
			update_post_meta($order_id, 'cielodireto_tid' , "");
			update_post_meta($order_id, 'cielodireto_tid' , $tid);
			update_post_meta($order_id, 'cielodireto_status' , (int)$Pedido->status);


      		//if ($this->debug=='yes') $this->log->add( $this->id, 'Pedido = '.$order_id.' / Status = '.$Pedido->status);			
      		
			if (!empty($order_id) && !empty($tid)) {

        		$order = new WC_Order($order_id);

        		// We are here so lets check status and do actions
				update_order($order, (int)$Pedido->status, $Pedido);
				
				//remove order from session
				unset($woocommerce->session->pedido);
      		}
			
			wp_redirect($woocommerce->session->return_url);		
		} //Fim da função check_ipn_response


		
		
		//---------------------------------------------------------------------------------------------------
		//Função: cielodireto_check_missing_fields_message
		//Descrição: Exibe um mensagem de erro no painel de controle se as configurações mandatórias para a
		//           execução do plugin não estiverem corretamente configuradas.
		//---------------------------------------------------------------------------------------------------
		function cielodireto_check_missing_fields_message(){
			global $woocommerce;
			
			$message = '';
			if(empty($this->credential)) $message .= '<p> - Obrigatório informar a Credencial da Cielo.</p>';
			if(empty($this->token))      $message .= '<p> - Obrigatório informar o Token da Cielo.</p>';

			if(!empty($message)){
				$message = '<div class="error">' .
						   '<strong>Gateway Desabilitado!</strong> Verifique os erros abaixo:' .
						   $message .
						   sprintf( __( 'Clique %saqui%s para configurar!' , 'woothemes' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways#gateway-cielodireto">', '</a>' ) .
						   '</div>';
			}
			echo $message;	
		} //Fim da função cielodireto_check_missing_fields_message	
		
		//---------------------------------------------------------------------------------------------------
		//Função: cielodireto_admin_status
		//Descrição: Esta função é um fix para que os status dos pedidos sejam corretamente populados nos 
		//           campos necessários dentro da página de configuração do plugin
		//---------------------------------------------------------------------------------------------------
		function cielodireto_admin_status(){
			//get all the registered statuses to be used in the admin panel
			$statuses = (array) get_terms('shop_order_status', array('hide_empty' => 0, 'orderby' => 'id'));

			//process the array into a readable format for init_form_fields() function
			$status_arr = array();
			foreach($statuses as $status) {
				$status_arr[$status->slug] = $status->name;
			}
			
			//store into statuses class field
			$this->statuses = $status_arr;
			
			// Load the form fields.
			$this->init_form_fields();
		} //Fim da função cielodireto_admin_status

	} //Fim da classe woocommerce_cielodireto
}
?>