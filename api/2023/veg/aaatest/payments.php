<?php
   include 'access.php';
   require_once '../../../../VegaaaCofigs2023Test/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncVegaaaPayments'){
      $custPaymentQuery = "SELECT CustomerPaymentId, CustomerId, PaymentDate, ForeignAmountPaid, BankId, Description FROM CustomerPayment WHERE PaymentDate  Between #19/5/2025# And #31/12/2026# AND (QBTransferStatus <> 1 OR QBTransferStatus IS NULL) ORDER BY CustomerPaymentId";
      $custPAymentStatement = $con_ho->prepare($custPaymentQuery);
      $custPAymentStatement->execute();
      $custPaymentResults=$custPAymentStatement->fetchAll();
      foreach($custPaymentResults as $custPaymentRow){
         $txnID = $custPaymentRow[0];
         $custId = $custPaymentRow[1];
         $paymentDate = $custPaymentRow[2];
         $amount = $custPaymentRow[3];
         $bankId = $custPaymentRow[4];

         $qbPaymentQuery = "SELECT TxnID FROM qb_receivepayment WHERE TxnID = '$txnID';";
         $qbPaymentStatement = $con_quickbooks->prepare($qbPaymentQuery);
         $qbPaymentStatement->execute();
         $qbPaymentRows = $qbPaymentStatement->rowCount();
         if($qbPaymentRows > 0){
            continue;
         }
         
         $bankQuery = "SELECT QBBankName FROM Bank WHERE BankId = $bankId";
         $bankStatement = $con_ho->prepare($bankQuery);
         $bankStatement->execute();
         $bankResults=$bankStatement->fetchAll();
         foreach($bankResults as $bankRow){
            $accDepositedTo = $bankRow[0];
         }

         $customerQuery = "SELECT CustomerName, CountryId, CustomerCode, CustomerFullName, CurrencyCode, QBCustomerNameAAA, FinalInvoiceType FROM Customer WHERE CustomerId = $custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute();
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $currency = $customerRow[4];
            $qbCustName = $customerRow[5];
            $arAcc = "Accounts Receivable - $currency"; 
         }

         if(!empty($qbCustName)){
            $insertQbPayments = "INSERT INTO qb_receivepayment (TxnID, TimeCreated, TimeModified, Customer_FullName, ARAccount_FullName, TxnDate, TotalAmount, DepositToAccount_FullName) 
            VALUES('$txnID', NOW(), NOW(),'$qbCustName','$arAcc', '$paymentDate', $amount, '$accDepositedTo');";
            $insertQbPaymentStatement=$con_quickbooks->prepare($insertQbPayments);
            $insertQbPaymentResult=$insertQbPaymentStatement->execute();

            $paymentlastid = $con_quickbooks->lastInsertId();
            $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            $paymentqueue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $paymentqueue->enqueue(QUICKBOOKS_ADD_RECEIVEPAYMENT, $paymentlastid, 903);

            $paymentLines = array();
            $custPayLineQuery = "SELECT InvoiceHeaderId, Amount FROM CustomerPaymentLine WHERE CustomerPaymentId = $txnID";
            $custPayLineStatement = $con_ho->prepare($custPayLineQuery);
            $custPayLineStatement->execute();
            $custPayLineResults=$custPayLineStatement->fetchAll();

            foreach($custPayLineResults as $custPayLineRow){
               $invoiceHeaderId = $custPayLineRow[0];
               $lineAmount = $custPayLineRow[1];

               $invoiceHeaderQuery = "SELECT InvoiceNo FROM  InvoiceHeader WHERE InvoiceHeaderId = $invoiceHeaderId";
               $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
               $invoiceHeaderStatement->execute();
               $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
               foreach($invoiceHeaderResults as $invoiceHeaderRow){
                  $invoiceNo = $invoiceHeaderRow[0];
               }

               $qbInvoiceQuery = "SELECT TxnID FROM qb_invoice WHERE RefNumber = '$invoiceNo';";
               $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
               $qbInvoiceStatement->execute();
               $qbInvoiceResults=$qbInvoiceStatement->fetchAll();
               foreach($qbInvoiceResults as $qbInvoiceRow){
                  $toTxnID = $qbInvoiceRow[0];
               }

               array_push($paymentLines, "('$txnID', '$txnID', '$toTxnID', 'Invoice', '$paymentDate', '$invoiceNo', 0, $lineAmount)");
            }

            $strPaymentLines = implode(',', $paymentLines);
            if($strPaymentLines){
               $inserPaymentQuery = "INSERT INTO qb_receivepayment_appliedtotxn (FromTxnID, ReceivePayment_TxnID, ToTxnID, TxnType, TxnDate, RefNumber, BalanceRemaining, Amount) VALUES $strPaymentLines;";
               $inserPaymentStatement=$con_quickbooks->prepare($inserPaymentQuery);
               $inserPaymentStatement->execute();
            }
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Payments Synched successfully';

      echo json_encode($response);
   }
?>
