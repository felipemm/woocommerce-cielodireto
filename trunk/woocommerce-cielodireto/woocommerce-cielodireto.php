<?php
/*
Plugin Name: WooCommerce Cielo Direto
Plugin URI: http://felipematos.com/loja
Description: Adiciona o gateway de pagamento para a Cielo no WooCommerce
Version: 0.1
Author: Felipe Matos <chucky_ath@yahoo.com.br>
Author URI: http://felipematos.com
License: Commercial
Requires at least: 3.4
Tested up to: 3.4.1
*/



add_action('woocommerce_order_actions', 'cielodireto_capture_credit', 0);


function cielodireto_capture_credit($order_id){
	global $woocommerce;
	
	$order = &new woocommerce_order( $order_id );
	
	$tid = get_post_meta($order_id, 'cielodireto_tid', true);
	//$tid = get_post_meta($order_id, 'cielodireto_tid', true);

	//var_dump(get_object_vars($woocommerce->payment_gateways));
	
	$json = json_encode($order);
	
	echo "<li><table>";
	echo 	"<tr><h3>Ações Cielo</h3>";
	echo 		"<td><input type='button' style='width:120px;' class='button tips' onclick='executeAction(this.name,".$json.", \"$tid\", \"".plugin_dir_url(__FILE__)."order/\")' name='capture' value='Capturar' data-tip='Realiza a captura do pagamento feito na Cielo.' /> </td>";
	echo 		"<td><input type='button' style='width:120px;' class='button tips' onclick='executeAction(this.name,$order_id)' name='authorize' value='Autorizar' data-tip='Realiza a captura do pagamento feito na Cielo.' /> </td>";
	echo 	"</tr>";	
	echo 	"<tr>";
	echo 		"<td><input type='button' style='width:120px;' class='button tips' onclick='executeAction(this.name,$order_id)' name='cancel' value='Cancelar' data-tip='Realiza a captura do pagamento feito na Cielo.' /> </td>";
	echo 		"<td><input type='button' style='width:120px;' class='button tips' onclick='executeAction(this.name,$order_id)' name='check' value='Consultar' data-tip='Realiza a captura do pagamento feito na Cielo.' /> </td>";
	echo 	"</tr>";	
	echo "</table></li>";
	echo "<script type='text/javascript' src='".plugin_dir_url(__FILE__)."order/main.js'></script>";
}



require 'includes/pedido.php';



//hook to include the payment gateway function
add_action('plugins_loaded', 'gateway_cielo_direto', 0);


