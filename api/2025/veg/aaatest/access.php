<?php
  include 'env.php';
  $con_ho='';
  $con_gen ='';

  $db_username = ''; 
  $db_password = '';
  $ho_path = "C:\\Users\\IT-DEVELOPER\\Desktop\\Offline\\veg\\VegetableManagerHOData.mdb";

  try {
    $con_quickbooks = new PDO("mysql:host=$mysql_servername;dbname=$mysql_dbname", $mysql_username, $mysql_password);
    $con_quickbooks->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }
  catch(PDOException $e)
  {
    echo "Connection failed: " . $e->getMessage();
  }

  if (!file_exists($ho_path)) {
    die("ho Access database file not found(Ho Data) !");
  }
  try{
    $con_ho = new PDO("odbc:DRIVER={Microsoft Access Driver (*.mdb)}; DBQ=$ho_path; Uid=$db_username; Pwd=$db_password;");
  }
  catch(PDOException $e){
    echo $e->getMessage();
  }

  $db_username = ''; 
  $db_password = ''; 
  $gen_path = "C:\\Users\\IT-DEVELOPER\\Desktop\\Offline\\veg\\VegetableManagerGeneralData.mdb";

  if (!file_exists($gen_path)) {
      die("Access database file not found(General Data) !");
  }
  try{
    $con_gen = new PDO("odbc:DRIVER={Microsoft Access Driver (*.mdb)}; DBQ=$gen_path; Uid=$db_username; Pwd=$db_password;");
  }
  catch(PDOException $e){
    echo $e->getMessage();
  }
?>
