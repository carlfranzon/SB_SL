<?php
/*
Plugin Name: coreylib
Plugin URI: http://github.com/collegeman/coreylib
Description: A small PHP library for downloading, caching, and extracting data formatted as XML or JSON
Version: 2.0
Author: Aaron Collegeman
Author URI: http://github.com/collegeman
License: GPL2
*/

/**
 * coreylib
 * Parse and cache XML and JSON.
 * @author Aaron Collegeman aaron@collegeman.net
 * @version 2.0
 *
 * Copyright (C)2008-2010 Fat Panda LLC.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA. 
 */
  
// src/core.php

 
/**
 * Generic Exception wrapper
 */
class clException extends Exception {}
 
/**
 * Configuration defaults.
 */
// enable debugging output
@define('COREYLIB_DEBUG', false);
// maximum number of times to retry downloading content before failure
@define('COREYLIB_MAX_DOWNLOAD_ATTEMPTS', 3);
// the number of seconds to wait before timing out on CURL requests
@define('COREYLIB_DEFAULT_TIMEOUT', 30);
// the default HTTP method for requesting data from the URL
@define('COREYLIB_DEFAULT_METHOD', 'get');
// set this to true to disable all caching activity
@define('COREYLIB_NOCACHE', false);
// default cache strategy is clFileCache
@define('COREYLIB_DEFAULT_CACHE_STRATEGY', 'clFileCache');
// the name of the folder to create for clFileCache files - this folder is created inside the path clFileCache is told to use
@define('COREYLIB_FILECACHE_DIR', '.coreylib');
// auto-detect WordPress environment?
@define('COREYLIB_DETECT_WORDPRESS', true);

/**
 * Coreylib core.
 */
class clApi {
  
  // request method
  const METHOD_GET = 'get';
  const METHOD_POST = 'post';
  private $method;
  
  // the URL provided in the constructor
  private $url;
  // default HTTP headers
  private $headers = array(
    
  );
  // default curlopts
  private $curlopts = array(
    CURLOPT_USERAGENT => 'coreylib/2.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
  );
  // the parameters being passed in the request
  private $params = array();
  // basic authentication 
  private $user;
  private $pass;
  // the cURL handle used to get the content
  private $ch;
  // reference to caching strategy
  private $cache;
  // the download
  private $download;
  // the cache key
  private $cache_key;
  
  /**
   * @param String $url The URL to connect to, with or without query string
   * @param clCache $cache An instance of an implementation of clCache, or null (the default)
   *   to trigger the use of the global caching impl, or false, to indicate that no caching
   *   should be performed.
   */
  function __construct($url, $cache = null) {
    // parse the URL and extract things like user, pass, and query string
    if (( $parts = @parse_url($url) ) && strtolower($parts['scheme']) != 'file') {
      $this->user = @$parts['user'];
      $this->pass = @$parts['pass'];
      @parse_str($parts['query'], $this->params);
      // rebuild $url
      $url = sprintf('%s://%s%s', 
        $parts['scheme'], 
        $parts['host'] . ( @$parts['port'] ? ':'.$parts['port'] : '' ),
        $parts['path'] . ( @$parts['fragment'] ? ':'.$parts['fragment'] : '')
      );
    }
    // stash the processed $url
    $this->url = $url;
    // setup the default request method
    $this->method = ($method = strtolower(COREYLIB_DEFAULT_METHOD)) ? $method : self::METHOD_GET;
    
    $this->curlopt(CURLOPT_CONNECTTIMEOUT, COREYLIB_DEFAULT_TIMEOUT);
    
    $this->cache = is_null($cache) ? coreylib_get_cache() : $cache;
  }

  function getUrl() {
    return $this->url;
  }
  
  /**
   * Download and parse the data from the specified endpoint using an HTTP GET.
   * @param mixed $cache_for An expression of time (e.g., 10 minutes), or 0 to cache forever, or FALSE to flush the cache, or -1 to skip over all caching (the default)
   * @param string One of clApi::METHOD_GET or clApi::METHOD_POST, or null
   * @param string (optional) Force the node type, ignoring content type signals and auto-detection
   * @return clNode if parsing succeeds; otherwise FALSE.
   * @see http://php.net/manual/en/function.strtotime.php
   */
  function &parse($cache_for = -1, $override_method = null, $node_type = null) {
    $node = false;
    
    if (is_null($this->download)) {
      $this->download = $this->download(false, $cache_for, $override_method);
    }
      
    // if the download succeeded
    if ($this->download->is2__()) {
      if ($node_type) {
        $node = clNode::getNodeFor($this->download->getContent(), $node_type);
      } else if ($this->download->isXml()) {
        $node = clNode::getNodeFor($this->download->getContent(), 'xml');
      } else if ($this->download->isJson()) {
        $node = clNode::getNodeFor($this->download->getContent(), 'json');
      } else {
        throw new clException("Unable to determine content type. You can force a particular type by passing a third argument to clApi->parse(\$cache_for = -1, \$override_method = null, \$node_type = null).");
      }
    } 
      
    return $node;
  }
  
  /**
   * Download and parse the data from the specified endpoint using an HTTP POST.
   * @param mixed $cache_for An expression of time (e.g., 10 minutes), or 0 to cache forever, or FALSE to flush the cache, or -1 to skip over all caching (the default)
   * @return bool TRUE if parsing succeeds; otherwise FALSE.
   * @see http://php.net/manual/en/function.strtotime.php
   * @deprecated Use clApi->parse($cache_for, clApi::METHOD_POST) instead.
   */
  function post($cache_for = -1) {
    return $this->parse($cache_for, self::METHOD_POST);
  }
  
  /**
   * Retrieve the content of the parsed document.
   */
  function getContent() {
    return $this->download ? $this->download->getContent() : '';
  }
  
  /**
   * Print the content of the parsed document.
   */
  function __toString() {
    return $this->getContent();
  }
  
  /**
   * Set or get a coreylib configuration setting.
   * @param mixed $option Can be either a string or an array of key/value configuration settings
   * @param mixed $value The value to assign
   * @return mixed If $value is null, then return the value stored by $option; otherwise, null.
   */
  static function setting($option, $value = null) {
    if (!is_null($value) || is_array($option)) {
      if (is_array($option)) {
        self::$options = array_merge(self::$options, $option);
      } else {
        self::$options[$option] = $value;
      }
    } else {
      return @self::$options[$option];
    }
  }
  
  /**
   * Set or get an HTTP header configuration
   * @param mixed $name Can be either a string or an array of key/value pairs
   * @param mixed $value The value to assign
   * @return mixed If $value is null, then return the value stored by $name; otherwise, null.
   */
  function header($name, $value = null) {
    if (!is_null($value) || is_array($name)) {
      if (is_array($name)) {
        $this->headers = array_merge($this->headers, $name);
      } else {
        $this->headers[$name] = $value;
      }
    } else {
      return @$this->headers[$name];
    }
  }
  
  /**
   * Set or get a request parameter
   * @param mixed $name Can be either a string or an array of key/value pairs
   * @param mixed $value The value to assign
   * @return mixed If $value is null, then return the value stored by $name; otherwise, null.
   */
  function param($name, $value = null) {
    if (!is_null($value) || is_array($name)) {
      if (is_array($name)) {
        $this->params = array_merge($this->params, $name);
      } else {
        $this->params[$name] = $value;
      }
    } else {
      return @$this->params[$name];
    }
  }
  
  /**
   * Set or get a CURLOPT configuration
   * @param mixed $opt One of the CURL option constants, or an array of option/value pairs
   * @param mixed $value The value to assign
   * @return mixed If $value is null, then return the value stored by $opt; otherwise, null.
   */
  function curlopt($opt, $value = null) {
    if (!is_null($value) || is_array($opt)) {
      if (is_array($opt)) {
        $this->curlopts = array_merge($this->curlopts, $opt);
      } else {
        $this->curlopts[$opt] = $value;
      }
    } else {
      return @$this->curlopts[$opt];
    }
  }
  
  /**
   * Download the content according to the settings on this object, or load from the cache.
   * @param bool $queue If true, setup a CURL connection and return the handle; otherwise, execute the handle and return the content
   * @param mixed $cache_for One of:
   *    An expression of how long to cache the data (e.g., "10 minutes")
   *    0, indicating cache duration should be indefinite
   *    FALSE to regenerate the cache
   *    or -1 to skip over all caching (the default)
   * @param string $override_method one of clApi::METHOD_GET or clApi::METHOD_POST; optional, defaults to null. 
   * @return clDownload
   * @see http://php.net/manual/en/function.strtotime.php
   */
  function &download($queue = false, $cache_for = -1, $override_method = null) {
    $method = is_null($override_method) ? $this->method : $override_method;
  
    $qs = http_build_query($this->params);
    $url = ($method == self::METHOD_GET ? $this->url.($qs ? '?'.$qs : '') : $this->url);
    
    // use the URL to generate a cache key unique to request and any authentication data present
    $this->cache_key = $cache_key = md5($method.$this->user.$this->pass.$url.$qs);
    if (($download = $this->cacheGet($cache_key, $cache_for)) !== false) {
      return $download;
    }
    
    // TODO: implement file:// protocol here
    
    $this->ch = curl_init($url);
    
    // authenticate?
    if ($this->user) {
      curl_setopt($this->ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      curl_setopt($this->ch, CURLOPT_USERPWD, "$this->user:$this->pass");
    }
    
    // set headers
    $headers = array();
    foreach($this->headers as $name => $value) {
      $headers[] = "{$name}: {$value}";
    }
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
    
    // apply pre-set curl opts, allowing some (above) to be overwritten
    foreach($this->curlopts as $opt => $val) {
      curl_setopt($this->ch, $opt, $val);
    }
    
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    
    if ($this->method != self::METHOD_POST) {
      curl_setopt($this->ch, CURLOPT_HTTPGET, true);
    } else {
      curl_setopt($this->ch, CURLOPT_POST, true);
      curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->params);
    }
    
    if ($queue) {
      $download = new clDownload($this->ch, false);
      
    } else {
      $content = curl_exec($this->ch);
      $download = new clDownload($this->ch, $content);
      
      // cache?
      if ($download->is2__()) {
        $this->cacheSet($cache_key, $download, $cache_for);
      }
    }
    
    return $download;
  }
  
  function setDownload(&$download) {
    if (!($download instanceof clDownload)) {
      throw new Exception('$download must be of type clDownload');
    }
    
    $this->download = $download;
  }
  
  function cacheWith($clCache) {
    $this->cache = $clCache;
  }
  
  function cacheGet($cache_key, $cache_for = -1) {
    if (!$this->cache || COREYLIB_NOCACHE || $cache_for === -1 || $cache_for === false) {
      return false;
    }
    return $this->cache->get($cache_key);
  }
  
  function cacheSet($cache_key, $download, $cache_for = -1) {
    if (!$this->cache || COREYLIB_NOCACHE || $cache_for === -1) {
      return false;
    } else {
      return $this->cache->set($cache_key, $download, $cache_for);
    }
  }

  /**
   * Delete cache entry for this API.
   * Note that the cache key is generated from several components of the request,
   * including: the request method, the URL, the query string (parameters), and
   * any username or password used. Changing any one of these before executing
   * this function will modify the cache key used to store/retrieve the cached
   * response. So, make sure to fully configure your clApi instance before running 
   * this method.
   * @param string $override_method For feature parity with clApi->parse, allows
   * for overriding the HTTP method used in cache key generation. 
   * @return A reference to this clApi instance (to support method chaining)
   */
  function &flush($override_method = null) {
    $method = is_null($override_method) ? $this->method : $override_method;
    $qs = http_build_query($this->params);
    $url = ($method == self::METHOD_GET ? $this->url.($qs ? '?'.$qs : '') : $this->url);
    // use the URL to generate a cache key unique to request and any authentication data present
    $cache_key = md5($method.$this->user.$this->pass.$url.$qs);
    $this->cacheDel($cache_key);

    return $this;
  }
  
