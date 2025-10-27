<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../configs/2025/veg/fgtest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncVegBills'){
      $billsQuery = "SELECT InvoiceHeaderId, AgentInvoiceValue, AgentVAT, AgentInvoiceDate, FlightNo, AWBChargeableWeight, AWB, InvoiceNo, AgentInvoiceNo, ClearingAgentId, Ref FROM InvoiceHeader WHERE ExporterId = 2 AND AgentInvoiceNo is not Null AND AgentInvoiceDate Between #7/1/2025# And #12/31/2026#  ORDER BY InvoiceHeaderId";
      $billStatement = $con_ho->prepare($billsQuery);
      $billStatement->execute();
      $billsResults=$billStatement->fetchAll();
      foreach($billsResults as $billRow){
         $invoiceId = $billRow[0];
         $txnID = $invoiceId;
         $amountDue = $billRow[1];
         $vat = $billRow[2];
         $date = $billRow[3];
         $flightNo = $billRow[4];
         $weight = $billRow[5];
         $awb = $billRow[6];
         $invoiceNo = $billRow[7];
         $agentInvoiceNo = $billRow[8];
         $clearingAgentId = $billRow[9];
         $ref = $billRow[10];

         $qbBillsQuery = "SELECT RefNumber  FROM qb_bill WHERE RefNumber = '$agentInvoiceNo'";
         $qbBillStatement = $con_quickbooks->prepare($qbBillsQuery);
         $qbBillStatement->execute();
         $qbBillRows = $qbBillStatement->rowCount();
         if($qbBillRows > 0){
            continue;
         }

         $currency = "USD";
         $currencyName = getCurrencyFullName($currency);
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

         $qbAgentName = "ZZ $ Tradewinds Logistics";
         $agentQuery = "SELECT ClearingAgentId, QBClearingAgentNameFG FROM ClearingAgent WHERE ClearingAgentId = $clearingAgentId";
         $agentStatement = $con_gen->prepare($agentQuery);
         $agentStatement->execute();
         $agentResults=$agentStatement->fetchAll();
         foreach($agentResults as $agentRow){
            $qbAgentName = $agentRow[1];
         }

         if(!empty($invoiceNo)){
            $memo = "Flight No- $flightNo Wt - $weight AWB-  $awb, Invoice No - $invoiceNo";
            $amountDueInHomeCurrency = $amountDue * $exchangeRate;
            $insertQbBills = "INSERT INTO qb_bill(TxnID, TimeCreated, Vendor_FullName, APAccount_FullName, TxnDate, AmountDue, Currency_FullName, ExchangeRate, AmountDueInHomeCurrency, RefNumber, Memo)
            VALUES('$txnID', NOW(), '$qbAgentName', 'Accounts Payable - $currency', '$date', $amountDue, '$currencyName', '$exchangeRate', $amountDueInHomeCurrency, '$agentInvoiceNo', '$memo');";
            $insertQbBillStatement=$con_quickbooks->prepare($insertQbBills);
            $insertQbBillsResult=$insertQbBillStatement->execute();

            $billLastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $billqueue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $billqueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testvegfg2025');
            $billqueue->enqueue(QUICKBOOKS_ADD_BILL, $billLastid, 903);

            $billLines = array();
            $sortOrder = 1;
            $txnLineID = $txnID . '-'. $sortOrder;
            array_push($billLines, "('$txnID', $sortOrder, '$txnLineID', 'Freight and Shipping Costs', $amountDue, '')");

            $strBillsLines = implode(',', $billLines);
            if($strBillsLines){
               $insertBillsQuery = "INSERT INTO qb_bill_expenseline(Bill_TxnID, SortOrder, TxnLineID, Account_FullName, Amount, Memo) VALUES $strBillsLines;";
               $insertBillStatement=$con_quickbooks->prepare($insertBillsQuery);
               $insertBillStatement->execute();
            }
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Bills Synched successfully';

      echo json_encode($response);
   }
?>