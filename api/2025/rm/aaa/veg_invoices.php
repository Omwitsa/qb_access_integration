<?php
   include 'access.php';
   include 'functions.php';
   require_once '../../../../VegaaaCofigsTest/quickbooks.php';

   $timecreated=date("Y-m-d h:i:sa");
   if($_GET["action"] === 'synchVegaaaInvoice'){
      $invoiceNo = trim($_GET["invoiceNo"]);
      $invoiceHeaderQuery = "SELECT InvoiceHeaderId, CustomerId, InvoiceDate, InvoiceNo, ShippingTerms, FlightDate, QBInvoiceNo, Ref FROM InvoiceHeader WHERE InvoiceNo = '$invoiceNo';";
      $invoiceHeaderStatement = $con_ho->prepare($invoiceHeaderQuery);
      $invoiceHeaderStatement->execute();
      $invoiceHeaderResults=$invoiceHeaderStatement->fetchAll();
      foreach($invoiceHeaderResults as $invoiceHeaderRow){
         $invoiceHeaderId = $invoiceHeaderRow[0];
         $txnID = $invoiceHeaderId ; // $row[0].$invoicelinecountc.$invoicelinecountc,
         $custId = $invoiceHeaderRow[1];
         $invoiceDate = $invoiceHeaderRow[2];
         $poNo = $invoiceHeaderRow[7];
         $dueDate = $invoiceDate;
         $shipDate = $invoiceHeaderRow[5];

         $qbInvoiceQuery = "SELECT TxnID FROM qb_invoice WHERE TxnID = '$txnID';";
         $qbInvoiceStatement = $con_quickbooks->prepare($qbInvoiceQuery);
         $qbInvoiceStatement->execute();
         $qbInvoiceRows = $qbInvoiceStatement->rowCount();
         if($qbInvoiceRows > 0){
            continue;
         }

         $customerQuery = "SELECT CustomerName, CountryId, CustomerCode, CustomerFullName, CurrencyCode, QBCustomerNameAAA, FinalInvoiceType FROM Customer WHERE CustomerId = $custId";
         $customerStatement = $con_gen->prepare($customerQuery);
         $customerStatement->execute();
         $customerResults=$customerStatement->fetchAll();
         foreach($customerResults as $customerRow){
            $currency = $customerRow[4];
            $qbCustName = $customerRow[5];
            $custCountryId = $customerRow[1];
            $currencyName = getCurrencyFullName($currency);
            $arAcc = "Accounts Receivable - $currency"; 
         }

         $flamingoproducelimited ='BB - Flamingo Produce UK Ltd';
         $template = strtoupper($qbCustName) === strtoupper($flamingoproducelimited) ? 'FUK Invoice' : 'EUR Invoice';
         $itemtax = $custCountryId === 7 ? 'Z' : 'E';

         $insertQuickbooks = "INSERT INTO qb_invoice(TxnID, Customer_FullName, ARAccount_FullName, TxnDate, Template_FullName, RefNumber, PONumber, Terms_FullName, DueDate, ShipDate, ItemSalesTax_FullName, Currency_FullName) 
         VALUES('$txnID', '$qbCustName', '$arAcc', '$invoiceDate', '$template', '$invoiceNo', '$poNo', '', '$dueDate', '$shipDate', '$itemtax', '$currencyName');";

         $insertQbInvoiceStatement=$con_quickbooks->prepare($insertQuickbooks);
         $insertQbInvoiceResult=$insertQbInvoiceStatement->execute();

         $dbConnectionString = "$mysql_username:$mysql_password@$mysql_servername:$mysql_port/$mysql_dbname";
         $invoicelastid = $con_quickbooks->lastInsertId();
         $invoicequeue = new QuickBooks_WebConnector_Queue('mysqli://'. $dbConnectionString);
         $invoicequeue->enqueue(QUICKBOOKS_ADD_INVOICE, $invoicelastid, 903); 

         // $productTypeQuery = "SELECT ProductTypeName FROM ProductType WHERE ProductTypeId = $bankId"; // $rowd[4]
         // $productTypeStatement = $con_ho->prepare($productTypeQuery);
         // $productTypeStatement->execute();
         // $productTypeResults=$productTypeStatement->fetchAll();
         // foreach($productTypeResults as $productTypeRow){
         //    $productTypeName = $productTypeRow[0];
         // }

         $invoiceLines = array();
         $invoiceLineQuery = "SELECT InvoiceLineId, ProductId, BoxCount, BoxQty, Price, LineValue, LabFL, LabBL, LabPL, CustomerBranchId FROM InvoiceLine WHERE InvoiceHeaderId = $invoiceHeaderId"; 
         $invoiceLineStatement = $con_ho->prepare($invoiceLineQuery);
         $invoiceLineStatement->execute();
         $invoiceLineResults=$invoiceLineStatement->fetchAll();
         $totalCartons = 0;
         $totalWeight = 0;
         foreach($invoiceLineResults as $invoiceLineRow){
            $productId=$invoiceLineRow[1];
            $quantity=$invoiceLineRow[3];
            $unitrate = $quantity>0 ? $invoiceLineRow[5]/$quantity : 0;
            $rate = round($unitrate,2);
            $amount = round($invoiceLineRow[5],2);
            $taxName = 'Tax';

            $productQuery = "SELECT ProductId, ProductCode, ProductName, ProductCode2, ProductTypeId, CustomerId, NetPackWtKg, BoxCount, Price, ClientCategoryId FROM Product WHERE ProductId = $productId";
            $productStatement = $con_gen->prepare($productQuery);
            $productStatement->execute();
            $productResults=$productStatement->fetchAll();
            foreach($productResults as $productRow){
               $descrip=$productRow[2]."".$productRow[7];
               $custCategoryId=$productRow[9];
               $netweightkg= $productRow[6];
               $subitem=str_replace(" ","",substr($productRow[2], 0, 29))."".$productRow[7];
            }

            $custCategoryQuery = "SELECT CustomerCategoryName FROM CustomerCategory WHERE CustomerCategoryId = $custCategoryId";
            $custCategoryStatement = $con_gen->prepare($custCategoryQuery);
            $custCategoryStatement->execute();
            $custCategoryResults=$custCategoryStatement->fetchAll();
            foreach($custCategoryResults as $custCategoryRow){
               $custCategoryName = $custCategoryRow[0];
               $flamingoitems='Mini'.'-'.$custCategoryName;
            }
         
            $itemfullname=$flamingoitems.":".$subitem;

            $lineWeight = $netweightkg * $invoiceLineRow[2] * $quantity;
            $other1 = $lineWeight.'Kgs net';
            array_push($invoiceLines, "('$txnID', '$itemfullname', '$descrip', '$quantity', '$rate', '$amount', '$taxName', '$other1')");

            $totalCartons += $quantity;
            $totalWeight += $lineWeight;
         }

         $strInvoiceLines= implode(',', $invoiceLines);
         if($strInvoiceLines){
            $inserInvoiceQuery = "INSERT INTO qb_invoice_invoiceline(Invoice_TxnID, Item_FullName, Descrip, Quantity, Rate, Amount, SalesTaxCode_FullName, Other1) VALUES $strInvoiceLines;";
            $insertInvoiceStatement=$con_quickbooks->prepare($inserInvoiceQuery);
            $insertInvoiceStatement->execute();
         }

         $invoiceHeaderUpdateQuery="UPDATE qb_invoice SET FOB = '$totalCartons Cartons', Other = '$totalWeight Kgs net' WHERE TxnID = '$txnID'";
         $invoiceHeaderUpdateStatement= $con_ho->prepare($invoiceHeaderUpdateQuery);
         $invoiceHeaderUpdateStatement->execute();
      }

      $response = new stdClass();
      $response->success = true;
      $response->data = '';
      $response->message = 'Invoice Synched successfully';

      echo json_encode($response);
   }

?>