  function cacheDel($cache_key = null) {
    if (!$this->cache || COREYLIB_NOCACHE) {
      return false;
    } else {
      return $this->cache->del($cache_key);
    }
  }
  
  function getCacheKey() {
    return $this->cache_key;
  }
  
  function &getDownload() {
    return $this->download;
  }

  static $sort_by = null;

  /**
   * Given a collection of clNode objects, use $selector to query a set of nodes
   * from each, then (optionally) sort those nodes by one or more sorting filters.
   * Sorting filters should be specified <type>:<selector>, where <type> is one of
   * str, num, date, bool, or fx and <selector> is a valid node selector expression.
   * The value at <selector> in each node will be converted to <type>, and the 
   * collection will then be sorted by those converted values. In the special case
   * of fx, <selector> should instead be a callable function. The function (a custom)
   * sorting rule, should be implemented as prescribed by the usort documentation,
   * and should handle node value selection internally.
   * @param mixed $apis array(clNode), an array of stdClass objects (the return value of clApi::exec), a single clNode instance, or a URL to query
   * @param string $selector
   * @param string $sort_by
   * @return array(clNode) A (sometimes) sorted collection of clNode objects
   * @see http://www.php.net/manual/en/function.usort.php
   */
  static function &grep($nodes, $selector, $sort_by = null /* dynamic args */) {
    $args = func_get_args();
    $nodes = @array_shift($args);

    if (!$nodes) {
      return false;

    } else if (!is_array($nodes)) {
      if ($nodes instanceof clNode) {
        $nodes = array($nodes);
      } else {
        $api = new clApi((string) $nodes);
        if ($node = $api->parse()) {
          clApi::log("The URL [$nodes] did not parse, so clApi::grep fails.", E_USER_ERROR);
          return false;
        }
        $nodes = array($node);
      }
    }

    $selector = @array_shift($args);

    if (!$selector) {
      clApi::log('clApi::grep requires $selector argument (arg #2)', E_USER_WARNING);
      return false;
    }

    $sort_by = array();

    foreach($args as $s) {
      if (preg_match('/(.*?)\:(.*)/', $s, $matches)) {
        @list($type, $order) = preg_split('/,\s*/', $matches[1]);
        if (!$order) {
          $order = 'asc';
        }
        $sort_by[] = (object) array(
          'type' => $type,
          'order' => strtolower($order),
          'selector' => $matches[2] 
        );
      } else {
        clApi::log("clApi::grep $sort_by arguments must be formatted <type>:<selector>: [{$s}] is invalid.", E_USER_WARNING);
      }
    }

    // build the node collection
    $grepd = array();
    foreach($nodes as $node) {
      // automatically detect clApi::exec results...
      if ($node instanceof stdClass) {
        if ($node->parsed) {
          $grepd = array_merge( $grepd, $node->parsed->get($selector)->toArray() );
        } else {
          clApi::log(sprintf("clApi::grep can't sort failed parse on [%s]", $node->api->getUrl()), E_USER_WARNING);
        }
      } else {
        $grepd = array_merge( $grepd, $node->get($selector)->toArray() );
      }
    }

    // sort the collection
    foreach($sort_by as $s) {
      self::$sort_by = $s;
      usort($grepd, array('clApi', 'grep_sort'));
      if ($order == 'desc') {
        $grepd = array_reverse($grepd);
      }
    }

    return $grepd;
  }

  static function grep_sort($node1, $node2) {
    $sort_by = self::$sort_by;
    $v1 = $node1->get($sort_by->selector);
    $v2 = $node2->get($sort_by->selector);

    if ($sort_by->type == 'string') {
      $v1 = (string) $v1;
      $v2 = (string) $v2;
      return strcasecmp($v1, $v2);

    } else if ($sort_by->type == 'bool') {
      $v1 = (bool) (string) $v1;
      $v2 = (bool) (string) $v2;
      return ($v1 === $v2) ? 0 : ( $v1 === true ? -1 : 1 );

    } else if ($sort_by->type == 'num') {
      $v1 = (float) (string) $v1;
      $v2 = (float) (string) $v2;
      return ($v1 === $v2) ? 0 : ( $v1 < $v2 ? -1 : 1 );

    } else if ($sort_by->type == 'date') {
      $v1 = strtotime((string) $v1);
      $v2 = strtotime((string) $v2);
      return ($v1 === $v2) ? 0 : ( $v1 < $v2 ? -1 : 1 );

    }
  }
  
  /**
   * Use curl_multi to execute a collection of clApi objects.
   */
  static function exec($apis, $cache_for = -1, $override_method = null, $node_type = null) {
    $mh = curl_multi_init();
    
    $handles = array();
    
    foreach($apis as $a => $api) {
      if (is_string($api)) {
        $api = new clApi($api);
        $apis[$a] = $api;
      } else if (!($api instanceof clApi)) {
        throw new Exception("clApi::exec expects an Array of clApi objects.");
      }
      
      $download = $api->download(true, $cache_for, $override_method);
      $ch = $download->getCurl();
      
      if ($download->getContent() === false) {
        curl_multi_add_handle($mh, $ch);
      } else {
        $api->setDownload($download);
      }
      
      $handles[(int) $ch] = array($api, $download, $ch);
    }
    
    do {
      $status = curl_multi_exec($mh, $active);
    } while($status == CURLM_CALL_MULTI_PERFORM || $active);
    
    foreach($handles as $ch => $ref) {
      list($api, $download, $ch) = $ref;
      
      // update the download object with content and CH info 
      $download->update(curl_multi_getcontent($ch), curl_getinfo($ch));
      
      // if the download was a success
      if ($download->is2__()) {
        // cache the download
        $api->cacheSet($api->getCacheKey(), $download, $cache_for);
      }
      
      $api->setDownload($download);
    }
    
    $results = array();
    
    foreach($apis as $api) {
      $results[] = (object) array(
        'api' => $api,
        'parsed' => $api->parse($cache_for = -1, $override_method = null, $node_type = null)
      );
    }
    
    curl_multi_close($mh);
    
    return $results;
  }
  
  /**
   * Print $msg to the error log.
   * @param mixed $msg Can be a string, or an Exception, or any other object
   * @param int $level One of the E_USER_* error level constants.
   * @return string The value of $msg, post-processing
   * @see http://www.php.net/manual/en/errorfunc.constants.php
   */
  static function log($msg, $level = E_USER_NOTICE) {
    if ($msg instanceof Exception) {
      $msg = $msg->getMessage();
    } else if (!is_string($msg)) {
      $msg = print_r($msg, true);
    }

    if ($level == E_USER_NOTICE && !COREYLIB_DEBUG) {
      // SHHH...
      return $msg;
    }
    
    trigger_error($msg, $level);
    
    return $msg;
  }
  
}

if (!function_exists('coreylib')):
  function coreylib($url, $cache_for = -1, $params = array(), $method = clApi::METHOD_GET) {
    $api = new clApi($url);
    $api->param($params);
    if ($node = $api->parse($cache_for, $method)) {
      return $node;
    } else {
      return false;
    }
  }
endif;

class clDownload {
  
  private $content = '';
  private $ch;
  private $info;
  
  function __construct(&$ch = null, $content = false) {
    $this->ch = $ch;
    $this->info = curl_getinfo($this->ch);
    $this->content = $content;
  }
  
  function __sleep() {
    return array('info', 'content');
  }
 
  function getContent() {
    return $this->content;
  }
  
  function update($content, $info) {
    $this->content = $content;
    $this->info = $info;
  }
  
  function hasContent() {
    return (bool) strlen(trim($this->content));
  }
  
  function &getCurl() {
    return $this->ch;
  }
  
  function getInfo() {
    return $this->info;
  }
  
  private static $xmlContentTypes = array(
    'text/xml',
    'application/rss\+xml',
    'xml'
  );
  
  function isXml() {
    if (preg_match(sprintf('#(%s)#i', implode('|', self::$xmlContentTypes)), $this->info['content_type'])) {
      return true;
    } else if (stripos('<?xml', trim($this->content)) === 0) {
      return true;
    } else {
      return false;
    }
  }
  
  private static $jsonContentTypes = array(
    'text/javascript',
    'application/x-javascript',
    'application/json',
    'text/x-javascript',
    'text/x-json',
    '.*json.*'
  );
  
  function isJson() {
    if (preg_match(sprintf('#(%s)#i', implode('|', self::$jsonContentTypes)), $this->info['content_type'])) {
      return true;
    } else if (substr(trim($this->content), 0) === '{' && substr(trim($this->content), -1) === '}') {
      return true;
    } else if (substr(trim($this->content), 0) === '[' && substr(trim($this->content), -1) === ']') {
      return true;
    } else {
      return false;
    }
  }
  
  function __call($name, $args) {
    if (preg_match('/^is(\d+)(_)?(_)?$/', $name, $matches)) {
      $status = $this->info['http_code'];
      
      if (!$status) {
        return false;
      }
      
      $http_status_code = $matches[1];
      $any_ten = @$matches[2];
      $any_one = @$matches[3];
      
      if ($any_ten || $any_one) {
        for($ten = 0; $ten <= ($any_ten ? 0 : 90); $ten+=10) {
          for($one = 0; $one <= (($any_ten || $any_one) ? 0 : 9); $one++) {
            $code = $http_status_code . ($ten == 0 ? '0' : '') . ($ten + $one);
            if ($code == $status) {
              return true;
            }
          }
        }
      } else if ($status == $http_status_code) {
        return true;
      } else {
        return false;
      }
    } else {
      throw new clException("Call to unknown function: $name");
    }
  }
  
}
// src/cache.php


/**
 * Core caching pattern.
 */
abstract class clCache {
 
  /**
   * Get the value stored in this cache, uniquely identified by $cache_key.
   * @param string $cache_key The cache key
   * @param bool $return_raw Instead of returning the cached value, return a packet
   *   of type stdClass, with two properties: expires (the timestamp
   *   indicating when this cached data should no longer be valid), and value
   *   (the unserialized value that was cached there)
   */
  abstract function get($cache_key, $return_raw = false);
  
  /**
   * Update the cache at $cache_key with $value, setting the expiration
   * of $value to a moment in the future, indicated by $timeout.
   * @param string $cache_key Uniquely identifies this cache entry
   * @param mixed $value Some arbitrary value; can be any serializable type
   * @param mixed $timeout An expression of time or a positive integer indicating the number of seconds;
   *   a $timeout of 0 indicates "cache indefinitely."
   * @return a stdClass instance with two properties: expires (the timestamp
   * indicating when this cached data should no longer be valid), and value
   * (the unserialized value that was cached there)
   * @see http://php.net/manual/en/function.strtotime.php
   */
  abstract function set($cache_key, $value, $timeout = 0);
  
  /**
   * Remove from the cache the value uniquely identified by $cache_key
   * @param string $cache_key
   * @return true when the cache key existed; otherwise, false
   */
  abstract function del($cache_key);
  
  /**
   * Remove all cache entries.
   */
  abstract function flush();
  
