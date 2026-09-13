<?php
require('configure.php');
function getOiTemporal($usr){
	try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $dbh->prepare("CALL input_clientes.sp_crea_correlativo_nacional(:usr);
								");
			
			$stmt->bindParam(':usr', $usr, PDO::PARAM_STR);			
			$stmt->execute();
			$result = $stmt->fetch(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;
		}
		catch(PDOException $e){
			echo $e->getMessage();
		}
	}

	function getInfOi($id){
		try{
				$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
				$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$stmt = $dbh->prepare("CALL input_clientes.sp_inf_oi_nacional(:id);
									");
				
				$stmt->bindParam(':id', $id, PDO::PARAM_STR);			
				$stmt->execute();
				$result = $stmt->fetchall(PDO::FETCH_ASSOC);
				$stmt->closeCursor();
				return $result;
			}
			catch(PDOException $e){
				echo $e->getMessage();
			}
		}
		function getIdOI($usr){
			try{
					$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
					$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
					$stmt = $dbh->prepare("select
					CONCAT(o.id_comprador,'-',o.id_oi_temp) AS id
					FROM
					input_clientes.oi_correlativo_nacional AS o
					INNER JOIN
					(
					SELECT
					c.id_comprador
					FROM
					input_clientes.usuarios AS u
					INNER JOIN input_clientes.compradores AS c ON u.id_usuario=c.nombre_abreviado
					WHERE
					u.id_usuario=:usr
					) AS comp ON o.id_comprador=comp.id_comprador
										");
					
					$stmt->bindParam(':usr', $usr, PDO::PARAM_STR);			
					$stmt->execute();
					$result = $stmt->fetch(PDO::FETCH_ASSOC);
					$stmt->closeCursor();
					return $result;
				}
				catch(PDOException $e){
					echo $e->getMessage();
				}
			}
function datos_control($usr,$pwd){
	try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $dbh->prepare("
								SELECT
									u.id_usuario,
									REPLACE(u.nombre,'ñ','n') AS nombre,
									u.id_usuario AS nombre_abreviado,
									u.password,
									u.ingresar_oi AS ingresar_oi
									FROM
									input_clientes.usuarios AS u
								WHERE 
									u.id_usuario = :usr and u.password = :pwd");
			$stmt->bindParam(':usr', $usr, PDO::PARAM_STR);
			$stmt->bindParam(':pwd', $pwd, PDO::PARAM_STR);
			$stmt->execute();
			$result = $stmt->fetch(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;
		}
		catch(PDOException $e){
			echo $e->getMessage();
		}
	}

function ingresar_oi($sku, $cantidad, $comentario, $usr, $id, $tipo){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		call input_clientes.sp_act_PCampos_test_nacional (:sku,:cantidad,:com,:tipo,:usr, :id );
		";
		//#call sp_act_promociones_empujes_new(:sku,:cantidad,STR_TO_DATE(:fi,'%d-%m-%Y'), STR_TO_DATE(:ft,'%d-%m-%Y'),:usr);"
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':sku', $sku, PDO::PARAM_STR);
		$stmt->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
		$stmt->bindParam(':com', $comentario, PDO::PARAM_STR);
		$stmt->bindParam(':usr', $usr, PDO::PARAM_STR);
		$stmt->bindParam(':id', $id, PDO::PARAM_INT);
		$stmt->bindParam(':tipo', $tipo, PDO::PARAM_STR);
		$stmt->execute();
		$crow = $stmt->rowCount();
		$stmt->closeCursor();
		return $crow;
		/*$result = $stmt->fetch(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;*/
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
	
	}
	function cargarOI($id){
		try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$query = "call input_clientes.sp_act_pr_nacional (:id );";
			//#call sp_act_promociones_empujes_new(:sku,:cantidad,STR_TO_DATE(:fi,'%d-%m-%Y'), STR_TO_DATE(:ft,'%d-%m-%Y'),:usr);"
			$stmt = $dbh->prepare($query);
			$stmt->bindParam(':id', $id, PDO::PARAM_STR);
			$stmt->execute();
			$crow = $stmt->rowCount();
			$stmt->closeCursor();
			return $crow;
		}
		catch(PDOException $e){
			echo $e->getMessage();
		}
		
		}


function eliminarOI($id){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		update PCampos_test_nacional 
		SET estado='Eliminado' , fecha_modificacion=CURDATE()
		where
		id_oi=:id";
		
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_STR);
		$stmt->execute();
		$crow = $stmt->rowCount();
		$stmt->closeCursor();
		return $crow;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
	
	}
function aprobarOI($id){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		update PCampos_test_nacional 
		SET estado='Aprobado', fecha_modificacion=CURDATE()
		where
		id_oi=:id";
		
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_STR);
		$stmt->execute();
		$crow = $stmt->rowCount();
		$stmt->closeCursor();
		return $crow;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
	
}
function modificarPO($id,$po){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		update PCampos_test_nacional 
		SET PO=:po,
		fecha_emision=(SELECT s.fecha_emision FROM sodimac_grt.SLI_nuevo AS s WHERE s.id_oc= SUBSTRING_INDEX(:po,',',1) LIMIT 1) 
		where
		id_oi=:id";
		
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_STR);
		$stmt->bindParam(':po', $po, PDO::PARAM_STR);
		$stmt->execute();
		$crow = $stmt->rowCount();
		$stmt->closeCursor();
		return $crow;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
	
}
function ingresarComentario($id,$com){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		update PCampos_test_nacional 
		SET comentario_oi=:com
		where
		id_oi=:id";
		
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_STR);
		$stmt->bindParam(':com', $com, PDO::PARAM_STR);
		$stmt->execute();
		$crow = $stmt->rowCount();
		$stmt->closeCursor();
		return $crow;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}

}
function getOI($id){
	try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$query = "
		select * FROM PCampos_test_nacional 
		
		where
		id_oi=:id";
		
		$stmt = $dbh->prepare($query);
		$stmt->bindParam(':id', $id, PDO::PARAM_STR);
		$stmt->execute();
		$result = $stmt->fetchall(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		return $result;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
	
}

function getOis($user){
	try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $dbh->prepare("
			SELECT
			p.id_oi,
			Count(p.sku) AS skus,
			GROUP_CONCAT(' ',p.sku ) AS lista,
			p.comentario,
			p.id_proveedor,
			p.estado,
			p.fecha_ingreso,
			p.fecha_modificacion,
			pv.nombre_proveedor prov,
			p.PO,
			p.comentario_oi AS com
			FROM
			PCampos_test_nacional AS p
			left join
			sodimac_grt.proveedores AS pv ON p.id_proveedor=pv.id_proveedor
			
			WHERE
			p.id_comprador=:u
			#AND p.fecha_ingreso >=SUBDATE(CURDATE(),INTERVAL 1 week)
			GROUP BY
			p.id_oi
			ORDER BY
			p.fecha_ingreso DESC
								");
			$stmt->bindParam(':u', $user, PDO::PARAM_INT);
			$stmt->execute();
			
			$result = $stmt->fetchall(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;
		}
	catch(PDOException $e){
		echo $e->getMessage();
	}
}

function skus_oi($id){
	try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $dbh->prepare("
			SELECT
			p.sku,
			pm.descripcion_producto
			FROM
			PCampos_test_nacional AS p
			INNER JOIN
			sodimac_grt.productos_maestro AS pm ON p.sku=pm.sku
			
			WHERE
			p.id_oi=:id
								");
			$stmt->bindParam(':id', $id, PDO::PARAM_STR);
			$stmt->execute();
			
			$result = $stmt->fetchall(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;
		}
	catch(PDOException $e){
		echo $e->getMessage();
	}
}

function getOisEstado($user,$estado){
try{
		$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$stmt = $dbh->prepare("
		SELECT
		p.id_oi,
		Count(p.sku) AS skus,
		GROUP_CONCAT(' ',p.sku ) AS lista,
		p.comentario,
		p.id_proveedor,
		p.estado,
		p.fecha_ingreso,
		p.fecha_modificacion,
		pv.nombre_proveedor prov,
		p.PO,
		p.comentario_oi AS com
		FROM
		PCampos_test_nacional AS p
		left join
		sodimac_grt.proveedores AS pv ON p.id_proveedor=pv.id_proveedor
		
		WHERE
		p.id_comprador=:u AND p.estado=:e
		#AND p.fecha_ingreso >=SUBDATE(CURDATE(),INTERVAL 1 week)
		GROUP BY
		p.id_oi
		ORDER BY
		p.fecha_ingreso DESC
							");
		$stmt->bindParam(':u', $user, PDO::PARAM_INT);
		$stmt->bindParam(':e', $estado, PDO::PARAM_STR);
		$stmt->execute();
		
		$result = $stmt->fetchall(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		return $result;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
}
function getComprador($user){
	try{
			$dbh = new PDO(DB_NAME.":host=".DB_SERVER.";dbname=".DB_DATABASE,DB_SERVER_USERNAME,DB_SERVER_PASSWORD);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $dbh->prepare("
			select
		c.id_comprador
		FROM
		usuarios AS u 
		INNER JOIN 
		compradores AS c ON u.id_usuario=c.nombre_abreviado 
		WHERE u.id_usuario=:u
								");
			$stmt->bindParam(':u', $user, PDO::PARAM_STR);
			$stmt->execute();
			
			$result = $stmt->fetch(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
			return $result;
	}
	catch(PDOException $e){
		echo $e->getMessage();
	}
}
function ingreso($sku,$cant,$mot,$usr,$id,$tipo){
	//echo  $sku."-".$cant."-".$mot."-".$usr."-".$id;
	echo ingresar_oi($sku,$cant,$mot,$usr,$id,$tipo);
	
}

	if(isset($_GET['sku'],$_GET['cantidad'],	$_GET['comentario'],	$_GET['usr'],$_GET['id'], $_GET['tipo'])){
		//$r= ingresar_oi(	$_GET['sku'],$_GET['cantidad'],	$_GET['comentario'],	$_GET['usr'],$_GET['id']);
		ingreso(	$_GET['sku'],$_GET['cantidad'],	$_GET['comentario'],	$_GET['usr'],$_GET['id'],$_GET['tipo']);
		
		//echo $r ;
		//echo "fjsdklfjsdlkfjsklafjsdklfjaskl";
		}


?>