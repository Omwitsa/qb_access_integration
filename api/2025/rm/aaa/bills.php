<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../configs/2025/rm/aaa/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncRmBills'){
      $billsQuery = "SELECT InvoiceHeaderId, AgentInvoiceValue, AgentVAT, AgentInvoiceDate, FlightNo, AWBChargeableWeight, AWB, InvoiceNo, AgentInvoiceNo, HandlingAgentId, Ref FROM InvoiceHeader WHERE ExporterId = 24 AND AgentInvoiceNo is not Null AND AgentInvoiceDate Between #7/1/2025# And #12/31/2026#  ORDER BY InvoiceHeaderId";
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

         if(isset($agentInvoiceNo)){
            $agentInvoiceNo = substr($agentInvoiceNo, 0, 20);
         }
         $qbBillsQuery = "SELECT RefNumber  FROM qb_bill WHERE RefNumber = :agentInvoiceNo";
         $qbBillStatement = $con_quickbooks->prepare($qbBillsQuery);
         $qbBillStatement->execute(array(
            ':agentInvoiceNo'=> $agentInvoiceNo
         ));

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
         $agentQuery = "SELECT HandlingAgentId, QBHandlingAgentNameAAA FROM HandlingAgent WHERE HandlingAgentId = :clearingAgentId";
         $agentStatement = $con_gen->prepare($agentQuery);
         $agentStatement->execute(array(
            ':clearingAgentId'=> $clearingAgentId
         ));
         $agentResults=$agentStatement->fetchAll();
         foreach($agentResults as $agentRow){
            $qbAgentName = $agentRow[1];
         }

         if(!empty($invoiceNo)){
            $memo = "Flight No- $flightNo Wt - $weight AWB-  $awb, Invoice No - $invoiceNo";
            $amountDueInHomeCurrency = $amountDue * $exchangeRate;
            $APAccount = "Accounts Payable - $currency";
            
            $insertQbBills = "INSERT INTO qb_bill(TxnID, TimeCreated, Vendor_FullName, APAccount_FullName, TxnDate, AmountDue, Currency_FullName, ExchangeRate, AmountDueInHomeCurrency, RefNumber, Memo)
            VALUES(:txnID, :TimeCreated, :qbAgentName, :APAccount, :date, :amountDue, :currency, :exchangeRate, :homeCurrency, :agentInvoiceNo, :memo);";
            $insertQbBillStatement=$con_quickbooks->prepare($insertQbBills);
            $insertQbBillsResult=$insertQbBillStatement->execute(array(':txnID' => $txnID, ':TimeCreated' => $timecreated, ':qbAgentName' => $qbAgentName, ':APAccount' => $APAccount,
            ':date' => $date, ':amountDue' => $amountDue, ':currency' => $currencyName, ':exchangeRate' => $exchangeRate, ':homeCurrency' => $amountDueInHomeCurrency, ':agentInvoiceNo' => $agentInvoiceNo,
            ':memo' => $memo));

            $billLastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $billqueue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $billqueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/rosesaaa2025');
            $billqueue->enqueue(QUICKBOOKS_ADD_BILL, $billLastid, 903);

            $sortOrder = 1;
            $txnLineID = $txnID . '-'. $sortOrder;
            if($vat){
               $taxable = $vat / 0.16;
               $insertBillsQuery = "INSERT INTO qb_bill_expenseline(Bill_TxnID, SortOrder, TxnLineID, Account_FullName, Amount, Memo) 
               VALUES(:txnID, :sortOrder, :txnLineID, :Account, :Amount, :memo);";
               $insertBillStatement=$con_quickbooks->prepare($insertBillsQuery);
               $insertBillStatement->execute(array(':txnID' => $txnID, ':sortOrder' => $sortOrder, ':txnLineID' => $txnLineID, 
               ':Account' => "AA  AIRLINE EXPENSES:Freight Charges", ':Amount' => $taxable, ':memo' => $memo));

               $sortOrder = 2;
               $txnLineID = $txnID . '-'. $sortOrder;
               $net = $amountDue - ($taxable + $vat);
               $insertBillsQuery = "INSERT INTO qb_bill_expenseline(Bill_TxnID, SortOrder, TxnLineID, Account_FullName, Amount, Memo) 
               VALUES(:txnID, :sortOrder, :txnLineID, :Account, :Amount, :memo);";
               $insertBillStatement=$con_quickbooks->prepare($insertBillsQuery);
               $insertBillStatement->execute(array(':txnID' => $txnID, ':sortOrder' => $sortOrder, ':txnLineID' => $txnLineID, 
               ':Account' => "AA  AIRLINE EXPENSES:Freight Charges", ':Amount' => $net, ':memo' => '<0%>'));

               $sortOrder = 3;
               $txnLineID = $txnID . '-'. $sortOrder;
               $insertBillsQuery = "INSERT INTO qb_bill_expenseline(Bill_TxnID, SortOrder, TxnLineID, Account_FullName, Amount, Memo) 
               VALUES(:txnID, :sortOrder, :txnLineID, :Account, :Amount, :memo);";
               $insertBillStatement=$con_quickbooks->prepare($insertBillsQuery);
               $insertBillStatement->execute(array(':txnID' => $txnID, ':sortOrder' => $sortOrder, ':txnLineID' => $txnLineID, 
               ':Account' => "KSHS II:VAT BANK", ':Amount' => $vat, ':memo' => 'VAT'));
            }
            else{
               $sortOrder = 1;
               $txnLineID = $txnID . '-'. $sortOrder;
               $insertBillsQuery = "INSERT INTO qb_bill_expenseline(Bill_TxnID, SortOrder, TxnLineID, Account_FullName, Amount, Memo) 
               VALUES(:txnID, :sortOrder, :txnLineID, :Account, :Amount, :memo);";
               $insertBillStatement=$con_quickbooks->prepare($insertBillsQuery);
               $insertBillStatement->execute(array(':txnID' => $txnID, ':sortOrder' => $sortOrder, ':txnLineID' => $txnLineID, 
               ':Account' => "AA  AIRLINE EXPENSES:Freight Charges", ':Amount' => $amountDue, ':memo' => '<0%>'));
            }
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Bills Synched successfully';

      echo json_encode($response);
   }
   if($_GET["action"] === 'getRMaaaBillsStats'){
      $results["items"] = array();
      $qbQuery = "SELECT Vendor_FullName, RefNumber, APAccount_FullName, TxnDate, qbsql_last_errmsg, TimeCreated FROM qb_bill WHERE qbsql_last_errmsg IS NOT NULL ORDER BY RefNumber DESC;";
      $qbStatement = $con_quickbooks->prepare($qbQuery);
      $qbStatement->execute();
      $qbResults=$qbStatement->fetchAll();
      foreach($qbResults as $row){
         $item = new stdClass();
         $item->vendor = $row[0];
         $item->refNo = $row[1];
         $item->accountPayable = $row[2];
         $item->date = $row[3];
         $item->error = $row[4];
         $item->timeCreated = $row[5];

         array_push($results["items"], $item);
      }

      $stagedCountQuery = "SELECT COUNT(*) FROM qb_bill WHERE TimeModified IS NULL AND qbsql_last_errmsg IS NULL;";
      $stagedCountStatement = $con_quickbooks->prepare($stagedCountQuery);
      $stagedCountStatement->execute();
      $stagedCount = $stagedCountStatement->fetchColumn();
      $results["stagedCount"] = $stagedCount;

      $output = new stdClass();
      $output->success = true;
      $output->message = "Successfull";
      $output->data = $results;
     
      echo json_encode($output);
   }
?>