//hook function
function gateway_cielo_direto(){

	
	//---------------------------------------------------------------------------------------------------
	//Classe: woocommerce_cielodireto
  	//Descrição: classe de implementação do gateway cielodireto
  	//---------------------------------------------------------------------------------------------------
  	class woocommerce_cielodireto extends WC_Payment_Gateway {

  		
		//---------------------------------------------------------------------------------------------------
  		//Função: __construct
  		//Descrição: cria e inicializa o objeto da classe
  		//---------------------------------------------------------------------------------------------------
  		public function __construct() {
      		global $woocommerce;

      		$this->id           = 'cielodireto';
            $this->method_title = __( 'Cielo Direto', 'woothemes' );
			$this->icon         = apply_filters('woocommerce_'.$this->id.'_icon', $url = plugin_dir_url(__FILE__).$this->id.'.png');
      		$this->has_fields   = false;

      		// Load the form fields.
      		$this->init_form_fields();

      		// Load the settings.
      		$this->init_settings();

      		// Define user set variables
      		$this->title          = $this->settings['title'];
      		$this->description    = $this->settings['description'];
      		$this->credential     = $this->settings['credential'];
      		$this->token          = $this->settings['token'];
      		$this->forma_pagto    = $this->settings['forma_pagto'];
      		$this->capturar_auto  = $this->settings['capturar_auto'];
      		$this->autorizar_auto = $this->settings['autorizar_auto'];
      		$this->bandeira       = $this->settings['bandeira'];
      		$this->num_parcelas   = $this->settings['num_parcelas'];
      		$this->debug          = $this->settings['debug'];

			
			$keys = fopen(plugin_dir_path(__FILE__).'order/keys.php', 'w');
			fwrite($keys, "<?php \n");
			fwrite($keys, "define('LOJA','".$this->credential."');\n");
			fwrite($keys, "define('LOJA_CHAVE','".$this->token."');\n");
			fwrite($keys, "?>");
			fclose($keys);
			
      		// Logs
      		if ($this->debug=='yes') $this->log = $woocommerce->logger();

      		// Actions
      		add_action('init', array(&$this, 'check_ipn_response') );
      		add_action('valid-'.$this->id.'-standard-ipn-request', array(&$this, 'successful_request') );
      		add_action('woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));
      		add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));

      		if ( !$this->is_valid_for_use() ) $this->enabled = false;
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
						'default' => __( 'Pague com Cielo', 'woothemes' )
					),
					'description' => array(
						'title' => __( 'Mensagem', 'woothemes' ),
						'type' => 'textarea',
						'description' => __( 'Exibe uma mensagem de texto ao selecionar o meio de pagamento (opcional).', 'woothemes' ),
						'default' => ''
					),
					'credential' => array(
						'title' => __( 'Credencimento', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'Número de credenciamento da Loja na Cielo.', 'woothemes' )
					),
					'token' => array(
						'title' => __( 'Chave de Segurança', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'Chave de segurança fornecido pela Cielo após homologação.', 'woothemes' )
					),
					'forma_pagto' => array(
						'title' => __( 'Formas de Pagamento', 'woothemes' ),
						'type' => 'multiselect',
						'description' => __( 'Selecione as formas de pagamento que deseja trabalhar.', 'woothemes' ),
						'options' => array(
							'A' => "Débito à vista",
							'1' => "Crédito à vista",
							'2' => "Parcelado Loja",
							'3' => "Parcelado Administradora"
						),
						'default' => '1'
					),
					'capturar_auto' => array(
						'title' => __( 'Capturar Automaticamente?', 'woothemes' ),
						'type' => 'checkbox',
						'description' => __( 'Deseja capturar a transação se autorizada pela administradora?.', 'woothemes' ),
						'default' => 'no'
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
						'default' => '1'
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
						'default' => 'visa'						
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
						'default' => '1'
					),
					'debug' => array(
						'title' => __( 'Debug', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __( 'Habilita a escrita de log (<code>woocommerce/logs/'.$this->id.'.txt</code>)', 'woothemes' ),
						'default' => 'no'
					)
			);
  		} //Fim da função init_form_fields



  		//---------------------------------------------------------------------------------------------------
  		//Função: admin_options
  		//Descrição: gera o formulário a ser exibido no painel deconfiguração
  		//---------------------------------------------------------------------------------------------------
  		public function admin_options() {
  			?>
	      		<h3><?php _e($this->id, 'woothemes'); ?></h3>
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
  		//Função: generate_cielodireto_form
  		//Descrição: gera o formulário de pagamento e envia os dados para a Cielo
  		//---------------------------------------------------------------------------------------------------
    	function generate_cielodireto_form($order_id){
      		global $woocommerce;
      		$order = &new woocommerce_order( $order_id );
			
			if(isset($_POST['is_postback']) && $_POST['is_postback'] == true){
				
				$Pedido = new Pedido(plugin_dir_path(__FILE__).$this->id.'.log');
	
				// Lê dados do $_POST
				$Pedido->formaPagamentoBandeira = $_POST["codigoBandeira"]; 
				if($_POST["formaPagamento"] != "A" && $_POST["formaPagamento"] != "1"){
					$Pedido->formaPagamentoProduto = $_POST["tipoParcelamento"];
					$Pedido->formaPagamentoParcelas = $_POST["formaPagamento"];
				} else {
					$Pedido->formaPagamentoProduto = $_POST["formaPagamento"];
					$Pedido->formaPagamentoParcelas = 1;
				}
				
				$Pedido->dadosEcNumero = $this->credential;
				$Pedido->dadosEcChave = $this->token;
				
				$Pedido->capturar = $_POST["capturarAutomaticamente"];	
				$Pedido->autorizar = $_POST["indicadorAutorizacao"];
				
				$Pedido->dadosPedidoNumero = $order->id;
				$Pedido->dadosPedidoValor = number_format($order->get_total(),2,'','');
				
				//$Pedido->urlRetorno = $this->get_return_url($order);
				//$Pedido->urlRetorno = urlencode(htmlspecialchars($this->get_return_url($order)));
				$Pedido->urlRetorno = urlencode(htmlspecialchars($this->get_return_url($order)));
				//$Pedido->urlRetorno = 'http://localhost/wordpress';
				
				// ENVIA REQUISIÇÃO SITE CIELO
				$objResposta = $Pedido->RequisicaoTransacao(false);
				
				$Pedido->tid = $objResposta->tid;
				$Pedido->pan = $objResposta->pan;
				$Pedido->status = $objResposta->status;
				
				$urlAutenticacao = "url-autenticacao";
				$Pedido->urlAutenticacao = $objResposta->$urlAutenticacao;

				// Serializa Pedido e guarda na SESSION
				$StrPedido = $Pedido->ToString();
				$_SESSION["pedido"] = $StrPedido;


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



				$payment_form = '<form action="'.esc_url( $Pedido->urlAutenticacao ).'" method="post" id="paypal_payment_form">
									<input type="submit" class="button" id="submit_cielodireto_payment_form" value="'.__('Pague com Cielo', 'woothemes').'" />
									<a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancelar pedido', 'woothemes').'</a>
								</form>';

				if ($this->debug=='yes') $this->log->add( 'cielodireto', "Pedido gerado com sucesso. Abaixo código HTML do formulário:");
				if ($this->debug=='yes') $this->log->add( 'cielodireto', $payment_form);
			} else {


		

				$payment_form = '<form action="" method="post" id="payment_form">
									<table>
										<tr>
											<td>
												Forma de pagamento
											</td>
											<td>
												<select name="codigoBandeira">
													<option value="visa">Visa</option>
													<option value="mastercard">Mastercard</option>
													<option value="elo">Elo</option>
												</select>
											</td>
										</tr>
										<tr>
											<td>
												Parcelamento
											</td>
											<td>
												<input type="radio" name="formaPagamento" value="A">Débito - R$ '. number_format($order->get_total(),2,',','.').'
												<br><input type="radio" name="formaPagamento" value="1" checked>Crédito à Vista - R$ '. number_format($order->get_total(),2,',','.').'
												<br><input type="radio" name="formaPagamento" value="2">2x R$ '. number_format($order->get_total()/2,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="3">3x R$ '. number_format($order->get_total()/3,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="4">4x R$ '. number_format($order->get_total()/4,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="5">5x R$ '. number_format($order->get_total()/5,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="6">6x R$ '. number_format($order->get_total()/6,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="7">7x R$ '. number_format($order->get_total()/7,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="8">8x R$ '. number_format($order->get_total()/8,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="9">9x R$ '. number_format($order->get_total()/9,2,',','.').'
												<br><input type="radio" name="formaPagamento" value="10">10x R$ '. number_format($order->get_total()/10,2,',','.').'
											</td>
										</tr>
										<tr>
											<td>
												Parcelamento
											</td>
											<td>
												<select name="tipoParcelamento">
													<option value="2">Loja</option>
													<option value="3">Administradora</option>
												</select>
											</td>
										</tr>
										<tr>
											<td>Capturar Automaticamente?</td>
											<td>
												<select name="capturarAutomaticamente">
													<option value="true">Sim</option>
													<option value="false" selected="selected">Não</option>
												</select>
											</td>
										</tr>
										<tr>
											<td>Autorização Automática</td>
											<td>
												<select name="indicadorAutorizacao">
													<option value="3">Autorizar Direto</option>
													<option value="2">Autorizar transação autenticada e não-autenticada</option>
													<option value="0">Somente autenticar a transação</option>
													<option value="1">Autorizar transação somente se autenticada</option>
												</select>
											</td>									
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
  		//Função: process_payment
  		//Descrição: processa o pagamento e retorna o resultado
  		//---------------------------------------------------------------------------------------------------
    	function process_payment( $order_id ) {

      		$order = &new woocommerce_order( $order_id );

      		return array(
        		'result'    => 'success',
        		'redirect'  => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
      		);
    	} //Fim da função process_payment



    	//---------------------------------------------------------------------------------------------------
  		//Função: receipt_page
  		//Descrição: Página final antes de redirecionar para a página de pagamento do cielodireto
  		//---------------------------------------------------------------------------------------------------
    	function receipt_page( $order ) {
    		echo '<p>'.__('Obrigado pelo seu pedido. Por favor clique no botão "Pagar com Cielo" para finalizar o pagamento.', 'woothemes').'</p>';
    		echo $this->generate_cielodireto_form( $order );
    	}



    	//---------------------------------------------------------------------------------------------------
  		//Função: check_ipn_response
  		//Descrição: Verifica se o retorno do cielodireto é válido, se for, atualiza o pedido com o novo status
  		//---------------------------------------------------------------------------------------------------
    	function check_ipn_response() {
      		global $woocommerce;

      		$code = (isset($_POST['notificationCode']) && trim($_POST['notificationCode']) !== ""  ? trim($_POST['notificationCode']) : null);
      		$type = (isset($_POST['notificationType']) && trim($_POST['notificationType']) !== ""  ? trim($_POST['notificationType']) : null);

			//if ($this->debug=='yes') $this->log->add( 'cielodireto', "Verificando tipo de retorno do cielodireto...");

      		if ( $code && $type ) {

				if ($this->debug=='yes') $this->log->add( 'cielodireto', "Retorno possui POST. Validando...");

      			$notificationType = new cielodiretoNotificationType($type);
      			$strType = $notificationType->getTypeFromValue();

      			switch(strtoupper($strType)) {

      				case 'TRANSACTION':
						if ($this->debug=='yes') $this->log->add( 'cielodireto', "POST to tipo TRANSACTION detectado. Processando...");
          				$credentials = new cielodiretoAccountCredentials($this->email, $this->tokenid);

				    	try {
				    		$transaction = cielodiretoNotificationService::checkTransaction($credentials, $code);
				    	} catch (cielodiretoServiceException $e) {
				    		if ($this->debug=='yes') $this->log->add( 'cielodireto', "Erro: ". $e->getMessage());
							//die($e->getMessage());
				    	}

				    	do_action("valid-cielodireto-standard-ipn-request", $transaction);

				    	break;

      				default:
      					//Logcielodireto::error("Unknown notification type [".$notificationType->getValue()."]");
						if ($this->debug=='yes') $this->log->add( 'cielodireto', "Unknown notification type [".$notificationType->getValue()."]");

      			}

      			//self::printLog($strType);

      		} else {

				//if ($this->debug=='yes') $this->log->add( 'cielodireto', "Retorno não possui POST, é somente o retorno da página de pagamento.");
      			//Logcielodireto::error("Invalid notification parameters.");
      			//self::printLog();

      		}
    	} //Fim da função check_ipn_response



    	//---------------------------------------------------------------------------------------------------
  		//Função: successful_request
  		//Descrição: Atualiza o pedido com a notificação enviada pelo cielodireto. Se a notificação for de
  		//           transação concluída, finaliza o pedido (status = completo para downloads e processing
  		//           para produtos físicos (o produto já pode ser enviado pela transportadora)
  		//---------------------------------------------------------------------------------------------------
    	function successful_request( $transaction ) {

    		$reference = $transaction->getReference();
    		$transactionID = $transaction->getCode();
    		$status = $transaction->getStatus();
    		$sender = $transaction->getSender();
    		$paymentMethod = $transaction->getPaymentMethod();
    		$code = $paymentMethod->getCode();


      		if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido = '.$reference.' / Status = '.$status->getTypeFromValue());

      		if (!empty($reference) && !empty($transactionID)) {

        		$order = new woocommerce_order( (int) $reference );

        		//Check order not already completed
        		if ($order->status == 'completed') {
          			if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$reference.' já se encontra completado no sistema!');
          			exit;
        		}


        		// We are here so lets check status and do actions
        		switch ($status->getValue()){

					case 1: //WAITING_PAYMENT
          				$order->add_order_note( __('O comprador iniciou a transação, mas até o momento o cielodireto não recebeu nenhuma informação sobre o pagamento.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': O comprador iniciou a transação, mas até o momento o cielodireto não recebeu nenhuma informação sobre o pagamento.');
          				break;


          			case 2: //IN_ANALYSIS
          				$order->update_status('on-hold', __('O comprador optou por pagar com um cartão de crédito e o cielodireto está analisando o risco da transação.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': O comprador optou por pagar com um cartão de crédito e o cielodireto está analisando o risco da transação.');
          				break;


          			case 3: //PAID
          				$order->add_order_note( __('A transação foi paga pelo comprador e o cielodireto já recebeu uma confirmação da instituição financeira responsável pelo processamento.', 'woothemes') );
          				$order->payment_complete();

          				update_post_meta($order->id, 'Nome do cliente' , $sender->getName());
          				update_post_meta($order->id, 'E-Mail cielodireto', $sender->getEmail());
          				update_post_meta($order->id, 'Código Transação', $transacao->getCode());
          				update_post_meta($order->id, 'Método Pagamento', $code->getTypeFromValue());
          				update_post_meta($order->id, 'Data Transação'  , $transacao->getLastEventDate());
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': A transação foi paga pelo comprador e o cielodireto já recebeu uma confirmação da instituição financeira responsável pelo processamento.');

          				break;


          			case 4: //AVAILABLE
            			$order->add_order_note( __('A transação foi paga e chegou ao final de seu prazo de liberação sem ter sido retornada e sem que haja nenhuma disputa aberta.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': A transação foi paga e chegou ao final de seu prazo de liberação sem ter sido retornada e sem que haja nenhuma disputa aberta.');
          				break;


          			case 5: //IN_DISPUTE
            			$order->add_order_note( __('O comprador, dentro do prazo de liberação da transação, abriu uma disputa.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': O comprador, dentro do prazo de liberação da transação, abriu uma disputa.');
          				break;


          			case 6: //REFUNDED
          				$order->update_status('failed', __('O valor da transação foi devolvido para o comprador.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': O valor da transação foi devolvido para o comprador.');
          				break;


          			case 7: //CANCELLED
          				$order->update_status('cancelled', __('A transação foi cancelada sem ter sido finalizada.', 'woothemes') );
          				if ($this->debug=='yes') $this->log->add( 'cielodireto', 'Pedido '.$order->id.': A transação foi cancelada sem ter sido finalizada.');
          				break;


          			default:
          				break;

        		}
      		}
    	} //Fim da função successful_request
  	} //Fim da classe woocommerce_cielodireto



  	//Add the gateway to WooCommerce
  	function add_cielodireto_gateway( $methods ) {
  		$methods[] = 'woocommerce_cielodireto';
  		return $methods;
  	}



  	add_filter('woocommerce_payment_gateways', 'add_cielodireto_gateway' );
}
?>