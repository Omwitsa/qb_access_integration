<?php
   include 'access.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'syncVegCreditNotes'){
      require_once '../../../../configs/2025/veg/aaatest/quickbooks.php';

      $creditNoteQuery = "SELECT CreditNoteId, CreditNoteNo, CreditNoteDate, CreditNoteValue, CustomerId, Notes FROM CreditNote WHERE ExporterId = 1 AND CreditNoteDate Between #9/22/2025# And #12/31/2026#  ORDER BY CreditNoteId";
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

         $claimHeaderQuery = "SELECT ClaimHeaderId, ReferenceNo, InvoiceHeaderId FROM ClaimHeader WHERE CreditNoteId = :creditNoteId";
         $claimHeaderStatement = $con_ho->prepare($claimHeaderQuery);
         $claimHeaderStatement->execute(array(
            ':creditNoteId'=> $creditNoteId
         ));
         $claimHeaderResults=$claimHeaderStatement->fetchAll();
         foreach($claimHeaderResults as $claimHeaderRow){
            $claimHeaderId = $claimHeaderRow[0];
         }
         
         $refNo = $creditNoteNo;
         if(isset($refNo)){
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
         $customerQuery = "SELECT CustomerName, CountryId, CustomerCode, CustomerFullName, CurrencyCode, QBCustomerNameAAA, FinalInvoiceType FROM Customer WHERE CustomerId = :custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute(array(
            ':custId'=> $custId
         ));
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $custCountryId = $customerRow[1];
            $currency = $customerRow[4];
            $qbCustName = $customerRow[5];
            $arAcc = "Accounts Receivable - $currency"; 
         }
         
         $itemtax = $custCountryId === 7 ? 'Z' : 'E';
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
            $creditNotequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testvegaaa2025');
            $creditNotequeue->enqueue(QUICKBOOKS_ADD_CREDITMEMO, $creditNotelastid, 903);

            $claimLineQuery = "SELECT ProductId, QtyClaim, PriceInvoice, LineValueClaim FROM ClaimLine WHERE ClaimHeaderId = :claimHeaderId";
            $claimLineStatement = $con_ho->prepare($claimLineQuery);
            $claimLineStatement->execute(array(
               ':claimHeaderId'=> $claimHeaderId
            ));
            $claimLineResults=$claimLineStatement->fetchAll();
            foreach($claimLineResults as $claimLineRow){
               $productId=$claimLineRow[0] ? $claimLineRow[0] : 0;
               $quantity=$claimLineRow[1];
               $rate=$claimLineRow[2];
               $lineAmount=$claimLineRow[3];

               $custCategoryId = 0;
               $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId FROM Product WHERE ProductId = :productId";
               $productStatement = $con_gen->prepare($productQuery);
               $productStatement->execute(array(
                  ':productId'=> $productId
               ));
               $productResults=$productStatement->fetchAll();
               foreach($productResults as $productRow){
                  $descrip=$productRow[2]."".$productRow[7]; // Credit Notes, Credit note flowers
                  $custCategoryId = $productRow[9] ? $productRow[9] : 0;
                  $netweightkg= $productRow[6];
                  $subitem=str_replace(" ","",substr($productRow[2], 0, 29))."".$productRow[7];
               }

               $itemfullname = "Veggetables"; // Flowers, Roses
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
   if($_GET["action"] === 'getVegaaaCreditNotesStats'){
      $results["items"] = array();
      $qbInvoicesQuery = "SELECT Customer_FullName, RefNumber, ARAccount_FullName, TxnDate, qbsql_last_errmsg, TimeCreated FROM qb_creditmemo WHERE qbsql_last_errmsg IS NOT NULL ORDER BY RefNumber DESC;";
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

      $unsynchedCountQuery = "SELECT COUNT(*) FROM CreditNote WHERE ExporterId = 1 AND QBTransferStatus IS NULL AND CreditNoteDate Between #7/12/2025# And #12/31/2026#";
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