  /** 
   * Store or retrieve the global cache object.
   */
  static $cache;
  static function cache($cache = null) {
    if (!is_null($cache)) {
      if (!($cache instanceof clCache)) {
        throw new Exception('Object %s does not inherit from clCache', get_class($object));
      }
      self::$cache = new clStash($cache);
    }
    
    if (!self::$cache) {
      try {
        // default is FileCache
        $class = COREYLIB_DEFAULT_CACHE_STRATEGY;
        $cache = new $class();
        self::$cache = new clStash($cache);
      } catch (Exception $e) {
        clApi::log($e, E_USER_WARNING);
        return false;
      }
    }
    
    return self::$cache;
  }

  private static $buffers = array();

  /**
   * Attempt to find some cached content. If it's found, echo
   * the content, and return true. If it's not found, invoke ob_start(),
   * and return false. In the latter case, the calling script should
   * next proceed to generate the content to be cached, then, the
   * script should call clCache::save(), thus caching the content and
   * printing it at the same time.
   * @param string $cache_key
   * @param mixed $cache_for An expression of how long the content 
   * should be cached
   * @param clCache $cache Optionally, a clCache implementation other
   * than the global default
   * @return mixed - see codedoc above
   */
  static function cached($cache_key, $cache_for = -1, $cache = null) {
    $cache = self::cache($cache);

    if ($cached = $cache->get($cache_key, true)) {
      if ($cached->expires != 0 && $cached->expires <= self::time()) {
        self::$buffers[] = (object) array(
          'cache' => $cache,
          'cache_key' => $cache_key,
          'cache_for' => $cache_for
        );
        ob_start();
        return false;
      } else {
        echo $cached->value;
        return true;
      }
    } else {
      self::$buffers[] = (object) array(
        'cache' => $cache,
        'cache_key' => $cache_key,
        'cache_for' => $cache_for
      );
      ob_start();
      return false;
    }
  }

  /**
   * Save the current cache buffer.
   * @see clCache::cached
   */
  static function save($cache_for = null) {
    if ($buffer = array_pop(self::$buffers)) {
      $buffer->cache->set($buffer->cache_key, ob_get_flush(), $cache_for ? $cache_for : $buffer->cache_for);
    } else {
      clApi::log("clCache::save called, but no buffer was open", E_USER_WARNING);
    }   
  }

  /**
   * Cancel the current cache buffer.
   * @see clCache::cached
   */
  static function cancel() {
    if (!array_pop(self::$buffers)) {
      clApi::log("clCache::cancel called, but no buffer was open");
    }
  }

  /**
   * Read data from the global clCache instance.
   */
  static function read($cache_key) {
    $cache = self::cache();
    return $cache->get($cache_key);
  }

  /**
   * Delete content cached in the global default clCache instance.
   */
  static function delete($cache_key) {
    $cache = self::cache();
    $cache->del($cache_key);
  }
  
  /**
   * Write content to the global clCache instance.
   */
  static function write($cache_key, $value, $timeout = -1) {
    $cache = self::cache();
    return $cache->set($cache_key, $value, $timeout);
  }

  /**
   * Convert timeout expression to timestamp marking the moment in the future
   * at which point the timeout (or expiration) would occur.
   * @param mixed $timeout An expression of time or a positive integer indicating the number of seconds
   * @see http://php.net/manual/en/function.strtotime.php
   * @return a *nix timestamp in the future, or the current time if $timeout is 0, always in GMT.
   */
  static function time($timeout = 0) {
    if ($timeout === -1) {
      return false;
    }
    
    if (!is_numeric($timeout)) {
      $original = trim($timeout);
  
      // normalize the expression: should be future
      $firstChar = substr($timeout, 0, 1);
      if ($firstChar == "-") {
        $timeout = substr($timeout, 1);
      } else if ($firstChar != "-") {
        if (stripos($timeout, 'last') === false) {
          $timeout = str_replace('last', 'next', $timeout);
        }
      }
      
      if (($timeout = strtotime(gmdate('c', strtotime($timeout)))) === false) {
        clApi::log("'$original' is an invalid expression of time.", E_USER_WARNING);
        return false;
      }
            
      return $timeout;
    } else {
      return strtotime(gmdate('c'))+$timeout;
    }
  }

  /**
   * Produce a standard cache packet.
   * @param $value to be wrapped
   * @return stdClass
   */
  static function raw(&$value, $expires) {
    return (object) array(
      'created' => self::time(),
      'expires' => $expires,
      'value' => $value
    );
  }
  
}

/**
 * A proxy for another caching system -- stashes the cached
 * data in memory, for fastest possible access. 
 */
class clStash extends clCache {
  
  private $proxied;
  private $mem = array();
  
  function __construct($cache) {
    if (is_null($cache)) {
      throw new clException("Cache object to proxy cannot be null.");
    } else if (!($cache instanceOf clCache)) {
      throw new clException("Cache object must inherit from clCache");
    }
    $this->proxied = $cache;
  }
  
  function get($cache_key, $return_raw = false) {
    if ($stashed = @$this->mem[$cache_key]) {
      // is the stash too old?
      if ($stashed->expires != 0 && $stashed->expires <= self::time()) {
        // yes, stash is too old. try to resource, just in case
        if ($raw = $this->proxied->get($cache_key, true)) {
          // there was something fresher in the proxied cache, to stash it
          $this->mem[$cache_key] = $raw;
          // then return the requested data
          return $return_raw ? $raw : $raw->value;
        // nope... we got nothing
        } else {
          return false;
        }
      // no, the stash was not too old
      } else {
        clApi::log("Cached data loaded from memory [{$cache_key}]");
        return $return_raw ? $stashed : $stashed->value;
      }
    // there was nothing in the stash:
    } else {
      // try to retrieve from the proxied cache:
      if ($raw = $this->proxied->get($cache_key, true)) {
        // there was a value in the proxied cache:
        $this->mem[$cache_key] = $raw;
        return $return_raw ? $raw : $raw->value;
      // nothing in the proxied cache:
      } else {
        return false;
      }
    }
  }
  
  function set($cache_key, $value, $timeout = 0) {
    return $this->mem[$cache_key] = $this->proxied->set($cache_key, $value, $timeout);
  }
  
  function del($cache_key) {
    unset($this->mem[$cache_key]);
    return $this->proxied->del($cache_key);
  }
  
  function flush() {
    $this->mem[] = array();
    $this->proxied->flush();
  }
  
}

/**
 * Caches data to the file system.
 */
class clFileCache extends clCache {
  
  function get($cache_key, $return_raw = false) {
    // seek out the cached data
    if (@file_exists($path = $this->path($cache_key))) {
      // if the data exists, try to load it into memory
      if ($content = @file_get_contents($path)) {
        // if it can be read, try to unserialize it
        if ($raw = @unserialize($content)) {
          // if it's not expired
          if ($raw->expires == 0 || self::time() < $raw->expires) {
            // return the requested data type
            return $return_raw ? $raw : $raw->value;
          // otherwise, purge the file, note the expiration, and move on
          } else {
            @unlink($path);
            clApi::log("Cache was expired [{$cache_key}:{$path}]");
            return false;
          }
        // couldn't be unserialized
        } else {
          clApi::log("Failed to unserialize cache file: {$path}", E_USER_WARNING);
        }
      // data couldn't be read, or the cache file was empty
      } else {
        clApi::log("Failed to read cache file: {$path}", E_USER_WARNING);
      }
    // cache file did not exist
    } else {
      clApi::log("Cache does not exist [{$cache_key}:{$path}]");
      return false;
    }
  }
  
  function set($cache_key, $value, $timeout = 0) {
    // make sure $timeout is valid
    if (($expires = self::time($timeout)) === false) {
      return false;
    }
    
    if ($serialized = @serialize($raw = self::raw($value, $expires))) {
      if (!@file_put_contents($path = $this->path($cache_key), $serialized)) {
        clApi::log("Failed to write cache file: {$path}", E_USER_WARNING);
      } else {
        return $raw;
      }
    } else {
      clApi::log("Failed to serialize cache data [{$cache_key}]", E_USER_WARNING);
      return false;
    }
  }
  
  function del($cache_key) {
    if (@file_exists($path = $this->path($cache_key))) {
      return @unlink($path);
    } else {
      return false;
    }
  }
  
  function flush() {
    if ($dir = opendir($this->basepath)) {
      while($file = readdir($dir)) {
        if (preg_match('#\.coreylib$#', $file)) {
          @unlink($this->basepath . DIRECTORY_SEPARATOR . $file);
        }
      }
      closedir($this->basepath);
    }
  }
  
  private $basepath;
  
  /**
   * @throws clException When the path that is to be the basepath for the cache
   * files cannot be created and/or is not writable.
   */
  function __construct($root = null) {
    if (is_null($root)) {
      $root = realpath(dirname(__FILE__));
    }
    // prepend the coreylib folder
    $root .= DIRECTORY_SEPARATOR . COREYLIB_FILECACHE_DIR;
    // if it doesn't exist
    if (!@file_exists($root)) {
      // create it
      if (!@mkdir($root)) {
        throw new clException("Unable to create File Cache basepath: {$root}");
      }
    } 
    
    // otherwise, if it's not writable
    if (!is_writable($root)) {
      throw new clException("File Cache basepath exists, but is not writable: {$root}");
    }
    
    $this->basepath = $root;
  }
  
  private static $last_path;
  
  /**
   * Generate the file path.
   */
  private function path($cache_key = null) {
    return self::$last_path = $this->basepath . DIRECTORY_SEPARATOR . md5($cache_key) . '.coreylib';
  }
  
  static function getLastPath() {
    return self::$last_path;
  }
  
}

/**
 * Caches data to the WordPress database.
 */
class clWordPressCache extends clCache {
  
  private $wpdb;
  
