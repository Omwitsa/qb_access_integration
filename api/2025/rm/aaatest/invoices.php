<?php
   include 'access.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchRMInvoice'){
      // $invoiceNo = trim($_GET["invoiceNo"]);
      
      include 'functions.php';
      require_once '../../../../configs/2025/rm/aaatest/quickbooks.php';
      $invoiceHeaderQuery = "SELECT InvoiceHeaderId, ClientId, InvoiceDate, InvoiceNo, ShippingTerms, FlightDate, QBInvoiceNo, Ref, DocumentFee FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 24 AND InvoiceDate Between #1/1/2026# AND #31/12/2026# ORDER BY InvoiceHeaderId";
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

         if(isset($invoiceNo)){
            $invoiceNo = substr(trim($invoiceNo), 0, 11);
         }
         $qbInvoiceQuery = "SELECT RefNumber FROM qb_invoice WHERE RefNumber = :invoiceNo;";
         $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
         $qbInvoiceStatement->execute(array(
            ':invoiceNo'=> $invoiceNo
         ));
         $qbInvoiceRows = $qbInvoiceStatement->rowCount();

         if($qbInvoiceRows > 0){
            $updateInvoiceQuery="UPDATE InvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE InvoiceHeaderId = :invoiceHeaderId";
            $updateInvoiceStatement=$con_ho->prepare($updateInvoiceQuery);
            $updateInvoiceStatement->execute(array(
               ':invoiceHeaderId'=> $invoiceHeaderId,
               ':QBTransferStatus'=> 1
            ));

            continue;
         }

         $currency = "";
         $qbCustName = "";
         $customerQuery = "SELECT ClientName, Country, ClientCode, CurrencyCode, QBCustomerNameAAA FROM Client WHERE ClientId = :custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute(array(
            ':custId'=> $custId
         ));
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            // $custCountryId = $customerRow[1];
            $currency = $customerRow[3];
            $qbCustName = $customerRow[4];
            $currencyName = getCurrencyFullName($currency);
            $arAcc = "Accounts Receivable - $currency"; 
         }

         $exchangeRate = 1;
         $exchangeRateQuery = "SELECT TOP 1 EffectiveDate, RateUSD, RateEUR, RateGBP FROM ExchangeRate ORDER BY EffectiveDate DESC;";
         $exchangeRateStatement = $con_ho->prepare($exchangeRateQuery);
         $exchangeRateStatement->execute();
         $exchangeRateResults=$exchangeRateStatement->fetchAll();
         foreach($exchangeRateResults as $exchangeRateRow){
            if($currency === "USD"){
               $exchangeRate = $exchangeRateRow[1];
            }
            if($currency === "EUR"){
               $exchangeRate = $exchangeRateRow[2];
            }
            if($currency === "GBP"){
               $exchangeRate = $exchangeRateRow[3];
            }
         }

         if(!empty($qbCustName)){
            $insertQuickbooks = "INSERT INTO qb_invoice(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, RefNumber, PONumber, Currency_FullName, ExchangeRate) 
            VALUES(:txnID, :timeCreated, :qbCustName, :arAcc, :invoiceDate, :invoiceNo, :qBInvoiceNo, :currencyName, :exchangeRate);";
            $insertQbInvoiceStatement=$con_quickbooks->prepare($insertQuickbooks);
            $insertQbInvoiceResult=$insertQbInvoiceStatement->execute(array(
               ':txnID'=> $txnID,
               ':timeCreated' => $timecreated,
               ':qbCustName' => $qbCustName,
               ':arAcc' => $arAcc,
               ':invoiceDate' => $invoiceDate,
               ':invoiceNo' => $invoiceNo,
               ':qBInvoiceNo' => $QBInvoiceNo,
               ':currencyName' => $currencyName,
               ':exchangeRate' => $exchangeRate
            ));

            $invoicelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testrosesaaa');
            $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903);

            $invoiceLineQuery = "SELECT InvoiceLineId, VarietyId, BoxQty, Price, StemQty, StemLength FROM InvoiceLine WHERE InvoiceHeaderId = :invoiceHeaderId"; 
            $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
            $invoiceLineStatement->execute(array(
               ':invoiceHeaderId'=> $invoiceHeaderId
            ));
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

               if($varietyId > 0){ 
                  $productQuery = "SELECT VarietyName, SpeciesId FROM Variety WHERE VarietyId = :varietyId";
                  $productStatement = $con_gen->prepare($productQuery);
                  $productStatement->execute(array(
                     ':varietyId'=> $varietyId
                  ));
                  $productResults=$productStatement->fetchAll();
                  foreach($productResults as $productRow){
                     $varietyname=$productRow[0];
                     $speciesId=$productRow[1];
                  }

                  $item = $speciesId == 2 ? "Summer Flowers" : "Roses";
                  $descrip = $varietyname.' - '.$stemLength;
                  $rate=number_format($price,4);
                  $inserInvoiceQuery = 'INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate) 
                  VALUES(:Invoice_TxnID, :Item_FullName, :Descrip, :Quantity, :Rate);';
                  $insertInvoiceLineStatement=$con_quickbooks->prepare($inserInvoiceQuery);
                  $insertInvoiceLineStatement->execute(array(
                     ':Invoice_TxnID'=> $txnID,
                     ':Item_FullName' => $item,
                     ':Descrip' => $descrip,
                     ':Quantity' => $qnty,
                     ':Rate' => $rate
                  ));

                  $totalStemQty += $qnty;
               }
               else{// mixed box
                  $mixedBoxQuery = "SELECT InvoiceLineId, VarietyId, Price, StemQty, StemLength FROM InvoiceLineMix WHERE InvoiceLineId = :invoiceLineId"; 
                  $mixedBoxStatement = $con_ho->prepare($mixedBoxQuery);
                  $mixedBoxStatement->execute(array(
                     ':invoiceLineId'=> $invoiceLineId
                  ));
                  $mixedBoxResults=$mixedBoxStatement->fetchAll();
                  foreach($mixedBoxResults as $mixedBoxRow){
                     $varietyId=$mixedBoxRow[1];
                     $price=$mixedBoxRow[2];
                     $mixedStemQty=$mixedBoxRow[3];
                     $stemLength=$mixedBoxRow[4];
                     $qnty = $boxQty * $mixedStemQty;

                     $productQuery = "SELECT VarietyName, SpeciesId FROM Variety WHERE VarietyId = :varietyId";
                     $productStatement = $con_gen->prepare($productQuery);
                     $productStatement->execute(array(
                        ':varietyId'=> $varietyId
                     ));
                     $productResults=$productStatement->fetchAll();
                     foreach($productResults as $productRow){
                        $varietyname=$productRow[0];
                        $speciesId=$productRow[1];
                     }

                     $item = $speciesId == 2 ? "Summer Flowers" : "Roses";
                     $descrip = $varietyname.' - '.$stemLength;
                     $rate=number_format($price,4);
                     $inserInvoiceQuery = 'INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate) 
                     VALUES(:Invoice_TxnID, :Item_FullName, :Descrip, :Quantity, :Rate);';
                     $insertInvoiceLineStatement=$con_quickbooks->prepare($inserInvoiceQuery);
                     $insertInvoiceLineStatement->execute(array(
                        ':Invoice_TxnID'=> $txnID,
                        ':Item_FullName' => $item,
                        ':Descrip' => $descrip,
                        ':Quantity' => $qnty,
                        ':Rate' => $rate
                     ));

                     $totalStemQty += $qnty;
                  }
               }
            }

            if($documentFee!=null){
               $documentFeeRate=number_format($documentFee,4);
               $inserInvoiceQuery = 'INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate) 
               VALUES(:Invoice_TxnID, :Item_FullName, :Descrip, :Quantity, :Rate);';
               $insertInvoiceLineStatement=$con_quickbooks->prepare($inserInvoiceQuery);
               $insertInvoiceLineStatement->execute(array(
                  ':Invoice_TxnID'=> $txnID,
                  ':Item_FullName' => $item,
                  ':Descrip' => 'Document fee',
                  ':Quantity' => '1',
                  ':Rate' => $documentFeeRate
               ));
            }
           
            $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = :FOB WHERE TxnID = :txnID";
            $invoiceHeaderUpdateStatement= $con_quickbooks->prepare($invoiceHeaderUpdateQuery);
            $invoiceHeaderUpdateStatement->execute(array(
               ':txnID'=> $txnID,
               ':FOB'=> $totalStemQty
            ));

            $invoiceQbStatusUpdate="UPDATE InvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE InvoiceHeaderId = :invoiceHeaderId;";
            $invoiceQbStatusUpdateStatement= $con_ho->prepare($invoiceQbStatusUpdate);
            $invoiceQbStatusUpdateStatement->execute(array(
               ':invoiceHeaderId'=> $invoiceHeaderId,
               ':QBTransferStatus'=> 1
            ));
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data = '';
      $response->message = 'Invoice Synched successfully';

      echo json_encode($response);
   }

   if($_GET["action"] === 'getRmAAAInvoicesStats'){
      $results["invoices"] = array();
      $qbInvoicesQuery = "SELECT Customer_FullName, RefNumber, ARAccount_FullName, TxnDate, qbsql_last_errmsg, TimeCreated FROM qb_invoice WHERE qbsql_last_errmsg IS NOT NULL ORDER BY RefNumber DESC;";
      $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoicesQuery);
      $qbInvoiceStatement->execute();
      $invoicesResults=$qbInvoiceStatement->fetchAll();
      foreach($invoicesResults as $row){
         $invoice = new stdClass();
         $invoice->customer = $row[0];
         $invoice->refNo = $row[1];
         $invoice->accountRecievable = $row[2];
         $invoice->date = $row[3];
         $invoice->error = $row[4];
         $invoice->timeCreated = $row[5];

         array_push($results["invoices"], $invoice);
      }

      $stagedInvoiceCountQuery = "SELECT COUNT(*) FROM qb_invoice WHERE TimeModified IS NULL AND qbsql_last_errmsg IS NULL;";
      $stagedInvoiceCountStatement = $con_quickbooks->prepare($stagedInvoiceCountQuery);
      $stagedInvoiceCountStatement->execute();
      $stagedInvoiceCount = $stagedInvoiceCountStatement->fetchColumn();
      $results["stagedInvoiceCount"] = $stagedInvoiceCount;

      $unsynchedInvoiceCountQuery = "SELECT COUNT(*) FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 24 AND  QBTransferStatus IS NULL AND InvoiceDate Between #1/1/2026# AND #31/12/2026#";
      $unsynchedInvoiceCountStatement = $con_ho->prepare($unsynchedInvoiceCountQuery);
      $unsynchedInvoiceCountStatement->execute();
      $unsynchedInvoiceCount = $unsynchedInvoiceCountStatement->fetchColumn();
      $results["unsynchedInvoiceCount"] = $unsynchedInvoiceCount;

      $unsynchedAuctionInvoiceCountQuery = "SELECT COUNT(*) FROM AuctionInvoiceHeader WHERE Finalized = Yes AND ExporterId = 24 AND  QBTransferStatus IS NULL AND InvoiceDate Between #1/1/2026# AND #31/12/2026#";
      $unsynchedAuctionInvoiceCountStatement = $con_ho->prepare($unsynchedAuctionInvoiceCountQuery);
      $unsynchedAuctionInvoiceCountStatement->execute();
      $unsynchedAuctionInvoiceCount = $unsynchedAuctionInvoiceCountStatement->fetchColumn();
      $results["unsynchedAuctionInvoiceCount"] = $unsynchedAuctionInvoiceCount;

      $output = new stdClass();
      $output->success = true;
      $output->message = "Successfull";
      $output->data = $results;
     
      echo json_encode($output);
   }
?>
