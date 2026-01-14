<?php
   include 'access.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncRmPayments'){
      require_once '../../../../configs/2025/rm/aaatest/quickbooks.php';
      $custPaymentQuery = "SELECT CustomerPaymentId, ClientId, PaymentDate, IIf(IsNull([ForeignAmountPaid]),0,[ForeignAmountPaid])+IIf(IsNull([OtherAmount]),0,[OtherAmount]) +IIf(IsNull([RebateAmount]),0,[RebateAmount])  AS Amount, BankId, Description FROM CustomerPayment WHERE ExporterId = 24 AND PaymentDate Between #6/1/2025# AND #12/31/2026# ORDER BY CustomerPaymentId";
      $custPAymentStatement = $con_ho->prepare($custPaymentQuery);
      $custPAymentStatement->execute();
      $custPaymentResults=$custPAymentStatement->fetchAll();
      foreach($custPaymentResults as $custPaymentRow){
         $paymentId = $custPaymentRow[0];
         $txnID = $paymentId;
         $custId = $custPaymentRow[1];
         $paymentDate = $custPaymentRow[2];
         $amount = $custPaymentRow[3];
         $bankId = $custPaymentRow[4] ? $custPaymentRow[4] : 0;
         $memo = $custPaymentRow[5];

         if($amount < 1){
            continue;
         }

         if(isset($paymentId)){
            $paymentId = substr($paymentId, 0, 20);
         }
         $qbPaymentQuery = "SELECT RefNumber FROM qb_receivepayment WHERE RefNumber = :paymentId;";
         $qbPaymentStatement = $con_quickbooks->prepare($qbPaymentQuery);
         $qbPaymentStatement->execute(array(
            ':paymentId'=> $paymentId
         ));
         $qbPaymentRows = $qbPaymentStatement->rowCount();
         if($qbPaymentRows > 0){
            $query="UPDATE CustomerPayment SET QBTransferStatus=1 WHERE CustomerPaymentId = :paymentId";
            $updateStatement=$con_ho->prepare($query);
            $updateStatement->execute(array(
               ':paymentId'=> $paymentId
            ));

            continue;
         }

         $accDepositedTo = "";
         $bankQuery = "SELECT QBBankName FROM Bank WHERE BankId = :bankId";
         $bankStatement = $con_ho->prepare($bankQuery);
         $bankStatement->execute(array(
            ':bankId'=> $bankId
         ));
         $bankResults=$bankStatement->fetchAll();
         foreach($bankResults as $bankRow){
            $accDepositedTo = $bankRow[0];
         }
        
         $qbCustName = "";
         $customerQuery = "SELECT ClientName, Country, ClientCode, CurrencyCode, QBCustomerNameAAA FROM Client WHERE ClientId = :custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute(array(
            ':custId'=> $custId
         ));
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $currency = $customerRow[3];
            $qbCustName = $customerRow[4];
            $arAcc = "Accounts Receivable - $currency"; 
         }

         if(!empty($qbCustName)){
            $insertQbPayments = "INSERT INTO qb_receivepayment (TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, RefNumber, TotalAmount, Memo, DepositToAccount_FullName) 
            VALUES(:txnID, :TimeCreated, :qbCustName, :arAcc, :paymentDate, :refNumber, :amount, :memo, :accDepositedTo);";
            $insertQbPaymentStatement=$con_quickbooks->prepare($insertQbPayments);
            $insertQbPaymentResult=$insertQbPaymentStatement->execute(array(':txnID' => $txnID, ':TimeCreated' => $timecreated, ':qbCustName' => $qbCustName, ':arAcc' => $arAcc,
            ':paymentDate' => $paymentDate, ':refNumber' => $paymentId, ':amount' => $amount, ':memo' => $memo, ':accDepositedTo' => $accDepositedTo));

            $paymentlastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $paymentqueue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $paymentqueue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testrosesaaa');
            $paymentqueue->enqueue(QUICKBOOKS_ADD_RECEIVEPAYMENT, $paymentlastid, 903);

            $custPayLineQuery = "SELECT InvoiceHeaderId, Amount FROM CustomerPaymentLine WHERE CustomerPaymentId = :txnID";
            $custPayLineStatement = $con_ho->prepare($custPayLineQuery);
            $custPayLineStatement->execute(array(
               ':txnID'=> $txnID
            ));
            $custPayLineResults=$custPayLineStatement->fetchAll();
            foreach($custPayLineResults as $custPayLineRow){
               $invoiceHeaderId = $custPayLineRow[0];
               $lineAmount = $custPayLineRow[1];

               $invoiceHeaderQuery = "SELECT InvoiceNo FROM  InvoiceHeader WHERE InvoiceHeaderId = :invoiceHeaderId";
               $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
               $invoiceHeaderStatement->execute(array(
                  ':invoiceHeaderId'=> $invoiceHeaderId
               ));
               $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
               foreach($invoiceHeaderResults as $invoiceHeaderRow){
                  $invoiceNo = $invoiceHeaderRow[0];
               }

               $qbInvoiceQuery = "SELECT TxnID FROM qb_invoice WHERE RefNumber = :invoiceNo;";
               $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
               $qbInvoiceStatement->execute(array(
                  ':invoiceNo'=> $invoiceNo
               ));
               $qbInvoiceResults=$qbInvoiceStatement->fetchAll();
               foreach($qbInvoiceResults as $qbInvoiceRow){
                  $toTxnID = $qbInvoiceRow[0];
               }

               $inserPaymentQuery = "INSERT INTO qb_receivepayment_appliedtotxn (FromTxnID, ReceivePayment_TxnID, ToTxnID, TxnType, TxnDate, RefNumber, BalanceRemaining, Amount) 
               VALUES(:FromTxnID, :ReceivePayment_TxnID, :ToTxnID, :type, :paymentDate, :invoiceNo,:BalanceRemaining, :Amount);";
               $inserPaymentStatement=$con_quickbooks->prepare($inserPaymentQuery);
               $inserPaymentStatement->execute(array(':FromTxnID' => $txnID, ':ReceivePayment_TxnID' => $txnID, ':ToTxnID' => $toTxnID, 
               ':type' => "Invoice", ':paymentDate' => $paymentDate, ':invoiceNo' => $invoiceNo,':BalanceRemaining' => 0, ':Amount' => $lineAmount));
            }

            $paymentQbStatusUpdate="UPDATE CustomerPayment SET QBTransferStatus = 1 WHERE CustomerPaymentId = :paymentId;";
            $paymentQbStatusUpdateStatement= $con_ho->prepare($paymentQbStatusUpdate);
            $paymentQbStatusUpdateStatement->execute(array(
               ':paymentId'=> $paymentId
            ));
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Payments Synched successfully';

      echo json_encode($response);
   }
   if($_GET["action"] === 'getRmAaaPayments'){
      $results["items"] = array();
      $qbQuery = "SELECT Customer_FullName, RefNumber, ARAccount_FullName, TxnDate, qbsql_last_errmsg, TimeCreated FROM qb_receivepayment WHERE qbsql_last_errmsg IS NOT NULL ORDER BY RefNumber DESC;";
      $qbStatement = $con_quickbooks->prepare($qbQuery);
      $qbStatement->execute();
      $qbResults=$qbStatement->fetchAll();
      foreach($qbResults as $row){
         $item = new stdClass();
         $item->customer = $row[0];
         $item->refNo = $row[1];
         $item->accountRecievable = $row[2];
         $item->date = $row[3];
         $item->error = $row[4];
         $item->timeCreated = $row[5];

         array_push($results["items"], $item);
      }

      $stagedCountQuery = "SELECT COUNT(*) FROM qb_receivepayment WHERE TimeModified IS NULL AND qbsql_last_errmsg IS NULL;";
      $stagedCountStatement = $con_quickbooks->prepare($stagedCountQuery);
      $stagedCountStatement->execute();
      $stagedCount = $stagedCountStatement->fetchColumn();
      $results["stagedCount"] = $stagedCount;

      $unsynchedCountQuery = "SELECT COUNT(*) FROM CustomerPayment WHERE ExporterId = 24 AND QBTransferStatus IS NULL AND PaymentDate Between #6/1/2025# AND #12/31/2026#";
      $unsynchedCountStatement = $con_ho->prepare($unsynchedCountQuery);
      $unsynchedCountStatement->execute();
      $unsynchedCount = $unsynchedCountStatement->fetchColumn();
      $results["unsynchedCount"] = $unsynchedCount;

      $output = new stdClass();
      $output->success = true;
      $output->message = "Successfull";
      $output->data = $results;
     
      echo json_encode($output);
   }
?>
