<?php
/*
VistaPanel Users API library
Originally by @oddmario, maintained by @GenerateApps
*/
error_reporting(E_ERROR | E_PARSE);
class VistapanelApi
{
    
    private $cpanelUrl = "https://cpanel.byethost.com";
    private $loggedIn = false;
    private $vistapanelSession = "";
    private $vistapanelSessionName = "PHPSESSID";
    private $accountUsername = "";
    private $cookie = "";
    
    private function getLineWithString($content, $str)
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, $str) !== false) {
                return $line;
            }
        }
        return -1;
    }

    private function simpleCurl(
        $url = "",
        $post = false,
        $postfields = array(),
        $header = false,
        $httpheader = array(),
        $followlocation = false
    )
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        }
        if ($header) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt(
            $ch,
            CURLOPT_USERAGENT,
            'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13'
        );
        if ($followlocation) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function classError($error)
    {
       throw new Exception("VistapanelApi_Error: " . $error);
    }
    
    private function checkCpanelUrl()
    {
        if (empty($this->cpanelUrl)) {
            $this->classError("Please set cpanelUrl first.");
        }
        if (substr($this->cpanelUrl, -1) == "/") {
            $this->cpanelUrl = substr_replace($this->cpanelUrl, "", -1);
        }
        return true;
    }
    
    private function checkLogin()
    {
        $this->checkCpanelUrl();
        if (!$this->loggedIn) {
            $this->classError("Not logged in.");
        }
        return true;
    }

    private function checkForEmptyParams(...$params)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1];
        $caller = $trace['function'];
        $reflection = new ReflectionMethod($this, $caller);
        $parameters = $reflection->getParameters();
    
        foreach ($params as $index => $parameter) {
            if (empty($parameter)) {
                $this->classError($parameters[$index]->getName() . " is required.");
            }
        }
    }

    private function getToken()
    {
        $this->checkLogin();
        $homepage = $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php", false, array(), false, array(
            $this->cookie
        ));
        $json = $this->getLineWithString($homepage, "/panel\/indexpl.php?option=domains&ttt=");
        $json = substr_replace($json, "", -1);
        $json = json_decode($json, true);
        $url = $json['url'];
        return (int) filter_var($url, FILTER_SANITIZE_NUMBER_INT);
    }

    private function getTableElements($url = "", $id = "") {
        $this->checkLogin();
        $this->checkForEmptyParams($url);
        $htmlContent = $this->simpleCurl(
            $url,
            false,
            array(),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);
        libxml_clear_errors();
        if (empty($id)) {
            $header = $dom->getElementsByTagName('th');
            $detail = $dom->getElementsByTagName('td');
        } else {
            $header = $dom->getElementById($id)->getElementsByTagName('th');
            $detail = $dom->getElementById($id)->getElementsByTagName('td');
        }
        
        foreach ($header as $nodeHeader) {
            $aDataTableHeaderHTML[] = trim($nodeHeader->textContent);
        }
        $i = 0;
        $j = 0;
        foreach ($detail as $sNodeDetail) {
            $aDataTableDetailHTML[$j][] = trim($sNodeDetail->textContent);
            $i = $i + 1;
            $j = $i % count($aDataTableHeaderHTML) == 0 ? $j + 1 : $j;
        }
        for ($i = 0; $i < count($aDataTableDetailHTML); $i++) {
            for ($j = 0; $j < count($aDataTableHeaderHTML); $j++) {
                $aTempData[$i][$aDataTableHeaderHTML[$j]] = $aDataTableDetailHTML[$i][$j];
            }
        }
        return $aTempData;
    }

    public function setCpanelUrl($url = "") {
        $this->checkForEmptyParams($url);
        $this->cpanelUrl = $url;
        return true;
    }

    public function approveNotification()
    {
        $this->checkLogin();
        $this->simpleCurl($this->cpanelUrl . "/panel/approve.php", true, array("submit" => true), false, array(
            $this->cookie
        ));
        return true;
    }

    public function disapproveNotification() {
        $this->checkLogin();
        $this->simpleCurl($this->cpanelUrl . "/panel/disapprove.php", true, array("submit" => false), false, array(
            $this->cookie
        ));
        return true;
    }
    
    public function login($username = "", $password = "", $theme = "PaperLantern")
    {
        $this->checkCpanelUrl();
        $this->checkForEmptyParams($username, $password);
        $login = $this->simpleCurl($this->cpanelUrl . "/login.php", true, array(
            "uname" => $username,
            "passwd" => $password,
            "theme" => $theme,
            "seeesurf" => "567811917014474432"
        ), true, array(), true);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $login, $matches);
        $cookies = array();
        foreach ($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        if ($this->loggedIn === true) {
            $this->classError("You are already logged in.");
        }
        if (empty($cookies[$this->vistapanelSessionName])) {
            $this->classError("Unable to login.");
        }
        if (strpos($login, "document.location.href = 'panel/indexpl.php") === false) {
            $this->classError("Invalid login credentials.");
        }
        $this->loggedIn = true;
        $this->accountUsername = $username;
        $this->vistapanelSession = $cookies[$this->vistapanelSessionName];
        $this->cookie = "Cookie: " . $this->vistapanelSessionName . "=" . $this->vistapanelSession;
        $checkImportantNotice = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php",
            false,
            array(),
            false,
            array(
                $this->cookie
            )
        );
        if (!strpos(
            $checkImportantNotice,
            "To notify you of changes to service and offers we need permission to send you email")
        )
        {
            $this->approveNotification();
        }
        return true;
    }
    
    public function createDatabase($dbname = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($dbname);
        $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=mysql&cmd=create", true, array(
            "db" => $dbname
        ), false, array(
            $this->cookie
        ));
        return true;
    }

    public function listDatabases()
    {
        $databases = array();
        $aDataTableDetailHTML = $this->getTableElements($this->cpanelUrl . "/panel/indexpl.php?option=pma");
        foreach ($aDataTableDetailHTML as $database) {
            $databases[str_replace($this->accountUsername . "_", "", array_shift($database))] = true;
        }
        return $databases;
    }
    
    public function deleteDatabase($database = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($database);
        if (!in_array($database, $this->listDatabases())) {
            $this->classError("The database you're trying to remove doesn't exist.");
        }
        $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=mysql&cmd=remove", true, array(
            "toremove" => $this->accountUsername . "_" . $database,
            "Submit2" => "Remove Database"
        ), false, array(
            $this->cookie
        ));
        return true;
    }
    
    public function getPhpmyadminLink($database = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($database);
        if (!array_key_exists($database, $this->listDatabases())) {
            $this->classError("The database you're trying to get the PMA link of doesn't exist.");
        }
        $htmlContent = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=pma",
            false,
            array(),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);
        libxml_clear_errors();
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            if (strpos($link->getAttribute('href'), "&db=" . $this->accountUsername . "_" . $database) !== false) {
                return $link->getAttribute('href');
            }
        }
    }

    public function listDomains($option = "all")
    {
        /* Parses the domain table and returns all domains in a category.
         * Available options: "all", "addon", "sub" and "parked". Returns all domains if no parameter is passed.
         */
        $this->checkLogin();
        switch ($option) {
            case "sub":
                $option = "subdomains";
                $id = "subdomaintbl";
                break;
            case "parked":
                $option = "parked";
                $id = "parkeddomaintbl";
                break;
            case "addon":
                $option = "domains";
                $id = "subdomaintbl";
                break;
            default:
                $option = "ssl";
                $id = "sql_db_tbl";
                break;
        }
        $domains = array();
        $aDataTableDetailHTML = $this->getTableElements(
            $this->cpanelUrl . "/panel/indexpl.php?option={$option}&ttt=" . $this->getToken(),
            $id
        );
        foreach ($aDataTableDetailHTML as $domain) {
            $domains[array_shift($domain)] = true;
        }
        return $domains;
    }

    public function createRedirect($domainname = "", $target = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname, $target);
        $response = $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=redirect_add", true, array(
            "domain_name" => $domainname,
            "redirect_url" => $target

        ), false, array(
            $this->cookie
        ), true);
        if (strpos(
            $response,
            "The redirect url {$target} does not appear to be a URL (it MUST start with http:// or http:// ! )")
            !== false
        )
        {
            $this->classError(
                "The redirect url {$target} does not appear to be a URL. Make sure it starts with http:// or https://"
            );
        }
        return true;
    }

    public function deleteRedirect($domainname = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=redirect_rem&domain=" . $domainname . "&redirect_url=http://",
            true,
            array(),
            false,
            array($this->cookie)
        );
        return true;
    }
    
    public function getPrivateKey($domainname = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $htmlContent = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=sslconfigure&domain_name=" . $domainname,
            false,
            array(),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);

        $xpath = new DOMXPath($dom);

        $privatekeys = $xpath->query("//textarea[@name='key']");
        return $privatekeys->item(0)->nodeValue;
    }

    public function getCertificate($domainname = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $htmlContent = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=sslconfigure&domain_name=" . $domainname,
            false,
            array(),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);

        $xpath = new DOMXPath($dom);

        $certificates = $xpath->query("//textarea[@name='cert']");
        return $certificates->item(0)->nodeValue;
    }
    
    public function uploadPrivateKey($domainname = "", $key = "", $csr = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname, $key);
        $this->simpleCurl($this->cpanelUrl . "/panel/modules-new/sslconfigure/uploadkey.php", true, array(
            "domain_name" => $domainname,
            "csr" => $csr,
            "key" => $key
            
        ), false, array(
            $this->cookie
        ));
        return true;
    }

    public function uploadCertificate($domainname = "", $cert = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname, $cert);
        $this->simpleCurl($this->cpanelUrl . "/panel/modules-new/sslconfigure/uploadcert.php", true, array(
            "domain_name" => $domainname,
            "cert" => $cert
            
        ), false, array(
            $this->cookie
        ));
        return true;
    }

    public function deleteCertificate($domainname = "")
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $this->simpleCurl(
            $this->cpanelUrl . "/panel/modules-new/sslconfigure/deletecert.php" .
            "?domain_name=" . $domainname .
            "&username=" . $this->accountUsername,
            false,
            array(),
            false,
            array($this->cookie)
        );
        return true;
    }

    public function getSoftaculousLink()
    {
        $this->checkLogin();
        $getlink = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=installer&ttt=" . $this->getToken(),
            false,
            array(),
            true,
            array(
                $this->cookie
            ),
            true
        );
        if (preg_match('~Location: (.*)~i', $getlink, $match)) {
            $location = trim($match[1]);
        }
        return $location;
    }

    public function showErrorPage($domainname = "", $option = "400")
    {
        /* Returns the URL that has been set for an error page.
         * Available options: "400", "401", "403", "404, and "503". Returns 400 if no option is given.
         */
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $xpath = '//input[@name="' . $option . '"]';
        $htmlContent = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=errorpages_configure",
            true,
            array(
                "domain_name" => $domainname
            ),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);

        $domxpath = new DOMXPath($dom);

        $values = $domxpath->query($xpath);
        return $values->item(0)->getAttribute("value");
    }

    public function updateErrorPages(
        $domainname = "",
        $value400 = "",
        $value401 = "",
        $value403 = "",
        $value404 = "",
        $value503 = ""
    )
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=errorpages_change", true, array(
            "domain_name" => $domainname,
            "400" => $value400,
            "401" => $value401,
            "403" => $value403,
            "404" => $value404,
            "503" => $value503
            
        ), false, array(
            $this->cookie
        ));
        return true;
    }
    



    public function showPHPConfig($domainname = "", $option = "display_errors")
    {
        /* Returns the URL that has been set for an error page.
         * Available options: "display_errors", "mbstring_http_input", "date_timezone". Returns displayerror if no option is given.

            Returning Values:
            display_errors: true(Enabled)/false(Disabled) (Boolean) 
            mbstring_http_input: Value (String)
            date_timezone: Timezone (String)

         */
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        if ($option!=="date_timezone") {
            $xpath = '//input[@name="' . $option . '"]';
        } else {
            $xpath = "//select[@name='date_timezone']/option[@selected]";
        }
        $htmlContent = $this->simpleCurl(
            $this->cpanelUrl . "/panel/indexpl.php?option=phpchangeconfig_configure",
            true,
            array(
                "domain_name" => $domainname
            ),
            false,
            array(
                $this->cookie
            )
        );
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlContent);

        $domxpath = new DOMXPath($dom);

        $values = $domxpath->query($xpath);
        if ($option=="mbstring_http_input") {
            return $values->item(0)->getAttribute("value");
        } else if ($option=="display_errors") {
            return $values->item(1)->getAttribute("checked");
        } else if ($option=="date_timezone") {
            return $values->item(0)->nodeValue;
        }
    }

    public function setPHPConfig(
        $domainname = "",
        $displayerrors = "",
        $mbstringinput = "",
        $timezone = ""
    )
    {
        $this->checkLogin();
        $this->checkForEmptyParams($domainname);
        $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=phpchangeconfig_change", true, array(
            "domain_name" => $domainname,
            "display_errors" => $displayerrors,
            "mbstring_http_input" => $mbstringinput,
            "date_timezone" => $timezone,
            
        ), false, array(
            $this->cookie
        ));
        return true;
    }
    





 public function getUserStats()
  {

    function tableToArray($html)
    {
      $doc = new DOMDocument();
      $doc->loadHTML($html);
      $table = $doc->getElementById("stats");
      $rows = $table->getElementsByTagName('tr');

      $data = array();

      foreach ($rows as $row) {
        $cols = $row->getElementsByTagName('td');
        if ($cols->length === 2) {

          $key = trim($cols->item(0)->nodeValue);
          $value = trim($cols->item(1)->nodeValue);
          $data[$key] = $value;
        }
      }

      return $data;
    }

    return tableToArray($this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php", true, null, false, array(
      $this->cookie
    )));
  }


    public function logout()
    {
        $this->checkLogin();
        $this->simpleCurl($this->cpanelUrl . "/panel/indexpl.php?option=signout", false, array(), false, array(
            $this->cookie
        ), true);
        $this->loggedIn = false;
        $this->vistapanelSession = "";
        $this->accountUsername = "";
        $this->cookie = "";
        return true;
    }
    
}
