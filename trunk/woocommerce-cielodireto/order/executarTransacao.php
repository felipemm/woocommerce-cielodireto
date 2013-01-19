<?php 
	header('Content-Type: text/html; charset=UTF-8');
	
	include('keys.php');
	require "../includes/pedido.php";
	
	$objResposta = null;
	
	$action = $_GET["action"];
		
	$Pedido = new Pedido('requests.log');
	$Pedido->tid = $_GET["tid"]; 
	$Pedido->dadosEcNumero = LOJA;
	$Pedido->dadosEcChave = LOJA_CHAVE;
	
	switch($action){
		case "authorize":  
			$objResposta = $Pedido->RequisicaoAutorizactionTid();
			break;
		case "capture": 
			//$valor = $_GET["valor"];
			//$objResposta = $Pedido->RequisicaoCaptura($valor, null);
			$objResposta = $Pedido->RequisicaoConsulta();
			break;
		case "cancel":
			$objResposta = $Pedido->RequisicaoCancelamento();
			break;
		case "check": 
			$objResposta = $Pedido->RequisicaoConsulta();
			break; 
	}
?>
<html>
	<body>
		<script>
			alert(document.location.pathname);
			function close(){
				window.opener.location.href = window.opener.location.href;
				if (window.opener.progressWindow){
					window.opener.progressWindow.close()
				}
				window.close();
			}
		</script>
		
		<center>
			<textarea name="xmlRetorno" cols="70" rows="40"><?php echo htmlentities(utf8_encode($objResposta->asXML())); ?></textarea>	
			<p>
				<input type="button" onclick="javascript: close();" value="Fechar"/>
			</p>
		</center>
	</body>
</html>