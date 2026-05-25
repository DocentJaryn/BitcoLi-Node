<?php
function initlnd($lnd) {
    $cfg = require __DIR__ . '/config.php';
    $lnd->setHost($cfg['LND_HOST']);
    $lnd->loadMacaroon($cfg['LND_MACAROON']);
}


class lnd {
    /*
     * version of the lnd rest api that we are communicating with
     */

    private $lndApiVersion = 'v1';

    /*
     * lnd rest endpoint
     * (includes lnd host ip&port & api version, formatted as url)
     */
    private $lndEndPoint = '';

    /*
     * local disk path to tls.cert
     * (required when sending SSL/TLS encrypted requests to lnd)
     */
    private $tlsCertificatePath = '';

    /*
     * forces curl to use SSL/TLS when communicating with lnd
     * (requires that tlsCertificatePath is set)
     */
    public $useSSL = false;

    /*
     * the hexadecimal representation of the lnd macaroon file
     * (this is sent in the header of every request we make
     * and is used by lnd for authentication)
     */
    protected $macaroonHex;

    public function __construct($lndHost = '') {
        if (!empty($lndHost)) {
            $this->setHost($lndHost);
        }
    }

    /*
     * Formats the lnd host details into an endpoint URL
     * This will be the URL to which all our curl requests are sent
     */

    public function setHost($lndHost) {
        // run a basic regex check to ensure the provided host
        // string is in the format of host:port
        $regex = "([a-z0-9\-\.]*)\.(([a-z]{2,4})|([0-9]{1,3}\.([0-9]{1,3})\.([0-9]{1,3})))";
        $regex .= "(:[0-9]{2,5})?";

        if (preg_match("~^$regex$~i", $lndHost)) {
            $this->lndEndPoint = 'https://' . $lndHost . '/'; // . $this->lndApiVersion . '/';
        } else {
            throw new Exception("Invalid lnd host. Use host:port syntax.");
        }
    }

    /*
     * construct a new lnd api request using curl and send it to our lnd endpoint.
     * decode the JSON response and return an object of stdClass
     */

    public function request($path, $postOptions = '') {

        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json; charset=UTF-8');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 20);

        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
        if (is_array($postOptions) && count($postOptions) > 0) {
//        if ($postOptions != "") {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
//            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $postOptions);
            curl_setopt($curlHandle, CURLOPT_POST, 1);
        }

        // execute the curl request then decode the JSON response to an
        // object of standard class
        $response = curl_exec($curlHandle);
        $requestResponse = json_decode($response);
        curl_close($curlHandle);

        // if the response is empty, throw an exception
        // otherwise return response data object
        if ($requestResponse == null) {
            $json = "[" . str_replace("\n", ",", trim($response)) . "]";
            $requestResponse = json_decode($json);
            if ($requestResponse != null) {
                return $requestResponse;                
            }
            //$exceptionString = "Request to " . $requestUrl . " failed.\n";
            //$exceptionString .= "See https://api.lightning.community/rest documentation.";
            $json_res = new stdClass();
            $json_res->error = true;
            $json_res->code = 7;
            $json_res->message = 'LND failue. Please try again later.';
            $json_res->recv = $ps ?? "";
            return $json_res;
            //throw new Exception($exceptionString);
        } else {
            return $requestResponse;
        }
    }

