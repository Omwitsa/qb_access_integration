<?php
   function insertItem($productId, $con_gen, $con_quickbooks, $timecreated){
      $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId	FROM Product WHERE ProductId = $productId";
      $productStatement = $con_gen->prepare($productQuery);
      $productStatement->execute();
      $productResults=$productStatement->fetchAll();
      foreach($productResults as $productRow){
         $label = null;
         $sublevel = null;
         $parentfullName = null;
         $productCode = $productRow[1];
         $productName = $productRow[2];
         $productCode2 = $productRow[3];
         $productTypeId = $productRow[4];
         $customerId = $productRow[5];
         $productBoxCount = $productRow[7];
         $price = $productRow[8];
         $custCategoryId=$productRow[9];

         // $productCode2-
         $productName = str_replace(" ", "", substr($productName, 0, 29));
         $descrip = $productCode2."_".$productName."x".$productBoxCount;
         $descrip = $productBoxCount < 1 ? $productCode2."_".$productName."".$productBoxCount : $descrip;
         if(strlen($productCode2) < 1){
            $descrip = $productName."x".$productBoxCount;
            $descrip = $productBoxCount < 1 ? $productName."".$productBoxCount : $descrip;
         }

         $subitem = str_replace(" ", "", $descrip);
         if (strpos($subitem, ":")) {
            continue;
         }

         if($customerId == 0) {
            $custCategoryQuery = "SELECT CustomerCategoryName FROM CustomerCategory WHERE CustomerCategoryId = :custCategoryId";
            $custCategoryStatement = $con_gen->prepare($custCategoryQuery);
            $custCategoryStatement->execute(array(
               ':custCategoryId' => $custCategoryId
               ));
            $custCategoryResults=$custCategoryStatement->fetchAll();
            foreach($custCategoryResults as $custCategoryRow){
               $custCategoryName = $custCategoryRow[0];
               if(strlen($custCategoryName) > 0){
                  $custCategoryName = trim($custCategoryName);
                  $flamingoitems = 'Mini'.'-'.$custCategoryName;
                  $itemfullname = $flamingoitems.":".$subitem;

                  $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE FullName=:FullName');
                  $qbItemStatement->execute(array(
                     ':FullName' => $flamingoitems
                     ));
                     
                  $itemCount = $qbItemStatement->fetchColumn();
                  if($itemCount < 1){
                     $insertItemStatement = $con_quickbooks->prepare('INSERT INTO qb_itemnoninventory(TimeCreated, Name, FullName, IsActive, SalesTaxCode_FullName, SalesOrPurchase_Account_FullName)
                     VALUES(:TimeCreated, :Name, :FullName, :IsActive, :SalesTaxCode_FullName, :SalesOrPurchase_Account_FullName )');
                     $insertItemStatement->execute(array(':TimeCreated' => $timecreated, ':Name' => $flamingoitems, ':FullName' => $flamingoitems, ':IsActive' => 1,
                     ':SalesTaxCode_FullName' => "Tax", ':SalesOrPurchase_Account_FullName' => "Sales:A Sales Veg" ));
                     
                     $qbItemLastid = $con_quickbooks->lastInsertId();
                     $qbItemQueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170/testvegfg2025');
                     $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 907);
                  }

                  $sublevel = $itemCount > 0 ? 1 : $sublevel;
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
                     $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 905);
                  }
               }
            }
         }
         else{
            $customerCode = "";
            $customerStatement = $con_gen->prepare('SELECT CustomerName, CustomerCode, CustomerFullName, QBCustomerNameFG	FROM Customer WHERE CustomerId = :CustomerId');
            $customerStatement->execute(array(
            ':CustomerId' => $customerId
            ));
            $customerResults=$customerStatement->fetchAll();
            foreach($customerResults as $customerRow){
               $customerCode = $customerRow[1];
               $customerFullName = $customerRow[2];
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
               $productTypeName = isset($productTypeName) ? trim($productTypeName) : $productTypeName;
               $flamingoitems = substr($customerCode, 0, 31)." ".$productTypeName;
               $flamingoitems = strlen($productTypeName) < 1 ? substr($customerCode, 0, 31) : $flamingoitems;
               if(isset($customerFullName)){
                  $customerFullName = trim($customerFullName);
                  $itemfullname = substr($customerFullName, 0, 31);
                  $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE FullName=:FullName');
                  $qbItemStatement->execute(array(
                     ':FullName' => $itemfullname
                     ));

                  $itemCount = $qbItemStatement->fetchColumn();
                  if($itemCount < 1){
                     $insertItemStatement = $con_quickbooks->prepare('INSERT INTO qb_itemnoninventory(TimeCreated, Name, FullName, IsActive, SalesTaxCode_FullName, SalesOrPurchase_Account_FullName)
                     VALUES(:TimeCreated, :Name, :FullName, :IsActive, :SalesTaxCode_FullName, :SalesOrPurchase_Account_FullName )');
                     $insertItemStatement->execute(array(':TimeCreated' => $timecreated, ':Name' => $itemfullname, ':FullName' => $itemfullname, ':IsActive' => 1,
                     ':SalesTaxCode_FullName' => "Tax", ':SalesOrPurchase_Account_FullName' => "Sales:A Sales Veg" ));
                     
                     $qbItemLastid = $con_quickbooks->lastInsertId();
                     $qbItemQueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170/testvegfg2025');
                     $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 907);
                  }

                  $parentfullName = $itemfullname;
                  $itemfullname = $parentfullName.":".$flamingoitems;
                  $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE FullName=:FullName');
                  $qbItemStatement->execute(array(
                     ':FullName' => $itemfullname
                     ));

                  $itemCount = $qbItemStatement->fetchColumn();
                  if($itemCount < 1){
                     $insertItemStatement = $con_quickbooks->prepare('INSERT INTO qb_itemnoninventory(TimeCreated, Name, FullName, IsActive, Parent_FullName, Sublevel, SalesTaxCode_FullName, SalesOrPurchase_Account_FullName)
                     VALUES(:TimeCreated, :Name, :FullName, :IsActive, :Parent_FullName, :Sublevel, :SalesTaxCode_FullName, :SalesOrPurchase_Account_FullName )');
                     $insertItemStatement->execute(array(':TimeCreated' => $timecreated, ':Name' => $flamingoitems, ':FullName' => $itemfullname, ':IsActive' => 1,
                     ':Parent_FullName' => $parentfullName, ':Sublevel' => 1, ':SalesTaxCode_FullName' => "Tax", ':SalesOrPurchase_Account_FullName' => "Sales:A Sales Veg" ));
                     
                     $qbItemLastid = $con_quickbooks->lastInsertId();
                     $qbItemQueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170/testvegfg2025');
                     $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 906);
                  }

                  $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE Parent_FullName=:Parent_FullName');
                  $qbItemStatement->execute(array(
                     ':Parent_FullName' => $parentfullName
                     ));

                  $itemCount = $qbItemStatement->fetchColumn();
                  $sublevel = $itemCount > 0 ? 1 : $sublevel;

                  $qbItemStatement = $con_quickbooks->prepare('SELECT COUNT(*) FROM qb_itemnoninventory WHERE FullName=:FullName');
                  $qbItemStatement->execute(array(
                     ':FullName' => $itemfullname
                     ));

                  $itemCount = $qbItemStatement->fetchColumn();
                  $sublevel = $itemCount > 0 ? 2 : $sublevel;

                  if(strtoupper($customerCode) == 'TS' OR strtoupper($customerCode) == 'JS' OR strtoupper($customerCode) == 'MS'   OR strtoupper($customerCode) == 'MO' 
                     OR strtoupper($customerCode) == 'MKT' OR strtoupper($customerCode) == 'BK' OR strtoupper($customerCode) == 'WR' OR strtoupper($customerCode) == 'AL'){
                     $label=' SNN';
                  }

                  $parentfullName = $itemfullname;
                  $itemfullname = $parentfullName.":".$subitem;
                  if(strtoupper(substr($customerCode, 0, 31)) == 'AL'){
                     $subitem = $productCode.$label;
                     $itemfullname = 'Flamingo Produce Ltd'.":".$flamingoitems.":".$subitem;
                  }

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
                     $qbItemQueue->enqueue(QUICKBOOKS_ADD_NONINVENTORYITEM, $qbItemLastid, 905);
                  }
               }
            }
         }
      }
   }

   

   // $response = new stdClass();
   // $response->success = true;
   // $response->data =  '';
   // $response->message = 'Products Synched successfully';

   // echo json_encode($response);
?>