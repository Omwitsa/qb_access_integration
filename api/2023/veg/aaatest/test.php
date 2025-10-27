<?php
 $response = new stdClass();
 $response->success = true;
 $response->data = '';
 $response->message = 'Hapo';

// $output["response"]=array();
// array_push($output["response"], $response);

echo json_encode($response);
?>