  function __construct() {
    global $wpdb;
    
    $wpdb->coreylib = $wpdb->prefix.'coreylib';
    
    $wpdb->query("
      CREATE TABLE IF NOT EXISTS $wpdb->coreylib (
        `cache_key` VARCHAR(32) NOT NULL,
        `value` TEXT,
        `expires` DATETIME,
        `created` DATETIME,
        PRIMARY KEY(`cache_key`)
      );
    ");
    
    if (!$wpdb->get_results("SHOW TABLES LIKE '$wpdb->coreylib'")) {
      clApi::log("Failed to create coreylib table for WordPress: {$wpdb->coreylib}");
    } else {
      $this->wpdb =& $wpdb;
    }
  }
  
  function get($cache_key, $return_raw = false) {
    if (!$this->wpdb) {
      return false;
    }
    
    // prepare the SQL
    $sql = $this->wpdb->prepare("SELECT * FROM {$this->wpdb->coreylib} WHERE `cache_key` = %s LIMIT 1", $cache_key);
    // seek out the cached data
    if ($raw = $this->wpdb->get_row($sql)) {
      // convert MySQL date strings to timestamps
      $raw->expires = is_null($raw->expires) ? 0 : strtotime($raw->expires);
      $raw->created = strtotime($raw->created);
      $raw->value = maybe_unserialize($raw->value);
      // if it's not expired
      if (is_null($raw->expires) || self::time() < $raw->expires) {
        // return the requested data type
        return $return_raw ? $raw : $raw->value;
      // otherwise, purge the file, note the expiration, and move on
      } else {
        $this->del($cache_key);
        clApi::log("Cache was expired {$this->wpdb->coreylib}[{$cache_key}]");
        return false;
      }
    
    // cache did not exist
    } else {
      clApi::log("Cache record does not exist {$this->wpdb->coreylib}[{$cache_key}]");
      return false;
    }
  }
  
  function set($cache_key, $value, $timeout = 0) {
    if (!$this->wpdb) {
      return false;
    }
    
    // make sure $timeout is valid
    if (($expires = self::time($timeout)) === false) {
      return false;
    }
    
    // if the value can be serialized
    if ($serialized = maybe_serialize($value)) {
      // prepare the SQL
      $sql = $this->wpdb->prepare("
        REPLACE INTO {$this->wpdb->coreylib} 
        (`cache_key`, `created`, `expires`, `value`) 
        VALUES 
        (%s, %s, %s, %s)
      ", 
        $cache_key,
        $created = date('Y/m/d H:i:s', self::time()),
        $expires = date('Y/m/d H:i:s', $expires),
        $serialized
      );
      
      // insert it!
      $this->wpdb->query($sql);
      if ($this->wpdb->query($sql)) {
        clApi::log("Stored content in {$this->wpdb->coreylib}[{$cache_key}]");
      } else {
        clApi::log("Failed to store content in {$this->wpdb->coreylib}[{$cache_key}]", E_USER_WARNING);
      }
      
      return (object) array(
        'expires' => $expires,
        'created' => $created,
        'value' => value
      );
    } else {
      clApi::log("Failed to serialize cache data [{$cache_key}]", E_USER_WARNING);
      return false;
    }
  }
  
  function del($cache_key) {
    if (!$this->enabled) {
      return false;
    }
    // prepare the SQL
    $sql = $this->wpdb->prepare("DELETE FROM {$this->wpdb->coreylib} WHERE `cache_key` = %s LIMIT 1", $cache_key);
    return $this->wpdb->query($sql);
  }
  
  function flush() {
    if (!$this->wpdb) {
      return false;
    }
    
    $this->wpdb->query("TRUNCATE {$this->wpdb->coreylib}");
  }
  
}

function coreylib_set_cache($cache) {
  clCache::cache($cache);
}

function coreylib_get_cache() {
  return clCache::cache();
}

function coreylib_flush() {
  if ($cache = clCache::cache()) {
    $cache->flush();
  }
}

function cl_cached($cache_key, $cache_for = -1, $cache = null) {
  return clCache::cached($cache_key, $cache_for, $cache);
}

function cl_save($cache_for = null) {
  return clCache::save($cache_for);
}

function cl_cancel() {
  return clCache::cancel();
}

function cl_delete($cache_key) {
  return clCache::delete($cache_key);
}

function cl_read($cache_key) {
  return clCache::read($cache_key);
}

function cl_write($cache_key) {
  return clCache::write($cache_key);
}
// src/node.php


/**
 * Parser for jQuery-inspired selector syntax.
 */
class clSelector implements ArrayAccess, Iterator {
  
  static $regex;
  
  static $attrib_exp;
  
  private $selectors = array();
  
  private $i = 0;
  
  static $tokenize = array('#', ';', '&', ',', '.', '+', '*', '~', "'", ':', '"', '!', '^', '$', '[', ']', '(', ')', '=', '>', '|', '/', '@', ' ');
  
  private $tokens;
  
  function __construct($query, $direct = null) {
    if (!self::$regex) {
      self::$regex = self::generateRegEx();
    }

    $tokenized = $this->tokenize($query);
    
    $buffer = '';
    $eos = false;
    $eoq = false;
    $direct_descendant_flag = false;
    $add_flag = false;
    
    // loop over the tokenized query
    for ($c = 0; $c<strlen($tokenized); $c++) {
      // is this the end of the query?
      $eoq = ($c == strlen($tokenized)-1);
      
      // get the current character
      $char = $tokenized{$c};
      
      // note descendants-only rule
      if ($char == '>') {
        $direct_descendant_flag = true;
      }
      
      // note add-selector-result rule
      else if ($char == ',') {
        $add_flag = true;
        $eos = true;
      }
      
      // is the character a separator?
      else if ($char == '/' || $char == "\t" || $char == "\n" || $char == "\r" || $char == ' ') {
        // end of selector reached
        $eos = strlen($buffer) && true;
      }
      
      else {
        $buffer .= $char;
      }
      
      if (strlen($buffer) && ($eoq || $eos)) {
        
        $sel = trim($buffer);
        
        // reset the buffer
        $buffer = '';
        $eos = false;
        
        // process and clear buffer
        if (!preg_match(self::$regex, $sel, $matches)) {
          throw new clException("Failed to parse [$sel], part of query [$query].");
        }

        $sel = (object) array(
          'element' => $this->untokenize(@$matches['element']),
          'is_expression' => ($this->untokenize(@$matches['attrib_exp']) != false),
          // in coreylib v1, passing "@attributeName" retrieved a scalar value;
          'is_attrib_getter' => preg_match('/^@.*$/', $query),
          // defaults for these:
          'attrib' => null,
          'value' => null,
          'suffixes' => null,
          'test' => null,
          'direct_descendant_flag' => $direct_descendant_flag || ((!is_null($direct)) ? $direct : false),
          'add_flag' => $add_flag
        );
        
        $direct_descendant_flag = false;
        $add_flag = false;

        // default element selection is "all," as in all children of current node
        if (!$sel->element && !$sel->is_attrib_getter) {
          $sel->element = '*';
        }

        if ($exp = @$matches['attrib_exp']) {
          // multiple expressions?
          if (strpos($exp, '][') !== false) {
            $attribs = array();
            $values = array();
            $tests = array();

            $exps = explode('][', substr($exp, 1, strlen($exp)-2));
            foreach($exps as $exp) {
              if (preg_match('#'.self::$attrib_exp.'#', "[{$exp}]", $matches)) {
                $attribs[] = $matches['attrib_exp_name'];
                $tests[] = $matches['test'];
                $values[] = $matches['value'];
              }
            }

            $sel->attrib = $attribs;
            $sel->value = $values;
            $sel->test = $tests;
          // just one expression
          } else {
            $sel->attrib = array($this->untokenize(@$matches['attrib_exp_name']));
            $sel->value = array($this->untokenize(@$matches['value']));
            $sel->test = array(@$matches['test']);
          }
        // no expression
        } else {
          $sel->attrib = $this->untokenize(@$matches['attrib']);
        }

        if ($suffixes = @$matches['suffix']) {
          $all = array_filter(explode(':', $suffixes));
          $suffixes = array();

          foreach($all as $suffix) {
            $open = strpos($suffix, '(');
            $close = strrpos($suffix, ')');
            if ($open !== false && $close !== false) {
              $label = substr($suffix, 0, $open);
              $val = $this->untokenize(substr($suffix, $open+1, $close-$open-1));
            } else {
              $label = $suffix;
              $val = true;
            }
            $suffixes[$label] = $val;
          }

          $sel->suffixes = $suffixes;
        }

        // alias for eq(), and backwards compat with coreylib v1
        if (!isset($sel->suffixes['eq']) && ($index = @$matches['index'])) {
          $sel->suffixes['eq'] = $index;
        }

        $this->selectors[] = $sel;
      }
    }
  }
  
  private function tokenize($string) {
    $tokenized = false;
    foreach(self::$tokenize as $t) {
      while(($at = strpos($string, "\\$t")) !== false) {
        $tokenized = true;
        $token = "TKS".count($this->tokens)."TKE";
        $this->tokens[] = $t;
        $string = substr($string, 0, $at).$token.substr($string, $at+2);
      }
    }
    return $tokenized ? 'TK'.$string : $string;
  }
  
  private function untokenize($string) {
    if (!$string || strpos($string, 'TK') !== 0) {
      return $string;
    } else {
      foreach($this->tokens as $i => $t) {
        $token = "TKS{$i}TKE";
        $string = preg_replace("/{$token}/", $t, $string);
      }
      return substr($string, 2);
    }
  }
  
  function __get($name) {
    $sel = @$this->selectors[$this->i];
    return $sel->{$name};
  }
  
  function has_suffix($name) {
    $sel = $this->selectors[$this->i];
    return @$sel->suffixes[$name];
  }
  
  function index() {
    return $this->i;
  }
  
  function size() {
    return count($this->selectors);
  }
  
  function current() {
    return $this->selectors[$this->i];
  }
  
  function key() {
    return $this->i;
  }
  
  function next() {
    $this->i++;
  }
  
  function rewind() {
    $this->i = 0;
  }
  
  function valid() {
    return isset($this->selectors[$this->i]);
  }
  
  function offsetExists($offset) {
    return isset($this->selectors[$offset]);
  }
  
  function offsetGet($offset) {
    return $this->selectors[$offset];
  }
  
  function offsetSet($offset, $value) {
    throw new clException("clSelector objects are read-only.");
  }
  
  function offsetUnset($offset) {
    throw new clException("clSelector objects are read-only.");
  }
  
  function getSelectors() {
    return $this->selectors;
  }
  
  static function generateRegEx() {
    // characters comprising valid names
    // should not contain any of the characters in self::$tokenize
    $name = '[A-Za-z0-9\_\-]+';
    
    // element express with optional index
    $element = "((?P<element>(\\*|{$name}))(\\[(?P<index>[0-9]+)\\])?)";
    
    // attribute expression 
    $attrib = "@(?P<attrib>{$name})";
    
    // tests of equality
    $tests = implode('|', array(
      // Selects elements that have the specified attribute with a value either equal to a given string or starting with that string followed by a hyphen (-).
      "\\|=",
      // Selects elements that have the specified attribute with a value containing the a given substring.
      "\\*=",
      // Selects elements that have the specified attribute with a value containing a given word, delimited by whitespace.
      "~=",
      // Selects elements that have the specified attribute with a value ending exactly with a given string. The comparison is case sensitive.
      "\\$=",
      // Selects elements that have the specified attribute with a value exactly equal to a certain value.
      "=",
      // Select elements that either don't have the specified attribute, or do have the specified attribute but not with a certain value.
      "\\!=",
      // Selects elements that have the specified attribute with a value beginning exactly with a given string.
      "\\^="
    ));
    
    // suffix selectors
    $suffixes = implode('|', array(
      // retun nth element
      ":eq\\([0-9]+\\)",
      // return the first element
      ":first",
      // return the last element
      ":last",
      // greater than index
      ":gt\\([0-9]+\\)",
      // less than index
      ":lt\\([0-9]+\\)",
      // even only
      ":even",
      // odd only
      ":odd",
      // empty - no children, no text
      ":empty",
      // parent - has children: text nodes count
      ":parent",
      // has - contains child element
      ":has\\([^\\)]+\\)",
      // text - text node in the element is
      ":contains\\([^\\)]+\\)"
    ));
    
    $suffix_exp = "(?P<suffix>({$suffixes})+)";
    
    // attribute expression
    self::$attrib_exp = $attrib_exp = "\\[@?((?P<attrib_exp_name>{$name})((?P<test>{$tests})\"(?P<value>.*)\")?)\\]";
    
    // the final expression
    return "#^{$element}?(({$attrib})|(?P<attrib_exp>{$attrib_exp}))*{$suffix_exp}*$#";
  }
  
}

class clNodeArray implements ArrayAccess, Iterator {
  
  private $arr = array();
  private $i;
  private $root;
  
  function __construct($arr = null, $root = null) {
    $this->root = $root;
    
    if (!is_null($arr)) {
      if ($arr instanceof clNodeArray) {
        $this->arr = $arr->toArray();
      } else {
        $this->arr = $arr;
      }
    }
  }
  
  function toArray() {
    return $this->arr;
  }
  
