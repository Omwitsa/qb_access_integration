<?php
   include 'access.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncRmCreditNotes'){
      require_once '../../../../configs/2025/rm/fgtest/quickbooks.php';

      $creditNoteQuery = "SELECT CreditNoteId, CreditNoteNo, CreditNoteDate, CreditNoteValue, ClientId, Notes FROM CreditNote WHERE ExporterId = 25 AND CreditNoteDate Between #9/4/2026# And #12/31/2026#  ORDER BY CreditNoteId";
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

         $refNo = $creditNoteNo;
         if(isset($refNo)){
            if(strlen($refNo) > 7){
               $refNo = str_replace("/", "", substr($refNo, 3));
            }
            
            $refNo = substr($refNo, 0, 11);
         }
         
         $qbIdQuery = "SELECT RefNumber FROM qb_creditmemo WHERE RefNumber = :refNo;";
         $qbIdStatement = $con_quickbooks->prepare($qbIdQuery);
         $qbIdStatement->execute(array(
            ':refNo'=> $refNo
         ));
         $qbIdRows = $qbIdStatement->rowCount();
         if($qbIdRows > 0){
            $query="UPDATE CreditNote SET QBTransferStatus = :QBTransferStatus WHERE CreditNoteId = :creditNoteId";
            $updateStatement=$con_ho->prepare($query);
            $updateStatement->execute(array(
               ':creditNoteId'=> $creditNoteId,
               ':QBTransferStatus'=> 1
            ));
            
            continue;
         }

         $qbCustName = "";
         $customerQuery = "SELECT ClientName, Country, ClientCode, CurrencyCode, QBCustomerName FROM Client WHERE ClientId = :custId";
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
         
         // $itemtax = $custCountryId === 7 ? 'Z' : 'E';
         $itemtax= 'VAT Exempt'; // VAT Zero Rate
         if(!empty($qbCustName)){
            $insertQbCreditNotes = "INSERT INTO qb_creditmemo(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, Template_FullName, TxnDate, RefNumber, DueDate, ShipDate, Subtotal, ItemSalesTax_FullName, TotalAmount, CreditRemaining, CustomerSalesTaxCode_FullName) 
            VALUES(:txnID, :TimeCreated, :qbCustName, :arAcc, :template, :date, :refNo, :DueDate, :ShipDate, :Subtotal, :ItemSalesTax_FullName, :TotalAmount, :CreditRemaining, :CustomerSalesTaxCode_FullName);";
            $insertQbCreditNoteStatement=$con_quickbooks->prepare($insertQbCreditNotes);
            $insertQbCreditNoteResult=$insertQbCreditNoteStatement->execute(array(':txnID' => $txnID, ':TimeCreated' => $timecreated, ':qbCustName' => $qbCustName, ':arAcc' => $arAcc,
            ':template' => 'Custom Credit Memo', ':date' => $creditNoteDate, ':refNo' => $refNo, ':DueDate' => $creditNoteDate, ':ShipDate' => $creditNoteDate, ':Subtotal' => $amount,
            ':ItemSalesTax_FullName' => $itemtax, ':TotalAmount' => $amount, ':CreditRemaining' => $amount, ':CustomerSalesTaxCode_FullName' => $taxName));

            $creditNotelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $creditNotequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $creditNotequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testrosesfg');
            $creditNotequeue->enqueue(QUICKBOOKS_ADD_CREDITMEMO, $creditNotelastid, 903);

            $lineQuery = "SELECT Price, Qty, LineValue, Description, InvoiceLineId FROM CreditNoteLine WHERE CreditNoteId =:creditNoteId;";
            $lineStatement = $con_ho->prepare($lineQuery);
            $lineStatement->execute(array(
               ':creditNoteId'=> $creditNoteId
            ));
            $lineResults=$lineStatement->fetchAll();
            foreach($lineResults as $lineRow){
               $rate=$lineRow[0];
               $quantity=$lineRow[1];
               $lineAmount=$lineRow[2];
               $descrip=$lineRow[3];

               $itemfullname = "Roses";
               $insertCreditNoteQuery = "INSERT INTO qb_creditmemo_creditmemoline(CreditMemo_TxnID, Item_FullName, Descrip, Quantity, Rate, Amount, SalesTaxCode_FullName) 
               VALUES(:txnID, :itemfullname, :descrip, :quantity, :rate, :Amount, :taxName);";
               $insertCreditNoteStatement=$con_quickbooks->prepare($insertCreditNoteQuery);
               $insertCreditNoteStatement->execute(array(':txnID' => $txnID, ':itemfullname' => $itemfullname, ':descrip' => $descrip, ':quantity' => $quantity,
               ':rate' => $rate, ':Amount' => $lineAmount, ':taxName' => $taxName));
            }

            $query="UPDATE CreditNote SET QBTransferStatus = :QBTransferStatus WHERE CreditNoteId = :creditNoteId";
            $updateStatement=$con_ho->prepare($query);
            $updateStatement->execute(array(
               ':creditNoteId'=> $creditNoteId,
               ':QBTransferStatus'=> 1
            ));
         }
      }

      $response = new stdClass();
      $response->success = true;
      $response->data =  '';
      $response->message = 'Credit notes Synched successfully';

      echo json_encode($response);
   }
   if($_GET["action"] === 'getRmFgCreditNotesStats'){
      $results["items"] = array();
      $qbInvoicesQuery = "SELECT Customer_FullName, RefNumber, ARAccount_FullName, TxnDate, qbsql_last_errmsg, TimeCreated FROM qb_creditmemo WHERE qbsql_last_errmsg IS NOT NULL ORDER BY RefNumber DESC LIMIT 20;";
      $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoicesQuery);
      $qbInvoiceStatement->execute();
      $invoicesResults=$qbInvoiceStatement->fetchAll();
      foreach($invoicesResults as $row){
         $item = new stdClass();
         $item->customer = $row[0];
         $item->refNo = $row[1];
         $item->accountRecievable = $row[2];
         $item->date = $row[3];
         $item->error = $row[4];
         $item->timeCreated = $row[5];

         array_push($results["items"], $item);
      }

      $stagedCountQuery = "SELECT COUNT(*) FROM qb_creditmemo WHERE TimeModified IS NULL AND qbsql_last_errmsg IS NULL;";
      $stagedCountStatement = $con_quickbooks->prepare($stagedCountQuery);
      $stagedCountStatement->execute();
      $stagedCount = $stagedCountStatement->fetchColumn();
      $results["stagedCount"] = $stagedCount;

      $unsynchedCountQuery = "SELECT COUNT(*) FROM CreditNote WHERE ExporterId = 25 AND QBTransferStatus IS NULL AND CreditNoteDate Between #9/4/2026# And #12/31/2026#";
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