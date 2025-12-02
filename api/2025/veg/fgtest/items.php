<?php
   include 'access.php';
   require_once '../../../../configs/2025/veg/fgtest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId	FROM Product ORDER BY ProductId ASC";
   $productStatement = $con_gen->prepare($productQuery);
   $productStatement->execute();
   $productResults=$productStatement->fetchAll();
   foreach($productResults as $productRow){
      $labels = null;
      $parentfullName = null;
      $sublevel=2;
      $productCode = $productRow[1];
      $productName = $productRow[2];
      $productCode2 = $productRow[3];
      $productTypeId = $productRow[4];
      $customerId = $productRow[5];
      $productBoxCount = $productRow[7];
      $price = $productRow[8];
      $custCategoryId=$productRow[9];

      $descrip = $productCode2."-".substr($productName, 0, 29)."x".$productBoxCount;
      $descrip = $productBoxCount < 1 ? $productCode2."-".substr($productName, 0, 29)."".$productBoxCount : $descrip;
      if(strlen($productCode2) < 1){
         $descrip = substr($productName, 0, 29)."x".$productBoxCount;
         $descrip = $productBoxCount < 1 ? substr($productName, 0, 29)."".$productBoxCount : $descrip;
      }

      $subitem = str_replace(" ", "", $descrip);
      $customerCode = "";
      $customerStatement = $con_gen->prepare('SELECT CustomerName, CustomerCode, CustomerFullName, QBCustomerNameFG	FROM Customer WHERE CustomerId = :CustomerId');
      $customerStatement->execute(array(
      ':CustomerId' => $customerId
      ));
      $customerResults=$customerStatement->fetchAll();
      foreach($customerResults as $customerRow){
         $customerCode = $customerRow[1];
         $customerFullName = $customerRow[2];
         if(strtoupper($customerCode) == 'TS' OR strtoupper($customerCode) == 'JS' OR strtoupper($customerCode) == 'MS'   OR strtoupper($customerCode) == 'MO' 
         OR strtoupper($customerCode) == 'MKT' OR strtoupper($customerCode) == 'BK' OR strtoupper($customerCode) == 'WR' OR strtoupper($customerCode) == 'AL'){
            $labels=' SNN';
            $sublevel=1;
         }
      }

      $productTypeName = "";
      $productTypeQuery = "SELECT ProductTypeName FROM ProductType WHERE ProductTypeId = :productTypeId";
      $productTypeStatement = $con_gen->prepare($productTypeQuery);
      $productTypeStatement->execute(array(
         ':productTypeId' => $productTypeId
         ));
      $productTypeResults=$productTypeStatement->fetchAll();
      foreach($productTypeResults as $productTypeRow){
         $productTypeName = $productTypeRow[0];
         $flamingoitems = substr($customerCode, 0, 31)." ".$productTypeName;
         $flamingoitems = strlen($productTypeName) < 1 ? substr($customerCode, 0, 31) : $flamingoitems;
         if(isset($labels)){ 
            $parentfullName = substr($customerFullName, 0, 31).":".$flamingoitems;
         }
          
         $itemfullname = substr($customerFullName, 0, 31).":".$flamingoitems.":".$subitem;
      }
      
      if($customerId == 0){
         $custCategoryQuery = "SELECT CustomerCategoryName FROM CustomerCategory WHERE CustomerCategoryId = :custCategoryId";
         $custCategoryStatement = $con_gen->prepare($custCategoryQuery);
         $custCategoryStatement->execute(array(
            ':custCategoryId' => $customerId
            ));
         $custCategoryResults=$custCategoryStatement->fetchAll();
         foreach($custCategoryResults as $custCategoryRow){
            $custCategoryName = $custCategoryRow[0];
            $flamingoitems='Mini'.'-'.$custCategoryName;
            $itemfullname = $flamingoitems.":".$subitem;
         }
      }

      if($labels != null){
         $itemfullname = $itemfullname." ".$labels;
      }
      $itemfullname = strtoupper(substr($customerCode, 0, 31)) == 'AL' ? 'Flamingo Produce Ltd'.":".$flamingoitems.":".$productCode : $itemfullname;
      $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE FullName=:FullName');
      $qbItemStatement->execute(array(
         ':FullName' => $itemfullname
         ));
         
      $itemCount = $qbItemStatement->fetchColumn();
      if($itemCount < 1){
         $insertItemStatement = $con_quickbooks->prepare('INSERT INTO qb_itemnoninventory(TimeCreated, Name, FullName, IsActive, Parent_FullName, Sublevel, SalesTaxCode_FullName, SalesOrPurchase_Desc, SalesOrPurchase_Price, SalesOrPurchase_Account_FullName)
         VALUES(:TimeCreated, :Name, :FullName, :IsActive, :Parent_FullName, :Sublevel, :SalesTaxCode_FullName,:SalesOrPurchase_Desc, :SalesOrPurchase_Price, :SalesOrPurchase_Account_FullName )');
         $insertItemStatement->execute(array(':TimeCreated' => $timecreated, ':Name' => $subitem, ':FullName' => $itemfullname, ':IsActive' => 1,
         ':Parent_FullName' => $parentfullName, ':Sublevel' => $sublevel, ':SalesTaxCode_FullName' => "Tax", ':SalesOrPurchase_Desc' => $subitem, 
         ':SalesOrPurchase_Price' => $price, ':SalesOrPurchase_Account_FullName' => "Sales:A Sales Veg" ));
         
         $qbItemLastid = $con_quickbooks->lastInsertId();
         $qbItemQueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170/testvegfg2025');
         $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 6);
      }
   }

   // $response = new stdClass();
   // $response->success = true;
   // $response->data =  '';
   // $response->message = 'Products Synched successfully';

   // echo json_encode($response);
?>