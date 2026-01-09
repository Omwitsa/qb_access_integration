<?php
   include 'access.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchVegInvoice'){
      include 'customers.php';
      include 'items.php';
      include 'functions.php';
      require_once '../../../../configs/2025/veg/fgtest/quickbooks.php';
      // $invoiceNo = trim($_GET["invoiceNo"]);
      $flamingoproducelimited='2BB - Flamingo Produce UK Ltd';

      $invoiceHeaderQuery = "SELECT InvoiceHeaderId, CustomerId, InvoiceDate, InvoiceNo, ShippingTerms, FlightDate, QBInvoiceNo, Ref FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 2 AND InvoiceDate Between #1/1/2026# AND #31/12/2026# ORDER BY InvoiceHeaderId";
      $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
      $invoiceHeaderStatement->execute();
      $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
      foreach($invoiceHeaderResults as $invoiceHeaderRow){
         $invoiceHeaderId = $invoiceHeaderRow[0];
         $txnID = $invoiceHeaderId ;
         $invoiceCustId = $invoiceHeaderRow[1];
         $invoiceDate = $invoiceHeaderRow[2];
         $invoiceNo = $invoiceHeaderRow[3];
         $shippingTerms = $invoiceHeaderRow[4];
         $flightDate = $invoiceHeaderRow[5];
         $QBInvoiceNo = $invoiceHeaderRow[6];
         $ref = $invoiceHeaderRow[7];

         $invoiceNo = trim($invoiceNo);
         $qbInvoiceQuery = "SELECT RefNumber FROM qb_invoice WHERE RefNumber = :invoiceNo;";
         $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
         $qbInvoiceStatement->execute(array(
            ':invoiceNo'=> $invoiceNo
         ));
         $qbInvoiceRows = $qbInvoiceStatement->rowCount();
         if($qbInvoiceRows > 0){
            $updateInvoiceQuery="UPDATE InvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE InvoiceHeaderId = :InvoiceHeaderId";
            $updateInvoiceStatement=$con_ho->prepare($updateInvoiceQuery);
            $updateInvoiceStatement->execute(array(
               ':InvoiceHeaderId'=> $invoiceHeaderId,
               ':QBTransferStatus'=> 1
            ));

            continue;
         }

         $customerCode = "";
         $currency = "";
         $customerQuery = "SELECT CustomerName, CountryId, CustomerCode, CustomerFullName, CurrencyCode, QBCustomerNameFG, FinalInvoiceType FROM Customer WHERE CustomerId = :customerId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute(array(
            ':customerId'=> $invoiceCustId
         ));
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $custCountryId = $customerRow[1];
            $customerCode = $customerRow[2];
            $customerFullName = $customerRow[3];
            $customerFullName = isset($customerFullName) ? trim($customerFullName) : $customerFullName;
            $currency = $customerRow[4];
            $qbCustName = $customerRow[5];
            $finalInvoiceType = $customerRow[6];
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
            $template='EUR Invoice';
            $itemtax = $custCountryId == 7 ? 'VAT Zero Rate' : 'VAT Exempt';
            // $template = strtoupper($qbCustName) === strtoupper($flamingoproducelimited) ? 'FUK Invoice' : 'EUR Invoice';
            // $itemtax = $custCountryId === 7 ? 'Z' : 'E';

            $insertQuickbooks = 'INSERT INTO qb_invoice(TxnID, TimeCreated, Customer_FullName, ARAccount_FullName, TxnDate, Template_FullName, RefNumber, PONumber, ShipDate, ItemSalesTax_FullName, Currency_FullName, ExchangeRate) 
            VALUES(:txnID, :timeCreated, :qbCustName, :arAcc, :invoiceDate, :template_FullName, :invoiceNo, :qBInvoiceNo, :shipDate, :itemSalesTax_FullName, :currencyName, :exchangeRate);';
            $insertQbInvoiceStatement=$con_quickbooks->prepare($insertQuickbooks);
            $insertQbInvoiceStatement->execute(array(
               ':txnID'=> $txnID,
               ':timeCreated' => $timecreated,
               ':qbCustName' => $qbCustName,
               ':arAcc' => $arAcc,
               ':invoiceDate' => $invoiceDate,
               ':template_FullName' => $template,
               ':invoiceNo' => $invoiceNo,
               ':qBInvoiceNo' => $QBInvoiceNo,
               ':shipDate' => $flightDate,
               ':itemSalesTax_FullName' => $itemtax,
               ':currencyName' => $currencyName,
               ':exchangeRate' => $exchangeRate
            ));

            $invoicelastid = $con_quickbooks->lastInsertId();
            // $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
            // $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
            $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://IT_ADMIN:sysadmin2018@192.168.1.170:3306/testvegfg2025');
            $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903);

            $invoiceLineQuery = "SELECT InvoiceLineId, ProductId, BoxCount, BoxQty, Price, LineValue, LabFL, LabBL, LabPL, CustomerBranchId FROM InvoiceLine WHERE InvoiceHeaderId = :invoiceHeaderId"; 
            $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
            $invoiceLineStatement->execute(array(
               ':invoiceHeaderId'=> $invoiceHeaderId
            ));
            $invoiceLineResults=$invoiceLineStatement->fetchAll();
            $totalCartons = 0;
            $totalWeight = 0;
            foreach($invoiceLineResults as $invoiceLineRow){
               $productId=$invoiceLineRow[1];
               $boxCount=$invoiceLineRow[2];
               $quantity=$invoiceLineRow[3];
               $lineValue=$invoiceLineRow[5];
               $labFL=$invoiceLineRow[6];
               $labBL=$invoiceLineRow[7];
               $labPL=$invoiceLineRow[8];
               $customerBranchId=$invoiceLineRow[9];
               $unitrate = $quantity > 0 ? $lineValue/$quantity : 0;
               $rate = round($unitrate,2);
               $amount = round($invoiceLineRow[5],2);
               $taxName = 'Tax';

               $labels = strtoupper($qbCustName) == strtoupper($flamingoproducelimited) ? $labFL.$labBL.$labPL : null;
               insertItem($productId,$con_gen, $con_quickbooks, $timecreated);
               
               $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId FROM Product WHERE ProductId = :productId";
               $productStatement = $con_gen->prepare($productQuery);
               $productStatement->execute(array(
                  ':productId'=> $productId
               ));
               $productResults=$productStatement->fetchAll();
               foreach($productResults as $productRow){
                  $productCode = $productRow[1];
                  $productName = $productRow[2];  
                  $productCode2 = $productRow[3];
                  $productTypeId = $productRow[4];
                  $productCustomerId = $productRow[5];
                  $netweightkg= $productRow[6];
                  $productBoxCount = $productRow[7];
                  $custCategoryId=$productRow[9];

                  $productName = str_replace(" ", "", substr($productName, 0, 29));
                  $descrip = $productCode2."-".$productName."x".$productBoxCount;
                  $descrip = $productBoxCount < 1 ? $productCode2."-".$productName."".$productBoxCount : $descrip;
                  if(strlen($productCode2) < 1){
                     $descrip = $productName."x".$productBoxCount;
                     $descrip = $productBoxCount < 1 ? $productName."".$productBoxCount : $descrip;
                  }

                  $subitem = substr(str_replace(" ", "", $descrip), 0, 31);
                  if($productCustomerId == 0) {
                     $custCategoryQuery = "SELECT CustomerCategoryName FROM CustomerCategory WHERE CustomerCategoryId = :custCategoryId";
                     $custCategoryStatement = $con_gen->prepare($custCategoryQuery);
                     $custCategoryStatement->execute(array(
                        ':custCategoryId' => $custCategoryId
                        ));
                     $custCategoryResults=$custCategoryStatement->fetchAll();
                     foreach($custCategoryResults as $custCategoryRow){
                        $custCategoryName = $custCategoryRow[0];
                        if(strlen($custCategoryName) > 0){
                           $custCategoryName = trim($custCategoryName);
                           $flamingoitems = substr('Mini'.'-'.$custCategoryName, 0, 31);
                           $itemfullname = $flamingoitems.":".$subitem;
                        }
                     }
                  }
                  else{
                     $productTypeName = "";
                     $productTypeQuery = "SELECT ProductTypeName FROM ProductType WHERE ProductTypeId = :productTypeId";
                     $productTypeStatement = $con_gen->prepare($productTypeQuery);
                     $productTypeStatement->execute(array(
                        ':productTypeId' => $productTypeId
                        ));
                     $productTypeResults=$productTypeStatement->fetchAll();
                     foreach($productTypeResults as $productTypeRow){
                        $productTypeName = $productTypeRow[0];
                        $productTypeName = isset($productTypeName) ? trim($productTypeName) : $productTypeName;
                        $flamingoitems = $customerCode." ".$productTypeName;
                        $flamingoitems = strlen($productTypeName) < 1 ? $customerCode : $flamingoitems;
                        $flamingoitems = substr($customerCode, 0, 31);
                        $itemfullname = substr($customerFullName, 0, 31).":".$flamingoitems.":".$subitem;

                        if(strtoupper(substr($customerCode, 0, 31)) == 'AL'){
                           $itemfullname = 'Flamingo Produce Ltd'.":".$flamingoitems.":".$productCode.$label;
                        }
                     }
                  }
               }
               
               $lineWeight = $netweightkg * $boxCount * $quantity;
               $other1 = $lineWeight.'Kgs net'; 

               if(date('Y', strtotime($timecreated)) === "2026"){
                  $itemfullname = "VEGETABLES";
               }

               $inserInvoiceQuery = 'INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate, Amount, SalesTaxCode_FullName, Other1) 
               VALUES(:Invoice_TxnID, :Item_FullName, :Descrip, :Quantity, :Rate, :Amount, :SalesTaxCode_FullName, :Other1);';
               $insertInvoiceLineStatement=$con_quickbooks->prepare($inserInvoiceQuery);
               $insertInvoiceLineStatement->execute(array(
                  ':Invoice_TxnID'=> $txnID,
                  ':Item_FullName' => $itemfullname,
                  ':Descrip' => $subitem,
                  ':Quantity' => $quantity,
                  ':Rate' => $rate,
                  ':Amount' => $amount,
                  ':SalesTaxCode_FullName' => $taxName,
                  ':Other1' => $other1
               ));

               $totalCartons += $quantity;
               $totalWeight += $lineWeight;
            }
            
            $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = :FOB, Other = :Other WHERE TxnID = :txnID";
            $invoiceHeaderUpdateStatement= $con_ho->prepare($invoiceHeaderUpdateQuery);
            $invoiceHeaderUpdateStatement->execute(array(
               ':FOB'=> "$totalCartons Cartons",
               ':Other'=> "$totalWeight Kgs net",
               ':txnID'=> $txnID
            ));

            $updateInvoiceQuery="UPDATE InvoiceHeader SET QBTransferStatus = :QBTransferStatus WHERE InvoiceHeaderId = :InvoiceHeaderId";
            $updateInvoiceStatement=$con_ho->prepare($updateInvoiceQuery);
            $updateInvoiceStatement->execute(array(
               ':InvoiceHeaderId'=> $invoiceHeaderId,
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
   if($_GET["action"] === 'getVegFGInvoicesStats'){
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

      $unsynchedInvoiceCountQuery = "SELECT COUNT(*) FROM InvoiceHeader WHERE Finalized = Yes AND ExporterId = 2 AND  QBTransferStatus = 0 AND InvoiceDate Between #1/1/2026# AND #31/12/2026#";
      $unsynchedInvoiceCountStatement = $con_ho->prepare($unsynchedInvoiceCountQuery);
      $unsynchedInvoiceCountStatement->execute();
      $unsynchedInvoiceCount = $unsynchedInvoiceCountStatement->fetchColumn();
      $results["unsynchedInvoiceCount"] = $unsynchedInvoiceCount;

      $output = new stdClass();
      $output->success = true;
      $output->message = "Successfull";
      $output->data = $results;
     
      echo json_encode($output);
   }
?>