  function __get($name) {
    if ($node = @$this->arr[0]) {
      return $node->{$name};
    } else {
      return null;
    }
  }
  
  function __call($name, $args) {
    if (($node = @$this->arr[0]) && is_object($node)) {
      return call_user_func_array(array($node, $name), $args);
    } else if (!is_null($node)) {
      throw new Exception("Value in clNodeArray at index 0 is not an object.");
    }
  }
  
  function size() {
    return count($this->arr);
  }
  
  /**
   * Run a selector query on the direct descendants of these nodes.
   * @return new clNodeArray containing direct descendants
   */
  function children($selector = '*') {
    $sel = $selector;
    if (!is_object($sel)) {
      $sel = new clSelector($sel, true);
    }
    
    $children = array();
    foreach($this->arr as $node) {
      $children = array_merge($children, $node->get($sel)->toArray());
      $sel->rewind();
    }
    
    return new clNodeArray($children, $this->root);
  }
  
  /**
   * Requery the root, and append the results to the stored array.
   * @return Reference to this clNodeArray (supports method chaining)
   */
  function add($selector = '*') {
    $this->arr = array_merge($this->arr, $this->root->get($selector)->toArray());    
    return $this;
  }
  
  function current() {
    return $this->arr[$this->i];
  }
  
  function key() {
    return $this->i;
  }
  
  function next() {
    $this->i++;
  }
  
  function rewind() {
    $this->i = 0;
  }
  
  function valid() {
    return isset($this->arr[$this->i]);
  }
  
  function offsetExists($offset) {
    if (is_string($offset)) {
      if ($node = @$this->arr[0]) {
        return isset($node[$offset]);
      } else {
        return false;
      }
    } else {
      return isset($this->arr[$offset]);
    }
  }
  
  function offsetGet($offset) {
    if (is_string($offset)) {
      if ($node = @$this->arr[0]) {
        return @$node[$offset];
      } else {
        return null;
      }
    } else {
      return @$this->arr[$offset];
    }
  }
  
  function offsetSet($offset, $value) {
    throw new clException("clNodeArray objects are read-only.");
  }
  
  function offsetUnset($offset) {
    throw new clException("clNodeArray objects are read-only.");
  }
  
  function __toString() {
    if ($node = @$this->arr[0]) {
      return (string) $node;
    } else {
      return '';
    }
  }
  
}

/**
 * Models a discreet unit of data. This unit of data can have attributes (or properties)
 * and children, themselves instances of clNode. Implements ArrayAccess, exposing attribute()
 * function.
 */
abstract class clNode implements ArrayAccess {
  
  /**
   * Factory method: return the correct type of clNode.
   * @param $string The content to parse
   * @param string $type The type - supported include "xml" and "json"
   * @return clNode implementation 
   */
  static function getNodeFor($string, $type) {
    if ($type == 'xml') {
      $node = new clXmlNode();
    } else if ($type == 'json') {
      $node = new clJsonNode();
    } else {
      throw new clException("Unsupported Node type: $type");
    }
    
    if (!$node->parse($string)) {
      return false;
    } else {
      return $node;
    }
  }
  
  function offsetExists($offset) {
    $att = $this->attribute($offset);
    return !is_null($att);
  }
  
  function offsetGet($offset) {
    return $this->attribute($offset);
  }
  
  function offsetSet($offset, $value) {
    throw new clException("clNode objects are read-only.");
  }
  
  function offsetUnset($offset) {
    throw new clException("clNode objects are read-only.");
  }
  
  /**
   * @return array key/value pairs for all of the attributes in this node
   */
  function &attribs() {
    $attribs = array();
    foreach($this->attribute() as $name => $value) {
      $attribs[$name] = $value;
    }
    return $attribs;
  }
  
  /**
   * @return array A representation of the data available in this node
   * and its children, suitable for exploring with print_r
   * @see http://php.net/manual/en/function.print-r.php
   */
  function &toArray($top_level = false) {
    $children = array();
    
    foreach($this->descendants('', true) as $child) {
      $name = $child->name();
      if (isset($children[$name])) {
        if (!is_array($children[$name])) {
          $children[$name] = array($children[$name]);
        }
        $children[$name][] = (object) array_filter(array(
          'text' => trim($child->__toString()),
          'children' => $child->toArray(false),
          'attribs' => $child->attribs()
        ));
      } else {
        $children[$name] = (object) array_filter(array(
          'text' => trim($child->__toString()),
          'children' => $child->toArray(false),
          'attribs' => $child->attribs()
        ));
      }
    }
    
    if ($top_level) {
      $array = (object) array($this->name() => (object) array_filter(array(
        'text' => trim($this->__toString()),
        'children' => $children,
        'attribs' => $this->attribs()
      )));
    } else {
      $array = $children;
    }
    
    return $array;
  }
  
  /** 
   * @return JSON-encoded representation of the data available in this node
   * and its children.
   */
  function toJson() {
    return json_encode($this->toArray(true));
  }
  
  /**
   * Print a <script></script> block that spits this node's content into the JavaScript console
   */
  function inspect() {
    ?>
      <script>
        console.log(<?php echo $this->toJson() ?>);
      </script>
    <?php
  }
  
  /**
   * Retrieve the first element or attribute queried by $selector.
   * @param string $selector
   * @return mixed an instance of a clNode subclass, or a scalar value
   * @throws clException When an attribute requested does not exist.
   */ 
  function first($selector) {
    $values = $this->get($selector);
    return is_array($values) ? @$values[0] : $values;
  }
  
  /**
   * Retrieve the last element or attribute queried by $selector.
   * @param string $selector
   * @return mixed an instance of a clNode subclass, or a scalar value
   * @throws clException When an attribute requested does not exist.
   */ 
  function last($selector) {
    $values = $this->get($selector);
    return is_array($values) ? @array_pop($values) : $values;
  }
  
  /**
   * Retrieve some data from this Node and/or its children.
   * @param mixed $selector A query conforming to the coreylib selector syntax, or an instance of clSelector
   * @param int $limit A limit on the number of values to return
   * @param array &$results Results from the previous recursive iteration of ::get
   * @return mixed A clNodeArray or a single value, given to $selector.
   */
  function get($selector, $limit = null, &$results = null) {
    // shorten the variable name, for convenience
    $sel = $selector;
    if (!is_object($sel)) {
      $sel = new clSelector($sel);
      if (!$sel->valid()) {
        // nothing to process
        return new clNodeArray(null, $this);
      }
    }
    
    if (is_null($results)) {
      $results = array($this);
    } else if (!is_array($results)) {
      $results = array($results);
    } 
    
    if ($sel->element) {
      $agg = array();
      foreach($results as $child) {
        if (is_object($child)) {
          $agg = array_merge($agg, $child->descendants($sel->element, $sel->direct_descendant_flag));
        }
      }
      $results = $agg;
      
      if (!count($results)) {
        return new clNodeArray(null, $this);
      }
    } 
    
    if ($sel->attrib) {
      if ($sel->is_expression) {
        $agg = array();
        foreach($results as $child) {
          if ($child->has_attribute($sel->attrib, $sel->test, $sel->value)) {
            $agg[] = $child;
          }
        }
        $results = $agg;
        
      } else {
        $agg = array();
        foreach($results as $child) {
          if (is_object($child)) {
            $att = $child->attribute($sel->attrib);
            if (is_array($att)) {
              $agg = array_merge($agg, $att);
            } else {
              $agg[] = $att;
            }
          }
        }
        
        // remove empty values and reset index
        $agg = array_values(array_filter($agg));
        
        if ($sel->is_attrib_getter) {
          return @$agg[0];
        } else {
          $results = $agg;
        }
      }
      
      if (!count($results)) {
        return new clNodeArray(null, $this);
      }
    }
    
    if ($sel->suffixes) {
      foreach($sel->suffixes as $suffix => $val) { 
        if ($suffix == 'gt') {
          $results = array_slice($results, $index);
        
        } else if ($suffix == 'lt') {
          $results = array_reverse(array_slice(array_reverse($results), $index));
      
        } else if ($suffix == 'first') {
          $results = array(@$results[0]);
      
        } else if ($suffix == 'last') {
          $results = array(@array_pop($results));
        
        } else if ($suffix == 'eq') {
          $results = array(@$results[$val]);

        } else if ($suffix == 'empty') {
          $agg = array();
          foreach($results as $r) {
            if (is_object($r)) {
              if (!count($r->descendants()) && ((string) $r) == '') {
                $agg[] = $r;
              }
            }
          }
          $results = $agg;
        
        } else if ($suffix == 'parent') {
          $agg = array();
          foreach($results as $r) {
            if (is_object($r)) {
              if (((string) $r) != '' || count($r->descendants())) {
                $agg[] = $r;
              }
            }
          }
          $results = $agg;
          
        } else if ($suffix == 'has') {
          $agg = array();
          foreach($results as $r) {
            if (is_object($r)) {
              if (count($r->descendants($val))) {
                $agg[] = $r;
              }
            }
          }
          $results = $agg;
          
        } else if ($suffix == 'contains') {
          $agg = array();
          foreach($results as $r) {
            if (is_object($r)) {
              if (strpos((string) $r, $val) !== false) {
                $agg[] = $r;
              }
            }
          }
          $results = $agg;
          
        } else if ($suffix == 'even') {
          $agg = array();
          foreach($results as $i => $r) {
            if ($i % 2 === 0) {
              $agg[] = $r;
            }
          }
          $results = $agg;
          
        } else if ($suffix == 'odd') {
          $agg = array();
          foreach($results as $i => $r) {
            if ($i % 2) {
              $agg[] = $r;
            }
          }
          $results = $agg;
          
        }
      }
      
      if (!count($results)) {
        return new clNodeArray(null, $this);
      }
    }
      
    
    // append the results of the next selector?
    if ($sel->add_flag) {
      $sel->next();
      if ($sel->valid()) {
        $results = array_merge($results, $this->get($sel, null)->toArray());
      }
    } else {
      // recursively use ::get to draw the lowest-level values
      $sel->next();
      if ($sel->valid()) {
        $results = $this->get($sel, null, $results);
      }  
    }
    
    // limit, if requested
    if ($limit && is_array($results)) {
      $results = array_slice($results, 0, $limit);
    }
    
    return new clNodeArray($results, $this);
  }
  
  /**
   * Should return either an array or a single value, given to $selector:
   * if selector is undefined, return an array of all attributes as a 
   * hashtable; otherwise, return the attribute's value, or if the attribute
   * does not exist, return null.
   * @param string $selector
   * @param mixed array, a single value, or null
   */
  protected abstract function attribute($selector = '');
  
  /**
   * Should return a string value representing the name of this node.
   * @return string
   */
  abstract function name();
   
