<?php
    function getCurrencyFullName($currency){
        switch(strtoupper($currency))
        {
            case strtoupper('EUR'):
            $currencyfullname='Euro';
     
            break;
     
            case strtoupper('USD'):
            $currencyfullname='US Dollar';
             break;
     
            case strtoupper('GBP'):
            $currencyfullname='British Pound Sterling';
             break;
     
            default:
            $currencyfullname='Kenyan Shilling';
        }

        return $currencyfullname;
    }

   
?>