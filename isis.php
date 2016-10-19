<?php

if (extension_loaded('oci8'))
    require_once "database_oci.php";
else
    require_once "database_pdo.php";

function echoerror($exception){
    ob_clean();
    die('Error ' . $exception->getMessage());
}

function libxml_display_error($error)
{
    $return = "";
    switch ($error->level) {
        case LIBXML_ERR_WARNING:
            $return .= "Warning $error->code : ";
            break;
        case LIBXML_ERR_ERROR:
            $return .= "Error $error->code : ";
            break;
        case LIBXML_ERR_FATAL:
            $return .= "Fatal Error $error->code : ";
            break;
    }
    $return .= trim($error->message);
    $return .= " on line $error->line\n";

    return $return;
}

function libxml_display_errors() {
    $ret = "";
    $errors = libxml_get_errors();
    foreach ($errors as $error) {
        $ret .= libxml_display_error($error);
    }
    libxml_clear_errors();
    return $ret;
}


function findMatch($matches,$request,$field){
    return $matches[$request][$field];
}

function locate($matches,$found,$value){
    $selected = -1;
    foreach($matches as $id=>$match){
//	print_r($match);
        if($match['query']===$found){
            if($match['queryMatch']!=''){
                //echo('/' . $match['queryMatch'] . '/' . "\n");
                if(preg_match('/' . $match['queryMatch'] . '/',$value)===1){
                    $selected = $id;
                    //echo('found ' . $id . "\n");
                }else{
                    //echo('not found' . "\n");
                }
            }else
                $selected = $id;
        }
    }
    //echo "Selected = $selected\n";
    return $selected;
}

function formMultiPart($file,$data,$mime_boundary,$eol) { 
	$cc = '';
	$cc .= '--' . $mime_boundary . $eol;
	$cc .= "Content-Disposition: form-data; name=\"$file\"; filename=\"$file\"" . $eol;
	$cc .= 'Content-Type: text/plain' . $eol;
	$cc .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
	$cc .= chunk_split(base64_encode($data)) . $eol;

	return $cc;
}

//var_dump($_GET);

$matches = json_decode(file_get_contents('isisParams.json'),true);
$genericError = 'templates/' . $matches["errorTemplate"];
$matches = $matches["camt"];
//print_r($matches);
$input = file_get_contents('php://input');

$query = simplexml_load_string($input);

$namespaces = $query->getDocNamespaces();
$query->registerXPathNamespace('u',$namespaces[""]);

$namespace = array_pop(split(':',$namespaces[""]));
echo $namespace . "\n";
$domelement = dom_import_simplexml($query);
$domdoc = $domelement->ownerDocument;

$valid = false;
$selectedXsd = "";
foreach (scandir('xsd') as $schema){
    if(!(strpos($schema,$namespace)===false)){
        libxml_use_internal_errors(true);
        if($domdoc->schemaValidate('xsd/' . $schema)){
		echo "matched $schema\n";
		$valid = true;
	        $selectedXsd = $schema;
		//break;
	}else{
            echo "Not validated with : $schema\n";
        }
    }else{
	echo "skipping $schema\n";
    }
}
echo "schema=$selectedXsd\n";
if($valid){
    $selected = locate($matches,$selectedXsd,$input);
    if($selected == -1){
        //header("HTTP/1.1 404 NOT FOUND",TRUE,404);
        header("HTTP/1.1 200 OK",TRUE,200);
        $errorMessage = "Found match, but filtered out\n";
        $errorMessage .= "XSD = $selectedXsd";
        include $genericError;
        exit;
    }
    $vars = array();
    foreach(findMatch($matches,$selected,"parameters") as $param=>$path){
        $query->registerXPathNamespace('u',$namespaces[""]);
        $rr = ($query->xpath($path)); 
        $vars[$param] = (string) $rr[0];
    }
    $vars = array_merge($vars, $_GET);
    //var_dump($vars);
    //var_dump(findMatch($matches,$selected,"responseTemplate"));
    $errorTemplate = findMatch($matches,$selected,"errorTemplate");
    $errorTemplate = ( ($errorTemplate==null) ? $genericError : $errorTemplate);
    $errorTemplate = 'templates/' . $errorTemplate;
    if(findMatch($matches,$selected,"displayError")==="On"){
            $errorMessage = "Requested error";
            ob_start();
            include $errorTemplate;
            $errorOutput = ob_get_contents();
            ob_end_clean();
            header("HTTP/1.1 200 OK",TRUE,200);
            echo trim(preg_replace('/\s+/', ' ', $errorOutput));
            exit;
    }
    $response = '';
    $multiple = false;
    if(!is_array(findMatch($matches,$selected,"responseTemplate"))){
	$templates = array(findMatch($matches,$selected,"responseTemplate"));
	$formats = array(findMatch($matches,$selected,"responseFormat"));
    }else{
        $templates = findMatch($matches,$selected,"responseTemplate");
	$formats = findMatch($matches,$selected,"responseFormat");
        $multiple = true;
    }
    $eol = "\r\n";
    $mime_boundary=md5(time());
    $nrep = 0;
    foreach($templates as $template){    
        $respxml = 'templates/' . $template;
        ob_start();
        include $respxml;
        $output = ob_get_contents();
        ob_end_clean();
        $outputxml = new DOMDocument();
        $outputxml->loadXML(preg_replace('/\s*(<[^>]*>)\s*/','$1',$output));
// $outputxml->loadXML($output);
//die(print_r($output,true));
        if(!($outputxml->schemaValidate('xsd/' . $formats[$nrep]))){
            $errorMessage = "Could not validate output with " . $formats[$nrep] . "\n";
            $errorMessage .= libxml_display_errors();
            ob_start();
            include $errorTemplate;
            $errorOutput = ob_get_contents();
            ob_end_clean();
            header("HTTP/1.1 200 OK",TRUE,200);
            echo $errorOutput;
            exit;
        }
        $outputxml->formatOutput=false;
        $outputxml->preserveWhiteSpace = false;
        if($multiple)
            $response .= formMultiPart($template,$outputxml->saveXML(),$mime_boundary,$eol);
        else
            $response = $outputxml->saveXML();
	$nrep++;
    }

    if($multiple){
        header("HTTP/1.1 200 OK",TRUE,200);
        header("Content-type: multipart/form-data; boundary=$mime_boundary");
        echo $response;
        echo "--" . $mime_boundary . "--" . $eol . $eol;
    }else{  
        header("HTTP/1.1 200 OK",TRUE,200);
        header('Content-type','application/xml');
        echo $response;
    }
}else{
    //header("HTTP/1.1 400 INVALID REQUEST",TRUE,400);
    header("HTTP/1.1 200 OK",TRUE,200);
    $errorMessage = "Unable to find appropriate response.\n";
    $errorMessage .= libxml_display_errors();
    include $genericError;
    exit; 
}
 