  /**
   * Determines if the given $selectors, $tests, and $values are true.
   * @param mixed $selectors a String or an array of strings, matching attributes by name
   * @param mixed $tests a String or an array of strings, each a recognized comparison operator (e.g., = or != or $=)
   * @param mixed $values a String or an array of strings, each a value to be matched according to the corresponding $test
   * @return true when all tests are true; otherwise, false
   */
  protected function has_attribute($selectors = '', $tests = null, $values = null) {
    // convert each parameter to an array
    if (!is_array($selectors)) {
      $selectors = array($selectors);
    }
    if (!is_array($tests)) {
      $tests = array($tests);
    }
    if (!is_array($values)) {
      $values = array($values);
    }
    
    // get all attributes
    $atts = $this->attribute();
    // no attributes? all results false
    if (!count($atts)) {
      return false;
    }
    
    $result = true;
    
    foreach($selectors as $i => $selector) {
      $selected = @$atts[$selector];
      $value = @$values[$i];
      $test = @$tests[$i];
    
      // all tests imply presence
      if (empty($selected)) {
        $result = false;
      // equal
      } else if ($test == '=') {
        $result =  $selected == $value;
      // not equal
      } else if ($test == '!=') {
        $result =  $selected != $value;
      // prefix
      } else if ($test == '|=') {
        $result =  $selected == $value || strpos($selected, "{$value}-") === 0;
      // contains
      } else if ($test == '*=') {
        $result =  strpos($selected, $value) !== false;
      // space-delimited word
      } else if ($test == '~=') {
        $words = preg_split('/\s+/', $selected);
        $result =  in_array($value, $words);
      // ends with
      } else if ($test == '$=') {
        $result =  strpos(strrev($selected), strrev($value)) === 0;
      // starts with
      } else if ($test == '^=') {
        $result =  strpos($selected, $value) === 0;
      }
      
      if ($result == false) {
        return false;
      }
    }
    
    return true;
  }
  
  /**
   * Retrieve a list of the child elements of this node. Unless $direct is true,
   * child elements should include ALL of the elements that appear beneath this element,
   * flattened into a single list, and in document order. If $direct is true,
   * only the direct descendants of this node should be returned.
   * @param string $selector
   * @param boolean $direct (Optional) defaults to false
   * @return array
   */
   
  protected abstract function descendants($selector = '', $direct = false);
  
  /**
   * Should respond with the value of this node, whatever that is according to
   * the implementation. 
   */
  abstract function __toString();
  
  /**
   * Initialize this node from the data represented by an arbitrary string.
   * @param string $string
   */
  abstract function parse($string = '');
  
}

/**
 * JSON implementation of clNode, wraps the results of json_decode.
 */
class clJsonNode extends clNode {
  
  private $obj;
  
  function __construct(&$json_object = null) {
    $this->obj = $json_object;
  }
  
  function parse($string = '') {
    if (($json_object = json_decode($string)) === false) {
      throw new Exception("Failed to parse string as JSON.");
    } else {
      if (is_array($json_object)) {
        $children = self::flatten_array($json_object);
        $this->obj = (object) $children;
      } else {
        $this->obj = $json_object;
      }
    }
  }
  
  protected function descendants($selector = '', $direct = false) {
    
  }
  
  protected function attribute($selector = '') {
    
  }
  
  function name() {
    return '';
  }
  
  function __toString() {
    return '';
  }
  
}

/**
 * XML implementation of clNode, wraps instances of SimpleXMLElement.
 */
class clXmlNode extends clNode {
  
  private $el;
  private $ns;
  private $namespaces;
  private $descendants;
  private $attributes;
  
  /**
   * Wrap a SimpleXMLElement object.
   * @param SimpleXMLElement $simple_xml_el (optional) defaults to null
   * @param clXmlNode $parent (optional) defaults to null
   * @param string $ns (optional) defaults to empty string
   * @param array $namespaces (optional) defaults to null
   */
  function __construct(&$simple_xml_el = null, $parent = null, $ns = '', &$namespaces = null) {
    $this->el = $simple_xml_el;
    $this->parent = $parent;
    $this->ns = $ns;
    
    if (!is_null($namespaces)) {
      $this->namespaces = $namespaces;
    }
    
    if (!$this->namespaces && $this->el) {
      $this->namespaces = $this->el->getNamespaces(true);
      $this->namespaces[''] = null;
    }
  }
  
  function parse($string = '') {
    if (($sxe = simplexml_load_string(trim($string))) !== false) {
      $this->el = $sxe;
      $this->namespaces = $this->el->getNamespaces(true);
      $this->namespaces[''] = null;
      return true;
    } else {
      // TODO: in PHP >= 5.1.0, it's possible to silence SimpleXML parsing errors and then iterate over them
      // http://us.php.net/manual/en/function.simplexml-load-string.php
      return false;
    }
  }
  
  function ns() {
    return $this->ns;
  }
  
  function parent() {
    return $this->parent;
  }
  
  /**
   * Expose the SimpleXMLElement API.
   */
  function __call($fx_name, $args) {
    $result = call_user_func_array(array($this->el, $fx_name), $args);
    if ($result instanceof SimpleXMLElement) {
      return new clXmlNode($result, $this, '', $this->namespaces);
    } else {
      return $result;
    }
  }
  
  /**
   * Expose the SimpleXMLElement API.
   */
  function __get($name) {
    $result = $this->el->{$name};
    if ($result instanceof SimpleXMLElement) {
      return new clXmlNode($result, $this, '', $this->namespaces);
    } else {
      return $result;
    }
  }
  
  
  protected function descendants($selector = '', $direct = false) {    
    if (!$this->descendants) {
      $this->descendants = array();
      foreach($this->namespaces as $ns => $uri) {
        foreach($this->el->children($ns, true) as $child) {
          $node = new clXmlNode($child, $this, $ns, $this->namespaces);
          $this->descendants[] = $node;
          $this->descendants = array_merge($this->descendants, $node->descendants('*'));
        }
      }
    }
    
    @list($ns, $name) = explode(':', $selector);
    
    if (!$name) {
      $name = $ns;
      $ns = null;
    }
    
    $children = array();
    
    foreach($this->descendants as $child) {
      if ( (!$name || $name == '*' || $child->getName() == $name) && (!$direct || $child->parent() === $this) && (!$ns || $child->ns() == $ns) ) {
        $children[] = $child;
      }
    }
    
    return $children;
  }
  
  
  protected function attribute($selector = '') {
    if (!$this->attributes) {
      $this->attributes = array();
      foreach($this->namespaces as $ns => $uri) {
        $this->attributes[$ns] = $this->el->attributes($ns, true);
      }
    }
    
    @list($ns, $name) = explode(':', $selector);
    
    if (!$name) {
      $name = $ns;
      $ns = null;
    }
    
    // no name? get all.
    if (!$name) {
      $attributes = array();
      foreach($this->attributes as $ns => $atts) {
        foreach($atts as $this_name => $val) {
          if ($ns) {
            $this_name = "$ns:$this_name";
          }
          $attributes[$this_name] = (string) $val;
        }
      }
      return $attributes;
      
    // ns specified? 
    } else if ($ns && isset($this->attributes[$ns])) {
      foreach($this->attributes[$ns] as $this_name => $val) {
        if ($this_name == $name) {
          return (string) $val;
        }
      }
     
    // looking for the name across all namespaces
    } else {
      foreach($this->attributes as $ns => $atts) {
        foreach($atts as $this_name => $val) {
          if ($this_name == $name) {
            return (string) $val;
          }
        }
      }
    }
    
    return null;
  }
  
  function name() {
    return $this->getName();
  }
  
  /**
   * Use XPATH to select elements. But... why? Ugh.
   * @param 
   * @return clXmlNode
   * @deprecated Use clXmlNode::get($selector, true) instead.
   */
  function xpath($selector) {
    return new clXmlNode($this->el->xpath($selector), $this, '', $this->namespaces);
  }
  
  function __toString() {
    return (string) $this->el;
  }
  
}
// src/inspector.php


if (COREYLIB_DEBUG) {
  ini_set('display_errors', false);
  
  trigger_error("COREYLIB_DEBUG is enabled", E_USER_WARNING);
  
  if ($url = @$_POST['url']) {
    if ($node = coreylib($url, @$_POST['flush'] ? false : '10 minutes')) {
      header('Content-Type: application/json');
      if ($selector = @$_POST['selector']) {
        $node = $node->get($selector);
        if ($node->size() > 1) {
          $result = array();
          foreach($node as $n) {
            if (is_object($n)) {
              $result[] = (object) array_filter(array(
                'text' => trim($n->__toString()),
                'children' => $n->toArray(),
                'attribs' => $n->attribs()
              ));
            } else {
              $result[] = $n;
            }
          }
          echo json_encode($result);
        } else {
          echo $node->toJson();
        }
      } else {
        echo $node->toJson();
      }
    }
    exit;
    
  } else {
    ?>
      <script>!window.jQuery && document.write(unescape('%3Cscript src=\"//ajax.googleapis.com/ajax/libs/jquery/1.5.2/jquery.min.js\"%3E%3C/script%3E'))</script>
      <script>
        function coreylib(url, selector, flush) {
          jQuery.ajax({
            url: 'coreylib.php', 
            data: { 'url': url, 'selector': selector, 'flush': flush },
            dataType: 'json',
            type: 'POST',
            success: function(json) {
              console.log(json.length, json);
            }
          });
          return "Downloading...";
        }
      </script>
    <?php
  }
}
// src/oauth-support.php

 
/**
 * OAuth support classes.
 * @ref http://oauth.googlecode.com/svn/code/php/
 */
 
/* Generic exception class
 */
class OAuthException extends Exception {
  // pass
}

class OAuthConsumer {
  public $key;
  public $secret;

  function __construct($key, $secret, $callback_url=NULL) {
    $this->key = $key;
    $this->secret = $secret;
    $this->callback_url = $callback_url;
  }

  function __toString() {
    return "OAuthConsumer[key=$this->key,secret=$this->secret]";
  }
}

class OAuthToken {
  // access tokens and request tokens
  public $key;
  public $secret;

  /**
   * key = the token
   * secret = the token secret
   */
  function __construct($key, $secret) {
    $this->key = $key;
    $this->secret = $secret;
  }

  /**
   * generates the basic string serialization of a token that a server
   * would respond to request_token and access_token calls with
   */
  function to_string() {
    return "oauth_token=" .
           OAuthUtil::urlencode_rfc3986($this->key) .
           "&oauth_token_secret=" .
           OAuthUtil::urlencode_rfc3986($this->secret);
  }

  function __toString() {
    return $this->to_string();
  }
}

/**
 * A class for implementing a Signature Method
 * See section 9 ("Signing Requests") in the spec
 */
abstract class OAuthSignatureMethod {
  /**
   * Needs to return the name of the Signature Method (ie HMAC-SHA1)
   * @return string
   */
  abstract public function get_name();

  /**
   * Build up the signature
   * NOTE: The output of this function MUST NOT be urlencoded.
   * the encoding is handled in OAuthRequest when the final
   * request is serialized
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @return string
   */
  abstract public function build_signature($request, $consumer, $token);

  /**
   * Verifies that a given signature is correct
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @param string $signature
   * @return bool
   */
  public function check_signature($request, $consumer, $token, $signature) {
    $built = $this->build_signature($request, $consumer, $token);
    return $built == $signature;
  }
}

/**
 * The HMAC-SHA1 signature method uses the HMAC-SHA1 signature algorithm as defined in [RFC2104]
 * where the Signature Base String is the text and the key is the concatenated values (each first
 * encoded per Parameter Encoding) of the Consumer Secret and Token Secret, separated by an '&'
 * character (ASCII code 38) even if empty.
 *   - Chapter 9.2 ("HMAC-SHA1")
 */
class OAuthSignatureMethod_HMAC_SHA1 extends OAuthSignatureMethod {
  function get_name() {
    return "HMAC-SHA1";
  }

