<?php
   include 'access.php';
   require_once '../../../../configs/2025/rm/aaa/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncRmCreditNotes'){
      $creditNoteQuery = "SELECT CreditNoteId, CreditNoteNo, CreditNoteDate, CreditNoteValue, ClientId, Notes FROM CreditNote WHERE CreditNoteDate Between #6/1/2025# And #12/31/2026#  ORDER BY CreditNoteId";
      $creditNoteStatement = $con_ho->prepare($creditNoteQuery);
      $creditNoteStatement->execute();
      $creditNoteResults=$creditNoteStatement->fetchAll();
      foreach($creditNoteResults as $creditNoteRow){
         $taxName = 'Tax';
         $creditNoteId = $creditNoteRow[0];
         $creditNoteNo = $creditNoteRow[1];
         $txnID = "$creditNoteId-$creditNoteNo";
         $creditNoteDate = $creditNoteRow[2];
         $amount = $creditNoteRow[3];
         $custId = $creditNoteRow[4];

         $refNo = "";
         $claimHeaderQuery = "SELECT ComplaintHeaderId, InvoiceHeaderId FROM ComplaintHeader WHERE CreditNoteId = $creditNoteId";
         $claimHeaderStatement = $con_ho->prepare($claimHeaderQuery);
         $claimHeaderStatement->execute();
         $claimHeaderResults=$claimHeaderStatement->fetchAll();
         foreach($claimHeaderResults as $claimHeaderRow){
            $claimHeaderId = $claimHeaderRow[0];
            $refNo = $claimHeaderRow[1];
         }
         
         $qbIdQuery = "SELECT RefNumber FROM qb_creditmemo WHERE RefNumber = '$refNo';";
         $qbIdStatement = $con_quickbooks->prepare($qbIdQuery);
         $qbIdStatement->execute();
         $qbIdRows = $qbIdStatement->rowCount();
         if($qbIdRows > 0){
            continue;
         }

         $customerQuery = "SELECT ClientName, Country, ClientCode, CurrencyCode, QBCustomerNameAAA FROM Client WHERE ExporterId = 24 AND ClientId = $custId";  // 25 -- FG
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute();
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $currency = $customerRow[3];
            $qbCustName = $customerRow[4];
            $arAcc = "Accounts Receivable - $currency"; 
         }
         
         // $itemtax = $custCountryId === 7 ? 'Z' : 'E';
         $itemtax = 'E';
         if(!empty($qbCustName)){
            $insertQbCreditNotes = "INSERT INTO qb_creditmemo(TxnID, TimeCreated, TimeModified, Customer_FullName, ARAccount_FullName, Template_FullName, TxnDate, RefNumber, DueDate, ShipDate, Subtotal, ItemSalesTax_FullName, TotalAmount, CreditRemaining, CustomerSalesTaxCode_FullName) 
            VALUES('$txnID', NOW(), NOW(), '$qbCustName', '$arAcc', 'Custom Credit Memo', '$creditNoteDate', '$refNo', '$creditNoteDate', '$creditNoteDate', $amount, '$itemtax', $amount, $amount, '$taxName');";
            $insertQbCreditNoteStatement=$con_quickbooks->prepare($insertQbCreditNotes);
            $insertQbCreditNoteResult=$insertQbCreditNoteStatement->execute();

            $creditNotelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $creditNotequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $creditNotequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/rosesaaa2025');
            $creditNotequeue->enqueue(QUICKBOOKS_ADD_CREDITMEMO, $creditNotelastid, 903);

            $creditNoteLines = array();
            $claimLineQuery = "SELECT VarietyId, StemQty, Price, LineValue, StemLength FROM ComplaintLine WHERE ComplaintHeaderId = $claimHeaderId";
            $claimLineStatement = $con_ho->prepare($claimLineQuery);
            $claimLineStatement->execute();
            $claimLineResults=$claimLineStatement->fetchAll();
            foreach($claimLineResults as $claimLineRow){
               $productId=$claimLineRow[0] ? $claimLineRow[0] : 0;
               $quantity=$claimLineRow[1] ? $claimLineRow[1] : 0;
               $rate=$claimLineRow[2] ? $claimLineRow[2] : 0;
               $lineAmount=$claimLineRow[3] ? $claimLineRow[3] : 0;
               $length=$claimLineRow[4];

               $custCategoryId = 0;
               $productQuery = "SELECT VarietyName FROM Variety WHERE VarietyId = $productId;";
               $productStatement = $con_gen->prepare($productQuery);
               $productStatement->execute();
               $productResults=$productStatement->fetchAll();
               foreach($productResults as $productRow){
                  $descrip=$productRow[0]."".$length; // Credit Notes, Credit note flowers
               }

               $itemfullname = "Roses";
               array_push($creditNoteLines, "('$txnID', '$itemfullname', '$descrip', $quantity, $rate, $lineAmount, '$taxName')");
            }

            $strCreditNoteLines = implode(',', $creditNoteLines);
            if($strCreditNoteLines){
               $insertCreditNoteQuery = "INSERT INTO qb_creditmemo_creditmemoline(CreditMemo_TxnID, Item_FullName, Descrip, Quantity, Rate, Amount, SalesTaxCode_FullName) VALUES $strCreditNoteLines;";
               $insertCreditNoteStatement=$con_quickbooks->prepare($insertCreditNoteQuery);
               $insertCreditNoteStatement->execute();
            }

            $paymentQbStatusUpdate="UPDATE CreditNote SET QBTransferStatus = 1 WHERE CreditNoteId = $creditNoteId;";
            $paymentQbStatusUpdateStatement= $con_ho->prepare($paymentQbStatusUpdate);
            $paymentQbStatusUpdateStatement->execute();
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Credit notes Synched successfully';

      echo json_encode($response);
   }
?>