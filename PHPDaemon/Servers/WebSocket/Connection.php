<?php
namespace PHPDaemon\Servers\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\WebSocket\ProtocolV0;
use PHPDaemon\WebSocket\ProtocolV13;
use PHPDaemon\WebSocket\ProtocolVE;
use PHPDaemon\WebSocket\Route;

class Connection extends \PHPDaemon\Network\Connection {
	use \PHPDaemon\Traits\DeferredEventHandlers;
	use \PHPDaemon\Traits\Sessions;

	/**
	 * Timeout
	 * @var integer
	 */
	protected $timeout = 120;

	protected $handshaked = false;
	protected $route;
	protected $writeReady = true;
	protected $extensions = [];
	protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';
	protected $buf = '';
	protected $protocol;
	protected $policyReqNotFound = false;
	protected $currentHeader;
	protected $EOL = "\r\n";
	public $session;

	/**
	 * Is this connection running right now?
	 * @var boolean
	 */
	protected $running = false;

	/**
	 * State: first line
	 * @var integer
	 */
	const STATE_FIRSTLINE  = 1;
	
	/**
	 * State: headers
	 * @var integer
	 */
	const STATE_HEADERS    = 2;
	
	/**
	 * State: content
	 * @var integer
	 */
	const STATE_CONTENT    = 3;
	
	/**
	 * State: processing
	 * @var integer
	 */
	const STATE_PROCESSING = 5;
	
	/**
	 * State: handshaked
	 * @var integer
	 */
	const STATE_HANDSHAKED = 6;

	/**
	 * Frame buffer
	 * @var string
	 */
	public $framebuf = '';

	/**
	 * _SERVER
	 * @var array
	 */
	public $server = [];

	/**
	 * _COOKIE
	 * @var array
	 */
	public $cookie = [];

	/**
	 * Contructor
	 * @return void
	 */
	public function init() {
		$this->setWatermark(null, $this->pool->maxAllowedPacket + 100);
	}


	/**
	 * Get cookie by name
	 * @param string $name Name of cookie
	 * @return string Contents
	 */
	protected function getCookieStr($name) {
		return \PHPDaemon\HTTPRequest\Generic::getString($this->cookie[$name]);
	}


	/**
	 * Set session state
	 * @param mixed
	 * @return void
	 */
	protected function setSessionState($var) {
		$this->session = $var;
	}