  public function build_signature($request, $consumer, $token) {
    $base_string = $request->get_signature_base_string();
    $request->base_string = $base_string;

    $key_parts = array(
      $consumer->secret,
      ($token) ? $token->secret : ""
    );

    $key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
    $key = implode('&', $key_parts);

    return base64_encode(hash_hmac('sha1', $base_string, $key, true));
  }
}

/**
 * The PLAINTEXT method does not provide any security protection and SHOULD only be used
 * over a secure channel such as HTTPS. It does not use the Signature Base String.
 *   - Chapter 9.4 ("PLAINTEXT")
 */
class OAuthSignatureMethod_PLAINTEXT extends OAuthSignatureMethod {
  public function get_name() {
    return "PLAINTEXT";
  }

  /**
   * oauth_signature is set to the concatenated encoded values of the Consumer Secret and
   * Token Secret, separated by a '&' character (ASCII code 38), even if either secret is
   * empty. The result MUST be encoded again.
   *   - Chapter 9.4.1 ("Generating Signatures")
   *
   * Please note that the second encoding MUST NOT happen in the SignatureMethod, as
   * OAuthRequest handles this!
   */
  public function build_signature($request, $consumer, $token) {
    $key_parts = array(
      $consumer->secret,
      ($token) ? $token->secret : ""
    );

    $key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
    $key = implode('&', $key_parts);
    $request->base_string = $key;

    return $key;
  }
}

/**
 * The RSA-SHA1 signature method uses the RSASSA-PKCS1-v1_5 signature algorithm as defined in
 * [RFC3447] section 8.2 (more simply known as PKCS#1), using SHA-1 as the hash function for
 * EMSA-PKCS1-v1_5. It is assumed that the Consumer has provided its RSA public key in a
 * verified way to the Service Provider, in a manner which is beyond the scope of this
 * specification.
 *   - Chapter 9.3 ("RSA-SHA1")
 */
abstract class OAuthSignatureMethod_RSA_SHA1 extends OAuthSignatureMethod {
  public function get_name() {
    return "RSA-SHA1";
  }

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  // (2) fetch via http using a url provided by the requester
  // (3) some sort of specific discovery code based on request
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_public_cert(&$request);

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_private_cert(&$request);

  public function build_signature($request, $consumer, $token) {
    $base_string = $request->get_signature_base_string();
    $request->base_string = $base_string;

    // Fetch the private key cert based on the request
    $cert = $this->fetch_private_cert($request);

    // Pull the private key ID from the certificate
    $privatekeyid = openssl_get_privatekey($cert);

    // Sign using the key
    $ok = openssl_sign($base_string, $signature, $privatekeyid);

    // Release the key resource
    openssl_free_key($privatekeyid);

    return base64_encode($signature);
  }

  public function check_signature($request, $consumer, $token, $signature) {
    $decoded_sig = base64_decode($signature);

    $base_string = $request->get_signature_base_string();

    // Fetch the public key cert based on the request
    $cert = $this->fetch_public_cert($request);

    // Pull the public key ID from the certificate
    $publickeyid = openssl_get_publickey($cert);

    // Check the computed signature against the one passed in the query
    $ok = openssl_verify($base_string, $decoded_sig, $publickeyid);

    // Release the key resource
    openssl_free_key($publickeyid);

    return $ok == 1;
  }
}

class OAuthRequest {
  protected $parameters;
  protected $http_method;
  protected $http_url;
  // for debug purposes
  public $base_string;
  public static $version = '1.0';
  public static $POST_INPUT = 'php://input';

  function __construct($http_method, $http_url, $parameters=NULL) {
    $parameters = ($parameters) ? $parameters : array();
    $parameters = array_merge( OAuthUtil::parse_parameters(parse_url($http_url, PHP_URL_QUERY)), $parameters);
    $this->parameters = $parameters;
    $this->http_method = $http_method;
    $this->http_url = $http_url;
  }


  /**
   * attempt to build up a request from what was passed to the server
   */
  public static function from_request($http_method=NULL, $http_url=NULL, $parameters=NULL) {
    $scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
              ? 'http'
              : 'https';
    $http_url = ($http_url) ? $http_url : $scheme .
                              '://' . $_SERVER['HTTP_HOST'] .
                              ':' .
                              $_SERVER['SERVER_PORT'] .
                              $_SERVER['REQUEST_URI'];
    $http_method = ($http_method) ? $http_method : $_SERVER['REQUEST_METHOD'];

    // We weren't handed any parameters, so let's find the ones relevant to
    // this request.
    // If you run XML-RPC or similar you should use this to provide your own
    // parsed parameter-list
    if (!$parameters) {
      // Find request headers
      $request_headers = OAuthUtil::get_headers();

      // Parse the query-string to find GET parameters
      $parameters = OAuthUtil::parse_parameters($_SERVER['QUERY_STRING']);

      // It's a POST request of the proper content-type, so parse POST
      // parameters and add those overriding any duplicates from GET
      if ($http_method == "POST"
          &&  isset($request_headers['Content-Type'])
          && strstr($request_headers['Content-Type'],
                     'application/x-www-form-urlencoded')
          ) {
        $post_data = OAuthUtil::parse_parameters(
          file_get_contents(self::$POST_INPUT)
        );
        $parameters = array_merge($parameters, $post_data);
      }

      // We have a Authorization-header with OAuth data. Parse the header
      // and add those overriding any duplicates from GET or POST
      if (isset($request_headers['Authorization']) && substr($request_headers['Authorization'], 0, 6) == 'OAuth ') {
        $header_parameters = OAuthUtil::split_header(
          $request_headers['Authorization']
        );
        $parameters = array_merge($parameters, $header_parameters);
      }

    }

    return new OAuthRequest($http_method, $http_url, $parameters);
  }

  /**
   * pretty much a helper function to set up the request
   */
  public static function from_consumer_and_token($consumer, $token, $http_method, $http_url, $parameters=NULL) {
    $parameters = ($parameters) ?  $parameters : array();
    $defaults = array("oauth_version" => OAuthRequest::$version,
                      "oauth_nonce" => OAuthRequest::generate_nonce(),
                      "oauth_timestamp" => OAuthRequest::generate_timestamp(),
                      "oauth_consumer_key" => $consumer->key);
    if ($token)
      $defaults['oauth_token'] = $token->key;

    $parameters = array_merge($defaults, $parameters);

    return new OAuthRequest($http_method, $http_url, $parameters);
  }

  public function set_parameter($name, $value, $allow_duplicates = true) {
    if ($allow_duplicates && isset($this->parameters[$name])) {
      // We have already added parameter(s) with this name, so add to the list
      if (is_scalar($this->parameters[$name])) {
        // This is the first duplicate, so transform scalar (string)
        // into an array so we can add the duplicates
        $this->parameters[$name] = array($this->parameters[$name]);
      }

      $this->parameters[$name][] = $value;
    } else {
      $this->parameters[$name] = $value;
    }
  }

  public function get_parameter($name) {
    return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
  }

  public function get_parameters() {
    return $this->parameters;
  }

  public function unset_parameter($name) {
    unset($this->parameters[$name]);
  }

  /**
   * The request parameters, sorted and concatenated into a normalized string.
   * @return string
   */
  public function get_signable_parameters() {
    // Grab all parameters
    $params = $this->parameters;

    // Remove oauth_signature if present
    // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
    if (isset($params['oauth_signature'])) {
      unset($params['oauth_signature']);
    }

    return OAuthUtil::build_http_query($params);
  }

  /**
   * Returns the base string of this request
   *
   * The base string defined as the method, the url
   * and the parameters (normalized), each urlencoded
   * and the concated with &.
   */
  public function get_signature_base_string() {
    $parts = array(
      $this->get_normalized_http_method(),
      $this->get_normalized_http_url(),
      $this->get_signable_parameters()
    );

    $parts = OAuthUtil::urlencode_rfc3986($parts);

    return implode('&', $parts);
  }

  /**
   * just uppercases the http method
   */
  public function get_normalized_http_method() {
    return strtoupper($this->http_method);
  }

  /**
   * parses the url and rebuilds it to be
   * scheme://host/path
   */
  public function get_normalized_http_url() {
    $parts = parse_url($this->http_url);

    $scheme = (isset($parts['scheme'])) ? $parts['scheme'] : 'http';
    $port = (isset($parts['port'])) ? $parts['port'] : (($scheme == 'https') ? '443' : '80');
    $host = (isset($parts['host'])) ? $parts['host'] : '';
    $path = (isset($parts['path'])) ? $parts['path'] : '';

    if (($scheme == 'https' && $port != '443')
        || ($scheme == 'http' && $port != '80')) {
      $host = "$host:$port";
    }
    return "$scheme://$host$path";
  }

  /**
   * builds a url usable for a GET request
   */
  public function to_url() {
    $post_data = $this->to_postdata();
    $out = $this->get_normalized_http_url();
    if ($post_data) {
      $out .= '?'.$post_data;
    }
    return $out;
  }

  /**
   * builds the data one would send in a POST request
   */
  public function to_postdata() {
    return OAuthUtil::build_http_query($this->parameters);
  }

  /**
   * builds the Authorization: header
   */
  public function to_header($realm=null) {
    $first = true;
  if($realm) {
      $out = 'Authorization: OAuth realm="' . OAuthUtil::urlencode_rfc3986($realm) . '"';
      $first = false;
    } else
      $out = 'Authorization: OAuth';

    $total = array();
    foreach ($this->parameters as $k => $v) {
      if (substr($k, 0, 5) != "oauth") continue;
      if (is_array($v)) {
        throw new OAuthException('Arrays not supported in headers');
      }
      $out .= ($first) ? ' ' : ',';
      $out .= OAuthUtil::urlencode_rfc3986($k) .
              '="' .
              OAuthUtil::urlencode_rfc3986($v) .
              '"';
      $first = false;
    }
    return $out;
  }

  public function __toString() {
    return $this->to_url();
  }


  public function sign_request($signature_method, $consumer, $token) {
    $this->set_parameter(
      "oauth_signature_method",
      $signature_method->get_name(),
      false
    );
    $signature = $this->build_signature($signature_method, $consumer, $token);
    $this->set_parameter("oauth_signature", $signature, false);
  }

  public function build_signature($signature_method, $consumer, $token) {
    $signature = $signature_method->build_signature($this, $consumer, $token);
    return $signature;
  }

  /**
   * util function: current timestamp
   */
  private static function generate_timestamp() {
    return time();
  }

  /**
   * util function: current nonce
   */
  public static function generate_nonce() {
    $mt = microtime();
    $rand = mt_rand();

    return md5($mt . $rand); // md5s look nicer than numbers
  }
}

class OAuthServer {
  protected $timestamp_threshold = 300; // in seconds, five minutes
  protected $version = '1.0';             // hi blaine
  protected $signature_methods = array();

  protected $data_store;

  function __construct($data_store) {
    $this->data_store = $data_store;
  }

  public function add_signature_method($signature_method) {
    $this->signature_methods[$signature_method->get_name()] =
      $signature_method;
  }

  // high level functions

  /**
   * process a request_token request
   * returns the request token on success
   */
  public function fetch_request_token(&$request) {
    $this->get_version($request);

    $consumer = $this->get_consumer($request);

    // no token required for the initial token request
    $token = NULL;

    $this->check_signature($request, $consumer, $token);

    // Rev A change
    $callback = $request->get_parameter('oauth_callback');
    $new_token = $this->data_store->new_request_token($consumer, $callback);

    return $new_token;
  }

  /**
   * process an access_token request
   * returns the access token on success
   */
  public function fetch_access_token(&$request) {
    $this->get_version($request);

    $consumer = $this->get_consumer($request);

    // requires authorized request token
    $token = $this->get_token($request, $consumer, "request");

    $this->check_signature($request, $consumer, $token);

    // Rev A change
    $verifier = $request->get_parameter('oauth_verifier');
    $new_token = $this->data_store->new_access_token($token, $consumer, $verifier);

    return $new_token;
  }

