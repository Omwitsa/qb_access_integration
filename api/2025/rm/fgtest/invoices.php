<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../configs/2025/rm/fgtest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchRMInvoice'){
      // $invoiceNo = trim($_GET["invoiceNo"]);

      $item='Roses';
      $invoiceHeaderQuery = "SELECT InvoiceHeaderId, ClientId, InvoiceDate, InvoiceNo, ShippingTerms, FlightDate, QBInvoiceNo, Ref, DocumentFee FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 25 AND InvoiceDate Between #01/01/2026# And #12/31/2026# ORDER BY InvoiceHeaderId";
      $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
      $invoiceHeaderStatement->execute();
      $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
      foreach($invoiceHeaderResults as $invoiceHeaderRow){
         $invoiceHeaderId = $invoiceHeaderRow[0];
         $txnID = $invoiceHeaderId ;
         $custId = $invoiceHeaderRow[1];
         $invoiceDate = $invoiceHeaderRow[2];
         $invoiceNo = $invoiceHeaderRow[3];
         $shippingTerms = $invoiceHeaderRow[4];
         $flightDate = $invoiceHeaderRow[5];
         $QBInvoiceNo = $invoiceHeaderRow[6];
         $ref = $invoiceHeaderRow[7];
         $documentFee = $invoiceHeaderRow[8];

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

         $customerQuery = "SELECT ClientName, Country, ClientCode, CurrencyCode, QBCustomerName FROM Client WHERE ExporterId = 25 AND ClientId = $custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute();
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            // $custCountryId = $customerRow[1];
            $currency = $customerRow[3];
            $qbCustName = $customerRow[4];
            $currencyName = getCurrencyFullName($currency);
            $arAcc = "Accounts Receivable - $currency"; 
         }

         if(!empty($qbCustName)){
            $insertQuickbooks = "INSERT INTO qb_invoice(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, RefNumber, PONumber, Currency_FullName) 
            VALUES(:txnID, :timeCreated, :qbCustName, :arAcc, :invoiceDate, :invoiceNo, :qBInvoiceNo, :currencyName);";
            $insertQbInvoiceStatement=$con_quickbooks->prepare($insertQuickbooks);
            $insertQbInvoiceResult=$insertQbInvoiceStatement->execute(array(
               ':txnID'=> $txnID,
               ':timeCreated' => $timecreated,
               ':qbCustName' => $qbCustName,
               ':arAcc' => $arAcc,
               ':invoiceDate' => $invoiceDate,
               ':invoiceNo' => $invoiceNo,
               ':qBInvoiceNo' => $QBInvoiceNo,
               ':currencyName' => $currencyName
            ));

            $invoicelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testrosesfg');
            $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903);

            $invoiceLines = array();
            $invoiceLineQuery = "SELECT InvoiceLineId, VarietyId, BoxQty, Price, StemQty, StemLength FROM InvoiceLine WHERE InvoiceHeaderId = $invoiceHeaderId"; 
            $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
            $invoiceLineStatement->execute();
            $invoiceLineResults=$invoiceLineStatement->fetchAll();

            $totalStemQty = 0;
            foreach($invoiceLineResults as $invoiceLineRow){
               $invoiceLineId=$invoiceLineRow[0];
               $varietyId=$invoiceLineRow[1];
               $boxQty=$invoiceLineRow[2]; 
               $price=$invoiceLineRow[3];
               $stemQty=$invoiceLineRow[4];
               $stemLength=$invoiceLineRow[5];
               $qnty=$stemQty;

               if($varietyId < 1){ // mixed box
                  $mixedBoxQuery = "SELECT InvoiceLineId, VarietyId, Price, StemQty, StemLength FROM InvoiceLineMix WHERE InvoiceLineId = $invoiceLineId"; 
                  $mixedBoxStatement = $con_ho->prepare($mixedBoxQuery);
                  $mixedBoxStatement->execute();
                  $mixedBoxResults=$mixedBoxStatement->fetchAll();
                  foreach($mixedBoxResults as $productRow){
                     $varietyId=$invoiceLineRow[1];
                     $price=$invoiceLineRow[2];
                     $mixedStemQty=$invoiceLineRow[3];
                     $stemLength=$invoiceLineRow[4];
                     $qnty = $boxQty * $mixedStemQty;
                  }
               }

               $productQuery = "SELECT VarietyName FROM Variety WHERE VarietyId=$varietyId";
               $productStatement = $con_gen->prepare($productQuery);
               $productStatement->execute();
               $productResults=$productStatement->fetchAll();
               foreach($productResults as $productRow){
                  $varietyname=$productRow[0];
               }

               $totalStemQty += $qnty;
               $descrip = $varietyname.' - '.$stemLength;
               $rate=number_format($price,4);
               if($documentFee!=null){
                  $documentFeeRate=number_format($documentFee,4);
                  array_push($invoiceLines, "('$txnID', '$item', 'Document fee', '1', '$documentFeeRate')");
               }
               array_push($invoiceLines, "('$txnID', '$item', '$descrip', '$qnty', '$rate')");
            }
           
            $strInvoiceLines= implode(',', $invoiceLines);
            if($strInvoiceLines){
               $inserInvoiceQuery = "INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate) VALUES $strInvoiceLines;";
               $insertInvoiceStatement=$con_quickbooks->prepare($inserInvoiceQuery);
               $insertInvoiceStatement->execute();
            }

            $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = '$totalStemQty' WHERE TxnID = '$txnID'";
            $invoiceHeaderUpdateStatement= $con_quickbooks->prepare($invoiceHeaderUpdateQuery);
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