	/**
	 * Get session state
	 * @return mixed
	 */
	protected function getSessionState() {
		return $this->session;
	}


	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		$this->running   = true;
		Daemon::$context = $this;
		Daemon::$process->setState(Daemon::WSTATE_BUSY);
	}

	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() {
		Daemon::$context = null;
		$this->running   = false;
		Daemon::$process->setState(Daemon::WSTATE_IDLE);
	}

	/**
	 * Called when connection is inherited from HTTP request
	 * @param $req
	 * @return void
	 */
	public function onInheritanceFromRequest($req) {
		$this->state  = self::STATE_HEADERS;
		$this->addr   = $req->attrs->server['REMOTE_ADDR'];
		$this->server = $req->attrs->server;
		$this->prependInput("\r\n");
		$this->onRead();
	}

	/**
	 * Sends a frame.
	 * @param string $data  Frame's data.
	 * @param string $type  Frame's type. ("STRING" OR "BINARY")
	 * @param callback $cb Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		if (!$this->handshaked) {
			return false;
		}

		if ($this->finished) {
			return false;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client ' . $this->addr);
			return false;
		}

		$this->protocol->sendFrame($data, $type);
		if ($cb) {
			$this->onWriteOnce($cb);
		}
		return true;
	}

	/**
	 * Event of Connection.
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->route)) {
			$this->route->onFinish();
		}
		$this->route = null;
		if ($this->protocol) {
			$this->protocol->conn = null;
			$this->protocol       = null;
		}
	}

	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean Handled?
	 */
	public function handleException($e) {
		if (!isset($this->route)) {
			return false;
		}
		return $this->route->handleException($e);
	}

	/**
	 * Called when new frame received.
	 * @param string Frame's data.
	 * @param string Frame's type ("STRING" OR "BINARY").
	 * @return boolean Success.
	 */
	public function onFrame($data, $type) {
		if (!isset($this->route)) {
			return false;
		}
		$this->onWakeup();
		$this->route->onFrame($data, $type);
		$this->onSleep();
		return true;
	}

	/**
	 * Called when the connection is handshaked.
	 * @return boolean Ready to handshake ?
	 */
	public function onHandshake() {

		$e         = explode('/', $this->server['DOCUMENT_URI']);
		$routeName = isset($e[1]) ? $e[1] : '';

		if (!isset($this->pool->routes[$routeName])) {
			if (Daemon::$config->logerrors->value) {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : undefined route "' . $routeName . '" for client "' . $this->addr . '"');
			}

			return false;
		}
		$route = $this->pool->routes[$routeName];
		if (is_string($route)) { // if we have a class name
			if (class_exists($route)) {
				$this->onWakeup();
				new $route($this);
				$this->onSleep();
			}
			else {
				return false;
			}
		}
		elseif (is_array($route) || is_object($route)) { // if we have a lambda object or callback reference
			if (is_callable($route)) {
				$ret = call_user_func($route, $this); // calling the route callback
				if ($ret instanceof Route) {
					$this->route = $ret;
				}
				else {
					return false;
				}
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
			return false;
		}

		if ($this->protocol->onHandshake() === false) {
			return false;
		}

		return true;
	}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown ?
	 */
	public function gracefulShutdown() {
		if ((!$this->route) || $this->route->gracefulShutdown()) {
			$this->finish();
			return true;
		}
		return FALSE;
	}

	/**
	 * Called when we're going to handshake.
	 * @param $data
	 * @return boolean Handshake status
	 */
	public function handshake($data) {

		if (!$this->onHandshake()) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot handshake session for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}

		// Handshaking...
		$handshake = $this->protocol->getHandshakeReply($data);

		if ($handshake === 0) { // not enough data yet
			return 0;
		}

		if (!$handshake) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}
		if (!$this->write($handshake)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake send failure for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}
		$this->handshaked = true;
		if (is_callable([$this->route, 'onHandshake'])) {
			$this->route->onHandshake();
		}
		return true;
	}

	/**
	 * Send Bad request
	 * @return void
	 */
	public function badRequest() {
		$this->state = self::STATE_ROOT;
		$this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
		$this->finish();
	}

	/**
	 * Read first line of HTTP request
	 * @return boolean Success
	 * @return void
	 */
	protected function httpReadFirstline() {
		if (($l = $this->readline()) === null) {
			return null;
		}
		$e = explode(' ', $l);
		$u = isset($e[1]) ? parse_url($e[1]) : false;
		if ($u === false) {
			$this->badRequest();
			return false;
		}
		if (!isset($u['path'])) {
			$u['path'] = null;
		}
		if (isset($u['host'])) {
			$this->server['HTTP_HOST'] = $u['host'];
		}
		$srv                       = & $this->server;
		$srv['REQUEST_METHOD']     = $e[0];
		$srv['REQUEST_TIME']       = time();
		$srv['REQUEST_TIME_FLOAT'] = microtime(true);
		$srv['REQUEST_URI']        = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
		$srv['DOCUMENT_URI']       = $u['path'];
		$srv['PHP_SELF']           = $u['path'];
		$srv['QUERY_STRING']       = isset($u['query']) ? $u['query'] : null;
		$srv['SCRIPT_NAME']        = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
		$srv['SERVER_PROTOCOL']    = isset($e[2]) ? $e[2] : 'HTTP/1.1';
		$srv['REMOTE_ADDR']        = $this->addr;
		$srv['REMOTE_PORT']        = $this->port;
		return true;
	}

	/**
	 * Read headers line-by-line
	 * @return boolean Success
	 * @return void
	 */
	protected function httpReadHeaders() {
		while (($l = $this->readLine()) !== null) {
			if ($l === '') {
				return true;
			}
			$e = explode(': ', $l);
			if (isset($e[1])) {
				$this->currentHeader                = 'HTTP_' . strtoupper(strtr($e[0], Generic::$htr));
				$this->server[$this->currentHeader] = $e[1];
			}
			elseif (($e[0][0] === "\t" || $e[0][0] === "\x20") && $this->currentHeader) {
				// multiline header continued
				$this->server[$this->currentHeader] .= $e[0];
			}
			else {
				// whatever client speaks is not HTTP anymore
				$this->badRequest();
				return false;
			}
		}
		return null;
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	protected function onRead() {
		if (!$this->policyReqNotFound) {
			$d = $this->drainIfMatch("<policy-file-request/>\x00");
			if ($d === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = \PHPDaemon\Servers\FlashPolicy\Pool::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}
			else {
				$this->policyReqNotFound = true;
			}
		}
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			$this->state = self::STATE_FIRSTLINE;
		}
		if ($this->state === self::STATE_FIRSTLINE) {
			if (!$this->httpReadFirstline()) {
				return;
			}
			$this->state = self::STATE_HEADERS;
		}

		if ($this->state === self::STATE_HEADERS) {
			if (!$this->httpReadHeaders()) {
				return;
			}
			if (!$this->httpProcessHeaders()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->state = self::STATE_PROCESSING;
		}

		if ($this->state === self::STATE_PROCESSING) {
			$this->buf .= $this->read(1024);
			if (!$this->handshake($this->buf)) {
				return;
			}
			$this->buf   = '';
			$this->state = self::STATE_HANDSHAKED;
		}
		if ($this->state === self::STATE_HANDSHAKED) {
			if (!isset($this->protocol)) {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
				$this->finish();
				return;
			}
			$this->protocol->onRead();
		}

	}

	/**
	 * Process headers
	 * @return bool
	 */
	protected function httpProcessHeaders() {
		$this->state = self::STATE_PROCESSING;
		if (isset($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS'])) {
			$str              = strtolower($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS']);
			$str              = preg_replace($this->extensionsCleanRegex, '', $str);
			$this->extensions = explode(', ', $str);
		}
		if (!isset($this->server['HTTP_CONNECTION'])
				|| (!preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->server['HTTP_CONNECTION'])) // "Upgrade" is not always alone (ie. "Connection: Keep-alive, Upgrade")
				|| !isset($this->server['HTTP_UPGRADE'])
				|| (strtolower($this->server['HTTP_UPGRADE']) !== 'websocket') // Lowercase compare important
		) {
			$this->finish();
			return false;
		}
		if (isset($this->server['HTTP_COOKIE'])) {
			Generic::parse_str(strtr($this->server['HTTP_COOKIE'], Generic::$hvaltr), $this->cookie);
		}
		// ----------------------------------------------------------
		// Protocol discovery, based on HTTP headers...
		// ----------------------------------------------------------
		if (isset($this->server['HTTP_SEC_WEBSOCKET_VERSION'])) { // HYBI
			if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '8') { // Version 8 (FF7, Chrome14)
				$this->protocol = new ProtocolV13($this);
			}
			elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '13') { // newest protocol
				$this->protocol = new ProtocolV13($this);
			}
			else {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "' . $this->addr . '"');
				$this->finish();
				return false;
			}
		}
		elseif (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
			$this->protocol = new ProtocolVE($this);
		}
		else { // Defaulting to HIXIE (Safari5 and many non-browser clients...)
			$this->protocol = new ProtocolV0($this);
		}
		// ----------------------------------------------------------
		// End of protocol discovery
		// ----------------------------------------------------------
		return true;
	}
}