  /**
   * verify an api call, checks all the parameters
   */
  public function verify_request(&$request) {
    $this->get_version($request);
    $consumer = $this->get_consumer($request);
    $token = $this->get_token($request, $consumer, "access");
    $this->check_signature($request, $consumer, $token);
    return array($consumer, $token);
  }

  // Internals from here
  /**
   * version 1
   */
  private function get_version(&$request) {
    $version = $request->get_parameter("oauth_version");
    if (!$version) {
      // Service Providers MUST assume the protocol version to be 1.0 if this parameter is not present.
      // Chapter 7.0 ("Accessing Protected Ressources")
      $version = '1.0';
    }
    if ($version !== $this->version) {
      throw new OAuthException("OAuth version '$version' not supported");
    }
    return $version;
  }

  /**
   * figure out the signature with some defaults
   */
  private function get_signature_method($request) {
    $signature_method = $request instanceof OAuthRequest
        ? $request->get_parameter("oauth_signature_method")
        : NULL;

    if (!$signature_method) {
      // According to chapter 7 ("Accessing Protected Ressources") the signature-method
      // parameter is required, and we can't just fallback to PLAINTEXT
      throw new OAuthException('No signature method parameter. This parameter is required');
    }

    if (!in_array($signature_method,
                  array_keys($this->signature_methods))) {
      throw new OAuthException(
        "Signature method '$signature_method' not supported " .
        "try one of the following: " .
        implode(", ", array_keys($this->signature_methods))
      );
    }
    return $this->signature_methods[$signature_method];
  }

  /**
   * try to find the consumer for the provided request's consumer key
   */
  private function get_consumer($request) {
    $consumer_key = $request instanceof OAuthRequest
        ? $request->get_parameter("oauth_consumer_key")
        : NULL;

    if (!$consumer_key) {
      throw new OAuthException("Invalid consumer key");
    }

    $consumer = $this->data_store->lookup_consumer($consumer_key);
    if (!$consumer) {
      throw new OAuthException("Invalid consumer");
    }

    return $consumer;
  }

  /**
   * try to find the token for the provided request's token key
   */
  private function get_token($request, $consumer, $token_type="access") {
    $token_field = $request instanceof OAuthRequest
         ? $request->get_parameter('oauth_token')
         : NULL;

    $token = $this->data_store->lookup_token(
      $consumer, $token_type, $token_field
    );
    if (!$token) {
      throw new OAuthException("Invalid $token_type token: $token_field");
    }
    return $token;
  }

  /**
   * all-in-one function to check the signature on a request
   * should guess the signature method appropriately
   */
  private function check_signature($request, $consumer, $token) {
    // this should probably be in a different method
    $timestamp = $request instanceof OAuthRequest
        ? $request->get_parameter('oauth_timestamp')
        : NULL;
    $nonce = $request instanceof OAuthRequest
        ? $request->get_parameter('oauth_nonce')
        : NULL;

    $this->check_timestamp($timestamp);
    $this->check_nonce($consumer, $token, $nonce, $timestamp);

    $signature_method = $this->get_signature_method($request);

    $signature = $request->get_parameter('oauth_signature');
    $valid_sig = $signature_method->check_signature(
      $request,
      $consumer,
      $token,
      $signature
    );

    if (!$valid_sig) {
      throw new OAuthException("Invalid signature");
    }
  }

  /**
   * check that the timestamp is new enough
   */
  private function check_timestamp($timestamp) {
    if( ! $timestamp )
      throw new OAuthException(
        'Missing timestamp parameter. The parameter is required'
      );

    // verify that timestamp is recentish
    $now = time();
    if (abs($now - $timestamp) > $this->timestamp_threshold) {
      throw new OAuthException(
        "Expired timestamp, yours $timestamp, ours $now"
      );
    }
  }

  /**
   * check that the nonce is not repeated
   */
  private function check_nonce($consumer, $token, $nonce, $timestamp) {
    if( ! $nonce )
      throw new OAuthException(
        'Missing nonce parameter. The parameter is required'
      );

    // verify that the nonce is uniqueish
    $found = $this->data_store->lookup_nonce(
      $consumer,
      $token,
      $nonce,
      $timestamp
    );
    if ($found) {
      throw new OAuthException("Nonce already used: $nonce");
    }
  }

}

class OAuthDataStore {
  function lookup_consumer($consumer_key) {
    // implement me
  }

  function lookup_token($consumer, $token_type, $token) {
    // implement me
  }

  function lookup_nonce($consumer, $token, $nonce, $timestamp) {
    // implement me
  }

  function new_request_token($consumer, $callback = null) {
    // return a new token attached to this consumer
  }

  function new_access_token($token, $consumer, $verifier = null) {
    // return a new access token attached to this consumer
    // for the user associated with this token if the request token
    // is authorized
    // should also invalidate the request token
  }

}

class OAuthUtil {
  public static function urlencode_rfc3986($input) {
  if (is_array($input)) {
    return array_map(array('OAuthUtil', 'urlencode_rfc3986'), $input);
  } else if (is_scalar($input)) {
    return str_replace(
      '+',
      ' ',
      str_replace('%7E', '~', rawurlencode($input))
    );
  } else {
    return '';
  }
}


  // This decode function isn't taking into consideration the above
  // modifications to the encoding process. However, this method doesn't
  // seem to be used anywhere so leaving it as is.
  public static function urldecode_rfc3986($string) {
    return urldecode($string);
  }

  // Utility function for turning the Authorization: header into
  // parameters, has to do some unescaping
  // Can filter out any non-oauth parameters if needed (default behaviour)
  // May 28th, 2010 - method updated to tjerk.meesters for a speed improvement.
  //                  see http://code.google.com/p/oauth/issues/detail?id=163
  public static function split_header($header, $only_allow_oauth_parameters = true) {
    $params = array();
    if (preg_match_all('/('.($only_allow_oauth_parameters ? 'oauth_' : '').'[a-z_-]*)=(:?"([^"]*)"|([^,]*))/', $header, $matches)) {
      foreach ($matches[1] as $i => $h) {
        $params[$h] = OAuthUtil::urldecode_rfc3986(empty($matches[3][$i]) ? $matches[4][$i] : $matches[3][$i]);
      }
      if (isset($params['realm'])) {
        unset($params['realm']);
      }
    }
    return $params;
  }

  // helper to try to sort out headers for people who aren't running apache
  public static function get_headers() {
    if (function_exists('apache_request_headers')) {
      // we need this to get the actual Authorization: header
      // because apache tends to tell us it doesn't exist
      $headers = apache_request_headers();

      // sanitize the output of apache_request_headers because
      // we always want the keys to be Cased-Like-This and arh()
      // returns the headers in the same case as they are in the
      // request
      $out = array();
      foreach ($headers AS $key => $value) {
        $key = str_replace(
            " ",
            "-",
            ucwords(strtolower(str_replace("-", " ", $key)))
          );
        $out[$key] = $value;
      }
    } else {
      // otherwise we don't have apache and are just going to have to hope
      // that $_SERVER actually contains what we need
      $out = array();
      if( isset($_SERVER['CONTENT_TYPE']) )
        $out['Content-Type'] = $_SERVER['CONTENT_TYPE'];
      if( isset($_ENV['CONTENT_TYPE']) )
        $out['Content-Type'] = $_ENV['CONTENT_TYPE'];

      foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) == "HTTP_") {
          // this is chaos, basically it is just there to capitalize the first
          // letter of every word that is not an initial HTTP and strip HTTP
          // code from przemek
          $key = str_replace(
            " ",
            "-",
            ucwords(strtolower(str_replace("_", " ", substr($key, 5))))
          );
          $out[$key] = $value;
        }
      }
    }
    return $out;
  }

  // This function takes a input like a=b&a=c&d=e and returns the parsed
  // parameters like this
  // array('a' => array('b','c'), 'd' => 'e')
  public static function parse_parameters( $input ) {
    if (!isset($input) || !$input) return array();

    $pairs = explode('&', $input);

    $parsed_parameters = array();
    foreach ($pairs as $pair) {
      $split = explode('=', $pair, 2);
      $parameter = OAuthUtil::urldecode_rfc3986($split[0]);
      $value = isset($split[1]) ? OAuthUtil::urldecode_rfc3986($split[1]) : '';

      if (isset($parsed_parameters[$parameter])) {
        // We have already recieved parameter(s) with this name, so add to the list
        // of parameters with this name

        if (is_scalar($parsed_parameters[$parameter])) {
          // This is the first duplicate, so transform scalar (string) into an array
          // so we can add the duplicates
          $parsed_parameters[$parameter] = array($parsed_parameters[$parameter]);
        }

        $parsed_parameters[$parameter][] = $value;
      } else {
        $parsed_parameters[$parameter] = $value;
      }
    }
    return $parsed_parameters;
  }

  public static function build_http_query($params) {
    if (!$params) return '';

    // Urlencode both keys and values
    $keys = OAuthUtil::urlencode_rfc3986(array_keys($params));
    $values = OAuthUtil::urlencode_rfc3986(array_values($params));
    $params = array_combine($keys, $values);

    // Parameters are sorted by name, using lexicographical byte value ordering.
    // Ref: Spec: 9.1.1 (1)
    uksort($params, 'strcmp');

    $pairs = array();
    foreach ($params as $parameter => $value) {
      if (is_array($value)) {
        // If two or more parameters share the same name, they are sorted by their value
        // Ref: Spec: 9.1.1 (1)
        // June 12th, 2010 - changed to sort because of issue 164 by hidetaka
        sort($value, SORT_STRING);
        foreach ($value as $duplicate_value) {
          $pairs[] = $parameter . '=' . $duplicate_value;
        }
      } else {
        $pairs[] = $parameter . '=' . $value;
      }
    }
    // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
    // Each name-value pair is separated by an '&' character (ASCII code 38)
    return implode('&', $pairs);
  }
}
// src/wordpress-support.php


/**
 * These next few lines allow coreylib.php to be dropped into your plugins folder.
 * Doing so will automatically configure it to use the WordPress database for cache storage.
 * You can override the cache system by setting COREYLIB_DETECT_WORDPRESS to false in your
 * wp-config.php, or by calling coreylib_set_cache(clCache) at any time to override.
 */
if (COREYLIB_DETECT_WORDPRESS) {
  // if the add_action function is present, assume this is wordpress
  if (function_exists('add_action')) {
    // override default caching mechanism with clWordPressCache
    function coreylib_init_cache() {
      coreylib_set_cache(new clWordPressCache());
      add_filter(sprintf('plugin_action_links_%s', basename(__FILE__)), 'coreylib_plugin_action_links', 10, 4);
      add_action('wp_ajax_coreylib_clear_cache', 'coreylib_wordpress_flush');
    }
    
    add_action('init', 'coreylib_init_cache');
    
    // allow for flushing global cache by WP ajax call
    function coreylib_wordpress_flush() {
      if (current_user_can('edit_plugins')) {
        coreylib_flush();
      }
      exit;
    }
    
    // add the cache flushing link to the plugins screen
    function coreylib_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
      $actions['flush'] = '<a href="#" onclick="if (confirm(\'Are you sure you want to clear the coreylib cache?\')) jQuery.post(ajaxurl, { action: \'coreylib_clear_cache\' }, function() { alert(\'Done!\'); });">Clear Cache</a>';
      return $actions;
    }
  }
}
