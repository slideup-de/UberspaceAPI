<?php

/**
 * @author Benjamin Scheide <benni.s@slideup.de>
 * @author lindesbs <lindesbs@googlemail.com>
 * @link https://github.com/slideup-de/UberspaceAPI
 * 
 * Forked from https://github.com/lindesbs/UberspaceAccountInfo
 *
 * Diese Klasse ermöglicht den Zugriff auf die Account-Daten von Uberspace. Es ist außerdem möglich Proforma-Rechnungen zu erstellen und herunterzuladen
 */
class UberspaceAPI
{

    private $ch, $strCookieFile, $sessionid;
    private $version = "1.0";
    private $connected = false;

    public function __construct($username, $password)
    {
        $this->strCookieFile = "cookie_" . time() . ".txt";
        libxml_use_internal_errors(true);
        setlocale(LC_ALL, 'de_DE');
        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_POST, 1);
        curl_setopt($this->ch, CURLOPT_USERAGENT, "UberspaceAPI");
        curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->strCookieFile);
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->strCookieFile);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 0);
        $strUrl = 'https://uberspace.de/login';
        $strData = sprintf("login=%s&password=%s&submit=anmelden", urlencode($username), urlencode($password));
        curl_setopt($this->ch, CURLOPT_URL, $strUrl);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $strData);
    }

    private function connect()
    {
        if ($this->connected === false)
        {

            curl_setopt($this->ch, CURLOPT_HEADER, true);
            $res = curl_exec($this->ch);
            $this->sessionid = preg_replace('/.*?uberspace\_session\=([0-9abcdef]+).*/s', '$1', $res);
            curl_setopt($this->ch, CURLOPT_HEADER, false);

            $this->connected = true;
        }
    }

    private function execute($page, $postParams = array())
    {
        $postString = '';
        foreach ($postParams as $postParamKey => $postParamValue)
        {
            $postString .= ($postString == '' ? '' : '&') . $postParamKey . '=' . urlencode($postParamValue);
        }

        curl_setopt($this->ch, CURLOPT_URL, $page);


        if ($postString !== '')
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postString);

        $res = curl_exec($this->ch);
        return $res;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close()
    {
        curl_close($this->ch);
        if (file_exists($this->strCookieFile))
            unlink($this->strCookieFile);
        libxml_use_internal_errors(false);
    }

    /**
     * Erstellt eine neue Proformarechnung mit dem Betrag $amount und der Rechnungsadresse $billingAddress und gibt die ID zurück
     * @param float $amount
     * @param string $billingAddress
     * @return int
     */
    public function createInvoice($amount, $billingAddress)
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/dashboard/proforma_invoice',
            array(
            '_sessionid' => $this->sessionid,
            'recipient'  => $billingAddress,
            'amount'     => number_format($amount, 2, ',', '')
        ));

        $dom = new DOMDocument();
        $dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $refreshContent = $xpath->query('*/meta[@http-equiv="refresh"]')->item(0)->getAttribute('content');

        $invoiceId = preg_replace('/.*?get\_proforma\_invoice\/([0-9]+)/', '$1', $refreshContent);

        return $invoiceId;
    }

    /**
     * Gibt die Proformarechnung für die angegebene ID zurück. Falls die ID falsch ist, wird FALSE zurückgegeben
     * @param int $invoiceId
     * @return boolean|string
     */
    public function getInvoice($invoiceId)
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/dashboard/get_proforma_invoice/' . intval($invoiceId));

        if (preg_match('/^\%PDF/', $content) === 0)
            return false;
        return $content;
    }

    /**
     * Gibt die Quittung als PDF-Content für die angegebene ID zurück. Falls die ID falsch ist, wird FALSE zurückgegeben
     * @param int $receiptId
     * @return boolean|string
     */
    public function getReceipt($receiptId)
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/transaction/' . intval($receiptId) . '/receipt');

        if (preg_match('/^\%PDF/', $content) === 0)
            return false;
        return $content;
    }

    /**
     * Gibt alle vorhandenen Rechnungen als Array zurück.
     * @return array
     */
    public function getAllInvoices()
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/dashboard/accounting');

        $dom = new DOMDocument();
        $dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $invoiceTable = $xpath->query('*//table[@id="proforma_invoices"]')->item(0);
        if (!$invoiceTable)
            return array();

        $header = true;
        $invoices = array();
        foreach ($invoiceTable->childNodes as $tr)
        {
            if ($header == true)
            {
                $header = false;
                continue;
            }

            $invoices[] = array(
                'id'             => preg_replace('/.*?get\_proforma\_invoice\/([0-9]+)/', '$1', $tr->childNodes->item(2)->childNodes->item(0)->getAttribute('href')),
                'name'           => $tr->childNodes->item(2)->nodeValue,
                'create_date'    => strtotime($tr->childNodes->item(0)->nodeValue),
                'amount'         => floatval(str_replace(',', '.', $tr->childNodes->item(4)->nodeValue))
            );
        }

        return $invoices;
    }

    /**
     * Gibt alle Quittungen als array zurück. personalized bedeutet, dass die Quittung bereits die Rechnungsanschrift erhalten hat.
     * @return array
     */
    public function getAllReceipts()
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/dashboard/accounting');

        $dom = new DOMDocument();
        $dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $invoiceTable = $xpath->query('*//table[@id="transactions"]')->item(0);
        if (!$invoiceTable)
            return array();

        $receipts = array();
        foreach ($invoiceTable->childNodes as $tr)
        {
            $receiptLinkLst = $xpath->query('*/a[@href]', $tr);
            if ($receiptLinkLst->length == 0)
                continue;
            
            $receipt = array(
                'create_date'    => strtotime($tr->childNodes->item(0)->nodeValue),
                'amount'         => floatval(str_replace(',', '.', $tr->childNodes->item(4)->nodeValue))
            );
            
            if($receiptLinkLst->item(0)->getAttribute('href') == '#')
            {
                $receipt['id'] = preg_replace('/.*?transaction\-([0-9]+)\-receipt.*/', '$1', $receiptLinkLst->item(0)->getAttribute('onclick'));
                $receipt['personalized'] = false;
            } else {
                $receipt['id'] = preg_replace('/.*?transaction\/([0-9]+).*/', '$1', $receiptLinkLst->item(0)->getAttribute('href'));
                $receipt['personalized'] = true;
            }

            $receipts[] = $receipt;
        }

        return $receipts;
    }
    
    /**
     * Personalisiert die angegebene Quittung mit der angegeben Rechnungsanschrift. Umbrüche sind per \\r\\n hinzuzufügen
     * @param type $receiptid
     * @param type $billingAddress
     */
    public function personalizeReceipt($receiptid, $billingAddress)
    {
        $this->connect();       
        $this->execute('https://transaction/'.$receiptid.'/receipt', array(
            '_sessionid' => $this->sessionid,
            'recipient'  => $billingAddress,
        ));
        
    }

    /**
     * Gibt Statusinformationen zum Uberspace-Account zurück. Falls die Zugangsdaten nicht korrekt sind, wird FALSE zurückgegeben
     * @return boolean|array
     */
    public function getData()
    {
        $this->connect();
        $content = $this->execute('https://uberspace.de/dashboard/accountinfo?format=json');
        $arrData = json_decode(trim($content));

        if ($arrData ===
            null)
            return false;

        if ($this->floatval($arrData->price) == 0)
            $paid_until = strtotime('+1 month');
        else
            $paid_until = strtotime('+' . floor($this->floatval($arrData->current_amount) / $this->floatval($arrData->price)) + 1 . ' month');

        return array(
            'current_amount' => $this->floatval($arrData->current_amount),
            'price'          => $this->floatval($arrData->price),
            'domains_web'    => (array) $arrData->domains->web,
            'domains_mail'   => (array) $arrData->domains->mail,
            'host'           => $arrData->host->fqdn,
            'ipv4'           => $arrData->host->ipv4,
            'username'       => $arrData->login,
            'paid_until'     => mktime(0, 0, 0, date('n', $paid_until), 1, date('Y', $paid_until))
        );
    }

    private function floatval($strValue)
    {
        $floatValue = preg_replace("@(^[0-9]*)(\\.|,)([0-9]*)(.*)@", "\\1.\\3", $strValue);
        if (!is_numeric($floatValue))
            $floatValue = preg_replace("@(^[0-9]*)(.*)@", "\\1", $strValue);
        if (!is_numeric($floatValue))
            $floatValue = 0;
        return

            $floatValue;
    }

    public function getVersion()
    {
        return $this->version;
    }

}