<?php
   include 'access.php';
   require_once '../../../../configs/2025/veg/aaatest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   $customerQuery = "SELECT CustomerId, CustomerName, CountryId, Active, CustomerFullName, CustomerAddress, CustomerAddress2, EmailRecepients, CurrencyCode, ShippingTerms, DropOffId, CollectionPointId, FinalInvoiceType, QBCustomerNameFG FROM Customer ORDER BY CurrencyCode ASC";
   $customerStatement = $con_gen->prepare($customerQuery);
   $customerStatement->execute();
   $customerResults=$customerStatement->fetchAll();
   foreach($customerResults as $customerRow){
      $customerId = $customerRow[0];
      $countryId = $customerRow[2];
      $active = $customerRow[3];
      $customerFullName = $customerRow[4];
      $customerAddress = $customerRow[5];
      $customerAddress2 = $customerRow[6];
      $emailRecepients = $customerRow[7];
      $dropOffId = $customerRow[10];
      $collectionPointId = $customerRow[11];
      $qbCustName = $customerRow[13];

      $dropOffName = "";
      $collectionPointName = "";
      $countryName = "";

      $itemtax= $countryId == 7 ? 'VAT Zero Rate' : 'VAT Exempt';

      if(strlen($qbCustName) > 0){
         $dropOffQuery = "SELECT DropOffName FROM DropOff WHERE DropOffId = :dropOffId";
         $dropOffStatement = $con_ho->prepare($dropOffQuery);
         $dropOffStatement->execute(array(
            ':dropOffId'=> $dropOffId
         ));
         $dropOffResults=$dropOffStatement->fetchAll();
         foreach($dropOffResults as $dropOffRow){
            $dropOffName = $dropOffRow[0];
         }
         
         $collectionPointQuery = "SELECT CollectionPointName FROM CollectionPoint WHERE CollectionPointId = :collectionPointId";
         $collectionPointStatement = $con_ho->prepare($collectionPointQuery);
         $collectionPointStatement->execute(array(
            ':collectionPointId'=> $collectionPointId
         ));
         $CollectionPointResults=$collectionPointStatement->fetchAll();
         foreach($CollectionPointResults as $collectionPointRow){
            $collectionPointName = $collectionPointRow[0];
         }

         $countryQuery = "SELECT CountryName FROM Country WHERE CountryId = :countryId";
         $countryStatement = $con_ho->prepare($countryQuery);
         $countryStatement->execute(array(
            ':countryId'=> $countryId
         ));
         $countryResults=$countryStatement->fetchAll();
         foreach($countryResults as $countryRow){
            $countryName = $countryRow[0];
         }

         $qbCustmerStatement = $con_quickbooks->prepare('SELECT * FROM qb_customer WHERE FullName=:FullName');
         $qbCustmerStatement->execute(array(
         'FullName' => $qbCustName
         ));
         $count=$qbCustmerStatement->rowCount();
         if($count < 1){
            $insertcustomerQuery = "INSERT INTO qb_customer(TimeCreated,ListID,Name,FullName,IsActive,CompanyName,BillAddress_Addr1, BillAddress_Addr2, BillAddress_Addr3,BillAddress_Addr4,
            BillAddress_Country,BillAddressBlock_Addr1,BillAddressBlock_Addr2, BillAddressBlock_Addr3,BillAddressBlock_Addr4,ShipAddress_Addr1,ShipAddress_Addr2,ShipAddress_Addr3,
            ShipAddress_Addr4,ShipAddress_Country,ShipAddressBlock_Addr1,ShipAddressBlock_Addr2,ShipAddressBlock_Addr3,ShipAddressBlock_Addr4,Email,SalesTaxCode_FullName,ItemSalesTax_FullName)
            VALUES(:TimeCreated,:ListID, :Name, :FullName, :IsActive, :CompanyName,:BillAddress_Addr1, :BillAddress_Addr2, :BillAddress_Addr3, :BillAddress_Addr4,:BillAddress_Country,
            :BillAddressBlock_Addr1, :BillAddressBlock_Addr2, :BillAddressBlock_Addr3,:BillAddressBlock_Addr4,:ShipAddress_Addr1,:ShipAddress_Addr2,:ShipAddress_Addr3,:ShipAddress_Addr4,
            :ShipAddress_Country,:ShipAddressBlock_Addr1,:ShipAddressBlock_Addr2,:ShipAddressBlock_Addr3,:ShipAddressBlock_Addr4,:Email,:SalesTaxCode_FullName,:ItemSalesTax_FullName)";

            $insertcustomerStatement=$con_quickbooks->prepare($insertcustomerQuery);
            $insertcustomerStatement->execute(array(':TimeCreated' => $timecreated, ':ListID' => $customerId, ':Name' => $qbCustName, ':FullName' => $qbCustName,':IsActive'=> $active,
               ':CompanyName' => $customerFullName,':BillAddress_Addr1' => $customerFullName, ':BillAddress_Addr2' => $customerAddress,':BillAddress_Addr3' => $customerAddress2,
               ':BillAddress_Addr4' => $dropOffName,':BillAddress_Country' => $countryName,':BillAddressBlock_Addr1' => $customerFullName,':BillAddressBlock_Addr2' => $customerAddress,
               ':BillAddressBlock_Addr3' => $customerAddress2,':BillAddressBlock_Addr4' => $dropOffName, ':ShipAddress_Addr1' => $customerFullName,':ShipAddress_Addr2' => $customerAddress,
               ':ShipAddress_Addr3' => $customerAddress2,':ShipAddress_Addr4' => $collectionPointName,':ShipAddress_Country' => $countryName,':ShipAddressBlock_Addr1' => $customerFullName,
               ':ShipAddressBlock_Addr2' => $customerAddress,':ShipAddressBlock_Addr3' => $customerAddress2,':ShipAddressBlock_Addr4' => $collectionPointName,':Email' => $emailRecepients,
               ':SalesTaxCode_FullName' => 'Tax',':ItemSalesTax_FullName' => $itemtax));

            $customerlastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $customerequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testvegaaa2025');
            $customerequeue->enqueue(QUICKBOOKS_ADD_CUSTOMER, $customerlastid, 7);
         }
      }
   }

   // $response = new stdClass();
   // $response->success = true;
   // $response->data =  '';
   // $response->message = 'Customer Synched successfully';

   // echo json_encode($response);
?>