    public function InvoicesSubscribe($callback, $add_index, $settle_index) {
        $path = 'v1/invoices/subscribe?add_index=' . $add_index . '&settle_index=' . $settle_index;
        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_BUFFERSIZE, 256);
        //max 10vterin na navazani spojeni 
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        //Cekej maximalne 60vterin na vyrizeni tohoto pozadavku
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 60);
//		if(is_array($postOptions) && count($postOptions)>0) {
//			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
//		}

        global $streamData; // = "zacatek";
        global $bracketCnt; // = 0;
        $streamData = '';
        $bracketCnt = 0;
        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
            //  echo $data;
            global $streamData, $bracketCnt;
            for ($i = 0; $i < strlen($data); $i++) {
                $streamData .= $data[$i];
                if ($data[$i] === '{') {
                    $bracketCnt++;
                }
                if ($data[$i] === '}') {
                    $bracketCnt--;
                    if ($bracketCnt === 0) {
                        print_r($streamData . '<br><br>');

                        $json = json_encode($streamData);
                        //         var_dump($json);
//var_dump(json_decode($json));
//print_r($json.'<br><br>');
                        //$callback($json);
                        call_user_func($callback);
//call_user_func($callback, 'bbbbbb');
//call_user_func($callback,'aaaaaaaaaaa');
                        //print_r($streamData.'<br><br>');
                        /* 			print_r(bin2hex(base64_decode($json->result->r_preimage)).'-');
                          print_r(bin2hex(base64_decode($json->result->r_hash)).'-');
                          print_r($json->result->memo.'<br>');
                          //                      print_r($json->result->memo);
                          //                       print_r($json.'<br>');
                         */ $streamData = '';
                    }
                }
            }
            ob_flush();
            flush();
            return strlen($data);
        });
        curl_exec($curlHandle);
        curl_close($curlHandle);
        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
    }

    public function requestSubscribe($add_index, $settle_index) {
        $path = 'v1/invoices/subscribe?add_index=' . $add_index . '&settle_index=' . $settle_index;
        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_BUFFERSIZE, 256);
        //max 10vterin na navazani spojeni 
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        //Cekej maximalne 60vterin na vyrizeni tohoto pozadavku
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 60);
//		if(is_array($postOptions) && count($postOptions)>0) {
//			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
//		}

        global $streamData; // = "zacatek";
        global $bracketCnt; // = 0;
        $streamData = '';
        $bracketCnt = 0;
        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
            //  echo $data;
            global $streamData, $bracketCnt;
            for ($i = 0; $i < strlen($data); $i++) {
                $streamData .= $data[$i];
                if ($data[$i] === '{') {
                    $bracketCnt++;
                }
                if ($data[$i] === '}') {
                    $bracketCnt--;
                    if ($bracketCnt === 0) {
                        $json = json_decode($streamData);
                        print_r($streamData . '<br><br>');
                        /* 			print_r(bin2hex(base64_decode($json->result->r_preimage)).'-');
                          print_r(bin2hex(base64_decode($json->result->r_hash)).'-');
                          print_r($json->result->memo.'<br>');
                          //                      print_r($json->result->memo);
                          //                       print_r($json.'<br>');
                         */ $streamData = '';
                    }
                }
            }
            ob_flush();
            flush();
            return strlen($data);
        });
        curl_exec($curlHandle);
        curl_close($curlHandle);
        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
    }

    public function TransactionsSubscribe($callback, $start_height) {
//$path = 'v1/transactions?start_height='.$start_height;//.'&end_height=-1';

        $path = 'v1/transactions/subscribe?start_height=' . $start_height . '&end_height=800000';
        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_BUFFERSIZE, 256);
        //max 10vterin na navazani spojeni 
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        //Cekej maximalne 60vterin na vyrizeni tohoto pozadavku
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 600);
//		if(is_array($postOptions) && count($postOptions)>0) {
//			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
//		}

        global $streamData; // = "zacatek";
        global $bracketCnt; // = 0;
        $streamData = '';
        $bracketCnt = 0;
        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
            echo $data;
            global $streamData, $bracketCnt;
            for ($i = 0; $i < strlen($data); $i++) {
                $streamData .= $data[$i];
                if ($data[$i] === '{') {
                    $bracketCnt++;
                }
                if ($data[$i] === '}') {
                    $bracketCnt--;
                    if ($bracketCnt === 0) {
                        $json = json_decode($streamData);
                        $callback($json);
                        //print_r($streamData.'<br><br>');
                        /* 			print_r(bin2hex(base64_decode($json->result->r_preimage)).'-');
                          print_r(bin2hex(base64_decode($json->result->r_hash)).'-');
                          print_r($json->result->memo.'<br>');
                          //                      print_r($json->result->memo);
                          //                       print_r($json.'<br>');
                         */ $streamData = '';
                    }
                }
            }
            ob_flush();
            flush();
            return strlen($data);
        });
        curl_exec($curlHandle);
        curl_close($curlHandle);
        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
    }

    public function requestTransactionsSubscribe($start_height) {
//$path = 'v1/transactions?start_height='.$start_height;//.'&end_height=-1';

        $path = 'v1/transactions/subscribe'; //?start_height='.$start_height.'&end_height=-1';
        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_BUFFERSIZE, 256);
        //max 10vterin na navazani spojeni 
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        //Cekej maximalne 60vterin na vyrizeni tohoto pozadavku
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 60);
//		if(is_array($postOptions) && count($postOptions)>0) {
//			curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
//		}

        global $streamData; // = "zacatek";
        global $bracketCnt; // = 0;
        $streamData = '';
        $bracketCnt = 0;
        echo($requestUrl);
        ob_flush();
        flush();
        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
            echo('Data=');
            echo $data;
            global $streamData, $bracketCnt;
            for ($i = 0; $i < strlen($data); $i++) {
                $streamData .= $data[$i];
                if ($data[$i] === '{') {
                    $bracketCnt++;
                }
                if ($data[$i] === '}') {
                    $bracketCnt--;
                    if ($bracketCnt === 0) {
                        $json = json_decode($streamData);
                        print_r($streamData . '<br><br>');
                        /* 			print_r(bin2hex(base64_decode($json->result->r_preimage)).'-');
                          print_r(bin2hex(base64_decode($json->result->r_hash)).'-');
                          print_r($json->result->memo.'<br>');
                          //                      print_r($json->result->memo);
                          //                       print_r($json.'<br>');
                         */ $streamData = '';
                    }
                }
            }
            ob_flush();
            flush();
            return strlen($data);
        });
        curl_exec($curlHandle);
        curl_close($curlHandle);
        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
    }

    public function requestStream($path, $postOptions = '') {

        $requestUrl = $this->lndEndPoint . $path;
        // include lnd authentication macaroon (hex representation) in our curl request
        // header and set the request content type to JSON
        $requestHeader = array('Grpc-Metadata-macaroon:' . $this->macaroonHex,
            'Content-Type:application/json');

        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_URL, $requestUrl);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $requestHeader);
