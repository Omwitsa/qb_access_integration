<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../configs/2025/veg/fgtest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchRMInvoice'){
      // $invoiceNo = trim($_GET["invoiceNo"]);

      $flamingoproducelimited='2BB - Flamingo Produce UK Ltd';
      
      $invoiceHeaderQuery = "SELECT InvoiceHeaderId, CustomerId, InvoiceDate, InvoiceNo, ShippingTerms, FlightDate, QBInvoiceNo, Ref FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 2 AND InvoiceDate Between #01/01/2026# And #12/31/2026# ORDER BY InvoiceHeaderId";
      $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
      $invoiceHeaderStatement->execute();
      $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
      foreach($invoiceHeaderResults as $invoiceHeaderRow){
         $invoiceHeaderId = $invoiceHeaderRow[0];
         $txnID = $invoiceHeaderId ;
         $invoiceCustId = $invoiceHeaderRow[1];
         $invoiceDate = $invoiceHeaderRow[2];
         $invoiceNo = $invoiceHeaderRow[3];
         $shippingTerms = $invoiceHeaderRow[4];
         $flightDate = $invoiceHeaderRow[5];
         $QBInvoiceNo = $invoiceHeaderRow[6];
         $ref = $invoiceHeaderRow[7];

         $invoiceNo = trim($invoiceNo);
         $qbInvoiceQuery = "SELECT RefNumber FROM qb_invoice WHERE RefNumber = :invoiceNo;";
         $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
         $qbInvoiceStatement->execute(array(
            ':invoiceNo'=> $invoiceNo
         ));
         $qbInvoiceRows = $qbInvoiceStatement->rowCount();
         if($qbInvoiceRows > 0){
            $updateInvoiceQuery="UPDATE InvoiceHeader SET QBTransferStatus=1 WHERE InvoiceHeaderId=$invoiceHeaderId";
            $updateInvoiceStatement=$con_ho->prepare($updateInvoiceQuery);
            $updateInvoiceStatement->execute();
            continue;
         }

         $customerQuery = "SELECT CustomerName, CountryId, CustomerCode, CustomerFullName, CurrencyCode, QBCustomerNameFG, FinalInvoiceType FROM Customer WHERE CustomerId = $invoiceCustId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute();
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $custCountryId = $customerRow[1];
            $customerCode = $customerRow[2];
            $customerFullName = $customerRow[3];
            $currency = $customerRow[4];
            $qbCustName = $customerRow[5];
            $finalInvoiceType = $customerRow[6];
            $currencyName = getCurrencyFullName($currency);
            $arAcc = "Accounts Receivable - $currency"; 
         }

         if(!empty($qbCustName)){
            $template='EUR Invoice';
            $itemtax = $custCountryId == 7 ? 'VAT Zero Rate' : 'VAT Exempt';
            // $template = strtoupper($qbCustName) === strtoupper($flamingoproducelimited) ? 'FUK Invoice' : 'EUR Invoice';
            // $itemtax = $custCountryId === 7 ? 'Z' : 'E';

            $insertQuickbooks = "INSERT INTO qb_invoice(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, Template_FullName, RefNumber, PONumber, ShipDate, ItemSalesTax_FullName, Currency_FullName) 
            VALUES(:txnID, :timeCreated, :qbCustName, :arAcc, :invoiceDate, :template_FullName, :invoiceNo, :qBInvoiceNo, :shipDate, :itemSalesTax_FullName, :currencyName);";
            $insertQbInvoiceStatement=$con_quickbooks->prepare($insertQuickbooks);
            $insertQbInvoiceResult=$insertQbInvoiceStatement->execute(array(
               ':txnID'=> $txnID,
               ':timeCreated' => $timecreated,
               ':qbCustName' => $qbCustName,
               ':arAcc' => $arAcc,
               ':invoiceDate' => $invoiceDate,
               ':template_FullName' => $template,
               ':invoiceNo' => $invoiceNo,
               ':qBInvoiceNo' => $QBInvoiceNo,
               ':shipDate' => $flightDate,
               ':itemSalesTax_FullName' => $itemtax,
               ':currencyName' => $currencyName
            ));

            $invoicelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testvegfg2025');
            $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903);

            $invoiceLines = array();
            $invoiceLineQuery = "SELECT InvoiceLineId, ProductId, BoxCount, BoxQty, Price, LineValue, LabFL, LabBL, LabPL, CustomerBranchId FROM InvoiceLine WHERE InvoiceHeaderId = $invoiceHeaderId"; 
            $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
            $invoiceLineStatement->execute();
            $invoiceLineResults=$invoiceLineStatement->fetchAll();
            $totalCartons = 0;
            $totalWeight = 0;
            foreach($invoiceLineResults as $invoiceLineRow){
               $productId=$invoiceLineRow[1];
               $boxCount=$invoiceLineRow[2];
               $quantity=$invoiceLineRow[3];
               $lineValue=$invoiceLineRow[5];
               $labFL=$invoiceLineRow[6];
               $labBL=$invoiceLineRow[7];
               $labPL=$invoiceLineRow[8];
               $customerBranchId=$invoiceLineRow[9];
               $unitrate = $quantity > 0 ? $lineValue/$quantity : 0;
               $rate = round($unitrate,2);
               $amount = round($invoiceLineRow[5],2);
               $taxName = 'Tax';

               $labels = strtoupper($qbCustName) == strtoupper($flamingoproducelimited) ? $labFL.$labBL.$labPL : "";
               $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId FROM Product WHERE ProductId = $productId";
               $productStatement = $con_gen->prepare($productQuery);
               $productStatement->execute();
               $productResults=$productStatement->fetchAll();
               foreach($productResults as $productRow){
                  $productCode = $productRow[1];
                  $productName = $productRow[2];  
                  $productCode2 = $productRow[3];
                  $productTypeId = $productRow[4];
                  $productCustomerId = $productRow[5];
                  $netweightkg= $productRow[6];
                  $productBoxCount = $productRow[7];
                  $custCategoryId=$productRow[9];

                  $descrip = $productCode2."-".substr($productName, 0, 29)."x".$productBoxCount;
                  $descrip = $productBoxCount < 1 ? $productCode2."-".substr($productName, 0, 29)."".$productBoxCount : $descrip;
                  if(strlen($productCode2) < 1){
                     $descrip = substr($productName, 0, 29)."x".$productBoxCount;
                     $descrip = $productBoxCount < 1 ? substr($productName, 0, 29)."".$productBoxCount : $descrip;
                  }

                  $subitem = str_replace(" ", "", $descrip);
               }

               $custCategoryQuery = "SELECT CustomerCategoryName FROM CustomerCategory WHERE CustomerCategoryId = $custCategoryId";
               $custCategoryStatement = $con_gen->prepare($custCategoryQuery);
               $custCategoryStatement->execute();
               $custCategoryResults=$custCategoryStatement->fetchAll();
               foreach($custCategoryResults as $custCategoryRow){
                  $custCategoryName = $custCategoryRow[0];
                  $flamingoitems='Mini'.'-'.$custCategoryName;
                  $itemfullname = $flamingoitems.":".$subitem;
               }

               $productTypeQuery = "SELECT ProductTypeName FROM ProductType WHERE ProductTypeId = $productTypeId";
               $productTypeStatement = $con_gen->prepare($productTypeQuery);
               $productTypeStatement->execute();
               $productTypeResults=$productTypeStatement->fetchAll();
               foreach($productTypeResults as $productTypeRow){
                  $productTypeName = $productTypeRow[0];
                  if($productCustomerId != 0){
                     $flamingoitems = substr($customerCode, 0, 31)." ".$productTypeName;
                     $flamingoitems = strlen($productTypeName) < 1 ? substr($customerCode, 0, 31) : $flamingoitems;
                     $itemfullname=substr($customerFullName, 0, 31).":".$flamingoitems.":".$subitem;
                  }
               }
               
               $itemfullname = $flamingoitems.":".$subitem." ".$labels;
               $itemfullname = strtoupper(substr($customerCode, 0, 31)) == 'AL' ? 'Flamingo Produce Ltd'.":".$flamingoitems.":".$productCode : $itemfullname;
               $lineWeight = $netweightkg * $boxCount * $quantity;
               $other1 = $lineWeight.'Kgs net'; 
               array_push($invoiceLines, "('$txnID', '$itemfullname', '', '$quantity', '$rate', '$amount', '$taxName', '$other1')");

               $totalCartons += $quantity;
               $totalWeight += $lineWeight;
            }

            $strInvoiceLines= implode(',', $invoiceLines);
            if($strInvoiceLines){
               $inserInvoiceQuery = "INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate, Amount, SalesTaxCode_FullName, Other1) VALUES $strInvoiceLines;";
               $insertInvoiceStatement=$con_quickbooks->prepare($inserInvoiceQuery);
               $insertInvoiceStatement->execute();
            }

            $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = '$totalCartons Cartons', Other = '$totalWeight Kgs net' WHERE TxnID = '$txnID'";
            $invoiceHeaderUpdateStatement= $con_ho->prepare($invoiceHeaderUpdateQuery);
            $invoiceHeaderUpdateStatement->execute();

            $invoiceQbStatusUpdate="UPDATE InvoiceHeader SET QBTransferStatus = 1 WHERE InvoiceHeaderId = $invoiceHeaderId;";
            $invoiceQbStatusUpdateStatement= $con_ho->prepare($invoiceQbStatusUpdate);
            $invoiceQbStatusUpdateStatement->execute();
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data = '';
      $response->message = 'Invoice Synched successfully';

      echo json_encode($response);
   }
?>
