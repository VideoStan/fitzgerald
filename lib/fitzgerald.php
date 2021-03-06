<?php

    // This is the only file you really need! The directory structure of this repo is a suggestion,
    // not a requirement. It's your app.


    /*  Fitzgerald - a single file PHP framework
     *  (c) 2008 Jim Benton, released under the MIT license
     *  Version 0.2
     */

    class Template {
        private $fileName;
        private $root = array();
        public function __construct($root, $fileName) {
            $this->fileName = $fileName;
            $this->root = (array)$root;
        }
        public function render($locals) {
        	   $success = false;
            extract($locals);
            ob_start();

            foreach ($this->root as $root) {
               $file = $root . 'views/' . $this->fileName . '.php';
               if (file_exists($file)) {
                  include $file;
                  $success = true;
                  break;
                }
            }
            // @todo render an emergency 500 in heredoc/nowdoc if !$success
            return ob_get_clean();
        }
    }

    class Url {
        private $url;
        private $method;
        private $conditions;

        private $filters = array();
        public $params = array();
        public $match = false;

        public function __construct($httpMethod, $url, $conditions=array(), $mountPoint) {

            $requestMethod = $_SERVER['REQUEST_METHOD'];
            $requestUri = str_replace($mountPoint, '', $_SERVER['REQUEST_URI']);
            if (empty($requestUri)) $requestUri = '/';
            $this->url = $url;
            $this->method = $httpMethod;
            $this->conditions = $conditions;

            if (strtoupper($httpMethod) == $requestMethod) {

                $paramNames = array();
                $paramValues = array();
                $requestUri = parse_url($requestUri, PHP_URL_PATH);

                preg_match_all('@:([a-zA-Z]+)@', $url, $paramNames, PREG_PATTERN_ORDER);                    // get param names
                $paramNames = $paramNames[1];                                                               // we want the set of matches
                $regexedUrl = preg_replace_callback('@:[a-zA-Z_]+@', array($this, 'regexValue'), $url);     // replace param with regex capture
                if (preg_match('@^' . $regexedUrl . '$@', $requestUri, $paramValues)){                      // determine match and get param values
                    array_shift($paramValues);                                                              // remove the complete text match
                    for ($i=0; $i < count($paramNames); $i++) {
                        $this->params[$paramNames[$i]] = $paramValues[$i];
                    }
                    $this->match = true;
                }
            }
        }

        private function regexValue($matches) {
            $key = str_replace(':', '', $matches[0]);
            if (array_key_exists($key, $this->conditions)) {
                return '(' . $this->conditions[$key] . ')';
            } else {
                return '([a-zA-Z0-9_]+)';
            }
        }

    }

    class ArrayWrapper {
        private $subject;
        public function __construct(&$subject) {
            $this->subject = $subject;
        }
        public function __get($key) {
            return isset($this->subject[$key]) ? $this->subject[$key] : null;
        }

        public function __set($key, $value) {
            $this->subject = $value;
            return $value;
        }
    }

    class SessionWrapper {
        protected $root = null;

        public function __construct($root = null) {
            $this->root = $root;
        }

        public static function open($name = 'fitzgerald_session') {
            session_name($name);
            session_start();
        }

        public function __get($key) {
            global $_SESSION;
            if (is_null($this->root)) {
                return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
            }
            return isset($_SESSION[$this->root][$key]) ? $_SESSION[$this->root][$key] : null;
        }

        public function __set($key, $value) {
            global $_SESSION;
            if (is_null($this->root)) {
                $_SESSION[$key] = $value;
            } else {
                $_SESSION[$this->root][$key] = $value;
            }
            return $value;
        }

        public function close()
        {
            session_write_close();
        }
    }

    class RequestWrapper extends ArrayIterator {

        public function __get($key) {
            global $_REQUEST;
            return isset($_REQUEST[$key]) ? $_REQUEST[$key] : null;
        }

        public function __set($key, $value) {
            global $_REQUEST;
            $_REQUEST[$key] = $value;
            return $value;
        }

    	  public function count() {
    	      return count($_REQUEST);
    	  }

        public function rewind() {
            return reset($_REQUEST);
        }

        public function current() {
            return current($_REQUEST);
        }

        public function key() {
            return key($_REQUEST);
        }

        public function next() {
            return next($_REQUEST);
        }

        public function valid() {
            return key($_REQUEST) !== null;
        }
    }

    class Fitzgerald {

        private $mappings = array();
        private $options;
        protected $session;
        protected $request;

        public function __construct($options=array()) {
            $this->options = new ArrayWrapper($options);

            $openSession  = true;
            if (!is_null($this->options->sessions)) {
            	$openSession = (bool)$this->options->session;
            }
            if ($openSession) SessionWrapper::open();
            $this->session = new SessionWrapper($this->options->sessionRootKey);
            $this->request = new RequestWrapper;
            $errorLevel = $this->options->errorLevel;
            if (is_null($errorLevel) || !is_int($errorLevel)) $errorLevel = E_WARNING;
            set_error_handler(array($this, 'handleError'), $errorLevel);
        }

        public function setResponseCode($code) {
            header('placeholder', true, $code);
        }

        /**
         * @todo handle exceptions
         */
        public function handleError($number, $message, $file, $line) {
            $this->setResponseCode(500);
            echo $this->render('500', compact('number', 'message', 'file', 'line'));
            exit(1);
        }

        public function show404() {
            $this->setResponseCode(404);
            echo $this->render('404');
            exit(0);
        }

        public function error($body, $statusCode = 400) {
            $this->halt($body, $statusCode);
        }

        public function halt($body = '', $statusCode = 200, $headers = array()) {
            $this->setResponseCode($statusCode);
            $this->sendHeaders($headers);
            echo $body;
            exit(0);
        }

        public function sendHeaders($headers) {
            foreach ($headers as $headerKey => $headerValue) {
            	$this->sendHeader($headerKey, $headerValue);
            }
        }

        public function sendHeader($headerKey, $headerValue) {
           header($headerKey . ':' . $headerValue, true);
        }

        public function get($url, $methodName, $conditions=array()) {
           $this->event('get', $url, $methodName, $conditions);
        }

        public function post($url, $methodName, $conditions=array()) {
           $this->event('post', $url, $methodName, $conditions);
        }

        public function before($methodName, $filterName) {
            if (!is_array($methodName)) {
                $methodName = explode('|', $methodName);
            }
            for ($i = 0; $i < count($methodName); $i++) {
                $method = $methodName[$i];
                if (!isset($this->filters[$method])) {
                    $this->filters[$method] = array();
                }
                array_push($this->filters[$method], $filterName);
            }
        }

        public function run() {
            echo $this->processRequest();
        }

        protected function redirect($path) {
            $protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
            $host = (preg_match('%^http://|https://%', $path) > 0) ? '' : "$protocol://" . $_SERVER['HTTP_HOST'];
            $uri = is_string($this->options->mountPoint) ? $this->options->mountPoint : '';
            if (isset($this->error)) $this->session->error = $this->error;
            if (isset($this->success)) $this->session->success = $this->success;
            $this->session->close();
            header("Location: $host$uri$path");
            return false;
        }

        protected function render($fileName, $variableArray=array()) {
            $variableArray['options'] = $this->options;
            $variableArray['request'] = $this->request;
            $variableArray['session'] = $this->session;
            if(isset($this->error)) {
                $variableArray['error'] = $this->error;
            }
            if(isset($this->success)) {
                $variableArray['success'] = $this->success;
            }

            if (is_string($this->options->layout)) {
                $contentTemplate = new Template($this->views(), $fileName);              // create content template
                $variableArray['content'] = $contentTemplate->render($variableArray);   // render and store contet
                $layoutTemplate = new Template($this->views(), $this->options->layout);  // create layout template
                return $layoutTemplate->render($variableArray);                         // render layout template and return
            } else {
                $template = new Template($this->views(), $fileName);                     // create template
                return $template->render($variableArray);                               // render template and return
            }
        }

        protected function sendFile($filename, $contentType, $path) {
            header("Content-type: $contentType");
            header("Content-Disposition: attachment; filename=$filename");
            return readfile($path);
        }

        protected function sendDownload($filename, $path) {
            header("Content-Type: application/force-download");
            header("Content-Type: application/octet-stream");
            header("Content-Type: application/download");
            header("Content-Description: File Transfer");
            header("Content-Disposition: attachment; filename=$filename".";");
            header("Content-Transfer-Encoding: binary");
            return readfile($path);
        }

        private function execute($methodName, $params) {
            if (isset($this->filters[$methodName])) {
                for ($i=0; $i < count($this->filters[$methodName]); $i++) {
                    $return = call_user_func(array($this, $this->filters[$methodName][$i]));
                    if (!is_null($return)) {
                        return $return;
                    }
                }
            }

            if ($this->session->error) {
                $this->error = $this->session->error;
                $this->session->error = null;
            }
            if ($this->session->success) {
                $this->success = $this->session->success;
                $this->session->success = null;
            }

            $reflection = new ReflectionMethod(get_class($this), $methodName);
            $args = array();

            foreach ($reflection->getParameters() as $i => $param) {
                if (!isset($params[$param->name]) && $param->isDefaultValueAvailable()) {
                	$args[$param->name] = $param->getDefaultValue();
                } else {
                	$args[$param->name] = $params[$param->name];
                }
            }

            return call_user_func_array(array($this, $methodName), $args);
        }

        private function event($httpMethod, $url, $methodName, $conditions=array()) {
            if (method_exists($this, $methodName)) {
                array_push($this->mappings, array($httpMethod, $url, $methodName, $conditions));
            }
        }

        protected function views()
        {
            return array($this->root());
        }

        protected function root() {
            return dirname(__FILE__) . '/../';
        }

        protected function path($path) {
            return $this->root() . $path;
        }

        // @todo don't use this static, should be in a helpers class instead and cached
        public static function baseUrl()
        {
            $scheme = $scheme = !isset($_SERVER['HTTPS']) || ($_SERVER['HTTPS'] == 'off') ? 'http' : 'https';
            $port = $_SERVER['SERVER_PORT'];
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
            if (($scheme == 'http' && $port != 80) || ($scheme == 'https' && $port != 443)) {
            	$baseUrl .= ':' . $port;
            }
            return $baseUrl;
        }

        private function processRequest() {
            for ($i = 0; $i < count($this->mappings); $i++) {
                $mapping = $this->mappings[$i];
                $mountPoint = is_string($this->options->mountPoint) ? $this->options->mountPoint : '';
                $url = new Url($mapping[0], $mapping[1], $mapping[3], $mountPoint);
                if ($url->match) {
                    return $this->execute($mapping[2], $url->params);
                }
            }
            return $this->show404();
        }
    }
