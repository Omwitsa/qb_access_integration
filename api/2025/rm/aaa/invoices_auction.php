<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../configs/2025/rm/aaa/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchRMAuctionInvoice'){
      // $invoiceNo = trim($_GET["invoiceNo"]);
      
      $item='Roses';
      $invoiceHeaderQuery="SELECT AuctionInvoiceHeaderId, AuctionId, ClientId, InvoiceDate, InvoiceNo, AuctionWeekNo, QBInvoiceNo FROM AuctionInvoiceHeader WHERE Finalized = Yes AND ExporterId = 24 AND InvoiceDate Between #1/1/2026# AND #31/12/2026# ORDER BY AuctionInvoiceHeaderId DESC";
      $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
      $invoiceHeaderStatement->execute();
      $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
      foreach($invoiceHeaderResults as $invoiceHeaderRow){
         $invoiceHeaderId = $invoiceHeaderRow[0];
         $txnID = $invoiceHeaderId ;
         $auctionId = $invoiceHeaderRow[1];
         $custId = $invoiceHeaderRow[2];
         $invoiceDate = $invoiceHeaderRow[3];
         $invoiceNo = $invoiceHeaderRow[4];
         $auctionWeekNo = $invoiceHeaderRow[5];
         $QBInvoiceNo = $invoiceHeaderRow[6];

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
            $updateInvoiceQuery="UPDATE AuctionInvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE AuctionInvoiceHeaderId = :invoiceHeaderId";
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

         $auctionQuery = "SELECT AuctionName FROM Auction  WHERE AuctionId = :auctionId";
         $auctionStatement = $con_ho->prepare($auctionQuery);
         $auctionStatement->execute(array(
            ':auctionId'=> $auctionId
         ));
         $auctionResult = $auctionStatement->fetch();
         $auctionName = $auctionResult['AuctionName'];

         if(!empty($qbCustName)){
            $insertQuickbooks = "INSERT INTO qb_invoice(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, RefNumber, PONumber, Currency_FullName, ExchangeRate, ShipMethod_FullName) 
            VALUES(:txnID, :timeCreated, :qbCustName, :arAcc, :invoiceDate, :invoiceNo, :qBInvoiceNo, :currencyName, :exchangeRate,:ShipMethod_FullName);";
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
               ':exchangeRate' => $exchangeRate,
               ':ShipMethod_FullName' => $auctionName
            ));

            $invoicelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/rosesaaa2025');
            $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903);

            // $invoiceLineQuery = "SELECT InvoiceLineId, VarietyId, BoxQty, Price, StemQty, StemLength FROM InvoiceLine WHERE InvoiceHeaderId = $invoiceHeaderId"; 
            $invoiceLineQuery = "SELECT AuctionWeekNo, AuctionId, VarietyId, StemLength, StemQty, Turnover, ExporterId FROM AuctionSales WHERE AuctionWeekNo=:AuctionWeekNo AND AuctionId=:AuctionId AND ExporterId=:ExporterId"; 
            $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
            $invoiceLineStatement->execute(array
            (
            ':AuctionWeekNo' => $auctionWeekNo,
            ':AuctionId' => $auctionId,
            ':ExporterId' => 24
            ));
            $invoiceLineResults=$invoiceLineStatement->fetchAll();
            $totalStemQty = 0;
            foreach($invoiceLineResults as $invoiceLineRow){
               $varietyId = $invoiceLineRow[2];
               $stemLength = $invoiceLineRow[3];
               $stemQty = $invoiceLineRow[4];
               $turnover = $invoiceLineRow[5];
               $qnty=$stemQty;

               $productQuery = "SELECT VarietyName, SpeciesId FROM Variety WHERE VarietyId=:VarietyId";
               $productStatement = $con_ho->prepare($productQuery);
               $productStatement->execute(array
               (
               ':VarietyId' => $varietyId
               ));
               $productResult = $productStatement->fetch();
               $varietyName = $productResult['VarietyName'];
               $speciesId = $productResult['SpeciesId'];

               $item = $speciesId == 2 ? "Summer Flowers" : $item;
               $descrip = $varietyName.' - '.$stemLength;
               $rate=number_format($turnover/$stemQty,3);
               $totalStemQty += $qnty;

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
            }

            $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = :FOB WHERE TxnID = :txnID";
            $invoiceHeaderUpdateStatement= $con_quickbooks->prepare($invoiceHeaderUpdateQuery);
            $invoiceHeaderUpdateStatement->execute(array(
               ':txnID'=> $txnID,
               ':FOB'=> $totalStemQty
            ));

            $updateInvoiceQuery="UPDATE AuctionInvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE AuctionInvoiceHeaderId = :invoiceHeaderId";
            $updateInvoiceStatement=$con_ho->prepare($updateInvoiceQuery);
            $updateInvoiceStatement->execute(array(
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
?>
