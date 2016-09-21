<?php
namespace Iubar\Tests;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Client;

/**
 * PHPUnit_Framework_TestCase Develop
 *
 * @author Matteo
 */
abstract class RestApi_TestCase extends Root_TestCase {

    const GET = 'GET';
    
    const POST = 'POST';
    
    const APP_JSON_CT = 'application/json';
    
    const HTTP_OK = 200;
    
    const HTTP_BAD_REQUEST = 400;
    
    const HTTP_UNAUTHORIZED = 401;
    
    const HTTP_FORBIDDEN = 403;
    
    const HTTP_METHOD_NOT_ALLOWED = 405;
    
    const HTTP_NOT_FOUND = 404;
        
    const CONTENT_TYPE = 'Content-Type';
        
    const TIMEOUT = 4; // seconds
      
    protected static $client = null;

    protected static function factoryClient($base_uri=null){
        if(!$base_uri){            
            $base_uri = self::getHost() . '/';            
        }
        self::$climate->comment('factoryClient()');
        self::$climate->comment ("\tHost:\t\t" . self::getHost());
        self::$climate->comment("\tBase Uri:\t" . $base_uri);
        // Base URI is used with relative requests
        // You can set any number of default request options.
        $client = new Client([
            'base_uri' => $base_uri,
            'http_errors' => false, // Vedi http://docs.guzzlephp.org/en/latest/request-options.html#http-errors
            // 'headers' => ['User-Agent' => "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36"],
            'timeout' => self::TIMEOUT,
            'verify' => false
        ]);
        return $client;
    }
    
    protected function sleep($seconds){
        self::$climate->comment('Waiting ' . $seconds . ' seconds...');
        sleep($seconds);
    }
    
    protected static function getHost(){
        $http_host = getenv('HTTP_HOST');
        if(!$http_host){
            throw new \Exception('Wrong config'); // in un contesto statico non posso usare $this->fail('Wrong config');
        }
        return $http_host;
    }
    
    /**
     * Handle the RequestException writing his msg
     *
     * @param RequestException $e the exception
     */
    protected function handleException(RequestException $e) {
        $this->printSeparator();
        self::$climate->flank('Http client exception catched...');
        $request = $e->getRequest();      
        self::$climate->comment(PHP_EOL . 'Request: ' . trim(Psr7\str($request)));
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            self::$climate->error('Response code: ' . $response->getStatusCode());
            self::$climate->error('Response string: ' . PHP_EOL . trim(Psr7\str($response)));
        }        
        self::$climate->error(PHP_EOL . 'Exception message: ' . PHP_EOL . $e->getMessage());
        $this->printSeparator();
        $this->fail('Exception');
    }
    
    /**
     * Send an http GET request and return the response
     *
     * @param string $method the method
     * @param string $partial_uri the partial uri
     * @param string $array the query
     * @param int $timeout the timeout
     * @return string the response
     */
    protected function sendGetReq($partial_uri, $array, $timeout=null) {
        $response = null;
        if(!$timeout){
            $timeout = self::TIMEOUT;
        }
        if(!self::$client){
            throw new \Exception("Client obj is null");
        }
        try {
            $request = new Request(self::GET, $partial_uri);

            $response = self::$client->send($request, [
                'headers' => [
                                'User-Agent' => 'testing/1.0',
                                'Accept'     => 'application/json',
                                'X-Requested-With' => 'XMLHttpRequest' // for Whoops' JsonResponseHandler
                            ],
                'query' => $array,
                'verify' => false,  // Ignora la verifica dei certificati SSL (obbligatorio per accesso a risorse https)
                                    // @see: http://docs.guzzlephp.org/en/latest/request-options.html#verify-option
                'timeout' => $timeout                
            ]);
            
            self::$climate->comment(PHP_EOL . "Request: " . PHP_EOL . "\tUrl:\t" . $partial_uri . PHP_EOL . "\tQuery:\t" . json_encode($array, JSON_PRETTY_PRINT));
        } catch (ConnectException $e) { // Is thrown in the event of a networking error. (This exception extends from GuzzleHttp\Exception\RequestException.)
            $this->handleException($e);
        } catch (ClientException $e) { // Is thrown for 400 level errors if the http_errors request option is set to true.
            $this->handleException($e);            
        } catch (RequestException $e) { // In the event of a networking error (connection timeout, DNS errors, etc.), a GuzzleHttp\Exception\RequestException is thrown.
            $this->handleException($e);
        } catch (ServerException $e) { // Is thrown for 500 level errors if the http_errors request option is set to true.
            $this->handleException($e);
        }
        return $response;
    }
    
    /**
     * Check the OK status code and the APP_JSON_CT content type of the response
     * 
     * Usage:   $data = $this->checkResponse($response); 
     *          self::$climate->info('Response Body: ' . PHP_EOL . json_encode($data, JSON_PRETTY_PRINT)); 
     *
     * @param string $response the response
     * @param int $status_code the expected http status code          
     * @return string the body of the decode response
     */
    protected function checkResponse($response, $expected_status_code = self::HTTP_OK) {
        $data = null;
        if ($response) {
                 
            $body = $response->getBody()->getContents(); // Warning: call 'getBody()->getContents()' only once ! getContents() returns the remaining contents, so that a second call returns nothing unless you seek the position of the stream with rewind or seek
            
            $this->printBody($body);
            
            // Format the response
            $data = json_decode($body, true); // returns an array
            
            $content_type = $response->getHeader(self::CONTENT_TYPE)[0];
            
            if($content_type==self::APP_JSON_CT && isset($data['error'])){ // Intercetto le eccezioni nel formato json restituito da Whoops e stampo il messaggio di errore contenuto nella risposta json
                $this->printSeparator();
                self::$climate->flank('The json returned contains an error message...');
                $payload = $data['error'];
                $message = $payload['message'];
                self::$climate->error($message);
                $this->printSeparator();
                $this->fail('Failed');
            }else{
                
                if($response->getStatusCode()!=self::HTTP_OK){
                // Response
                self::$climate->comment('Status code: ' . $response->getStatusCode());
                self::$climate->comment('Content-Type: '  . json_encode($response->getHeader('Content-Type'), JSON_PRETTY_PRINT));
                // self::$climate->info('Access-Control-Allow-Origin: '  . json_encode($response->getHeader('Access-Control-Allow-Origin'), JSON_PRETTY_PRINT));
                }
                        
                
                // Asserzioni                
                self::$climate->comment('Checking assertions...');                                    
                $this->assertEquals($expected_status_code, $response->getStatusCode());
                $this->assertContains(self::APP_JSON_CT, $content_type);
                self::$climate->comment('...ok');
            }         
            
        }
        return $data;
    }

    private function printSeparator(){
        self::$climate->out(PHP_EOL . '--------------------------------------------' . PHP_EOL);
    }
    
    private function printBody($body){
        $max_char = 320;
        if(strlen($body) > $max_char){
            $body = substr($body, 0, $max_char) . ' ...<truncated>';
        }
        $json = json_encode($body, JSON_PRETTY_PRINT);
        self::$climate->comment('Response body: ' . PHP_EOL . $body);
    }
    
    private function printHttpHeader($response){
        foreach ($response->getHeaders() as $name => $values) {
            $str = $name . ': ' . implode(', ', $values);
            self::$climate->info($str);
        }        
    }
    
    
}