//		curl_setopt($curlHandle, CURLOPT_CAPATH, $this->tlsCertificatePath);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, $this->useSSL);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        $fp = fopen("memory.txt", "w+");
        echo('xx');
        echo(filesize($fp));

        curl_setopt($curlHandle, CURLOPT_FILE, $fp);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_BUFFERSIZE, 256);
        // if lnd expects additional parameters for a request,
        // we include these by setting the curl postfields option to a JSON encoded
        // representation of the supplied $postOptions array
        if (is_array($postOptions) && count($postOptions) > 0) {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($postOptions));
        }

        // execute the curl request then decode the JSON response to an
        // object of standard class
        echo('OKK');
        curl_exec($curlHandle);
        echo('OKKK2');
        sleep(1);
        //	print_r(curl_getinfo($curlHandle));
        if (curl_error($curlHandle)) {
            fputs($fp, "error\n");
            fwrite($fp, curl_error($curlHandle));
        }
        print_r('end');
        fputs($fp, "hello\n");
        rewind($fp);
        echo(stream_get_contents($fp));
        print_r(filesize($fp));
        /* $requestResponse = fread($fp, filesize($fp)); */
        curl_close($curlHandle);
        fclose($fp);

        // if the response is empty, throw an exception
        // otherwise return response data object
        if (!$requestResponse) {
            $exceptionString = "Request to " . $requestUrl . " failed.\n";
            $exceptionString .= "See https://api.lightning.community/rest documentation.";
            throw new Exception($exceptionString);
        } else {
            return $requestResponse;
        }
    }

    /*
     * Read the lnd authentication .macaroon file from disk
     * convert to its (uppercase) hexadecimal representation and store
     * for later use (in constructing curl request headers)
     */

    public function loadMacaroon($macaroonPath) {
        if (file_exists($macaroonPath)) {
            $this->macaroonHex = strtoupper(bin2hex(file_get_contents($macaroonPath)));
        } else {
            throw new Exception('Macaroon not found.');
        }
    }

    /*
     * Check lnd TLS certificate exists on disk and
     * store its path for later use (in constructing curl request headers)
     */

    public function loadTlsCert($tlsCertificatePath) {
        if (file_exists($tlsCertificatePath)) {
            $this->tlsCertificatePath = $tlsCertificatePath;
        } else {
            throw new Exception('TLS Certificate not found.');
        }
    }

}
