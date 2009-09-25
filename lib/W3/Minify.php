<?php

/**
 * W3 Minify object
 */

/**
 * Class W3_Minify
 */
class W3_Minify
{
    /**
     * Config
     * 
     * @var W3_Config
     */
    var $_config = null;
    
    /**
     * Memcached object
     *
     * @var W3_Cache_Memcached
     */
    var $_memcached = null;
    
    /**
     * PHP5 constructor
     */
    function __construct()
    {
        require_once W3TC_LIB_W3_DIR . '/Config.php';
        $this->_config = W3_Config::instance();
        set_include_path(get_include_path() . PATH_SEPARATOR . W3TC_LIB_MINIFY_DIR);
    }
    
    /**
     * PHP4 constructor
     * @return W3_Minify
     */
    function W3_Minify()
    {
        $this->__construct();
    }
    
    /**
     * Runs minify
     */
    function process()
    {
        require_once W3TC_LIB_MINIFY_DIR . '/Minify.php';
        require_once W3TC_LIB_MINIFY_DIR . '/HTTP/Encoder.php';
        
        HTTP_Encoder::$encodeToIe6 = $this->_config->get_boolean('minify.comprss.ie6', true);
        
        Minify::$uploaderHoursBehind = $this->_config->get_integer('minify.fixtime');
        Minify::setCache($this->_get_cache());
        
        $serve_options = $this->_config->get_array('minify.options');
        $serve_options['encodeOutput'] = $this->_config->get_boolean('minify.compress', true);
        $serve_options['postprocessor'] = array(
            &$this, 
            'postprocessor'
        );
        
        if (stripos(PHP_OS, 'win') === 0) {
            Minify::setDocRoot();
        }
        
        foreach ($this->_config->get_array('minify.symlinks') as $link => $target) {
            $link = str_replace('//', realpath($_SERVER['DOCUMENT_ROOT']), $link);
            $link = strtr($link, '/', DIRECTORY_SEPARATOR);
            $serve_options['minifierOptions']['text/css']['symlinks'][$link] = realpath($target);
        }
        
        if ($this->_config->get_boolean('minify.debug') && isset($_REQUEST['debug'])) {
            $serve_options['debug'] = true;
        }
        
        if ($this->_config->get('minify.debug')) {
            require_once W3TC_LIB_MINIFY_DIR . '/Minify/Logger.php';
            Minify_Logger::setLogger($this);
        }
        
        if (isset($_GET['f']) || (isset($_GET['gg']) && isset($_GET['g']) && isset($_GET['t']))) {
            if (isset($_GET['gg']) && isset($_GET['g']) && isset($_GET['t'])) {
                $serve_options['minApp']['groups'] = $this->get_groups($_GET['gg'], $_GET['t']);
                
                if ($_GET['t'] == 'js' && ((in_array($_GET['g'], array(
                    'include', 
                    'include-nb'
                )) && $this->_config->get_boolean('minify.js.combine.header')) || (in_array($_GET['g'], array(
                    'include-footer', 
                    'include-footer-nb'
                )) && $this->_config->get_boolean('minify.js.combine.footer')))) {
                    $serve_options['minifiers']['application/x-javascript'] = array(
                        $this, 
                        'minify_stub'
                    );
                }
            }
            
            Minify::serve('MinApp', $serve_options);
        } else {
            die('This file cannot be accessed directly');
        }
    }
    
    /**
     * Minify postprocessor
     *
     * @param string $content
     * @param string $type
     * @return string
     */
    function postprocessor($content, $type)
    {
        switch ($type) {
            case 'text/css':
                if ($this->_config->get_boolean('minify.css.strip.comments')) {
                    $content = preg_replace('~/\*.*\*/~Us', '', $content);
                }
                
                if ($this->_config->get_boolean('minify.css.strip.crlf')) {
                    $content = preg_replace("~[\r\n]+~", ' ', $content);
                } else {
                    $content = preg_replace("~[\r\n]+~", "\n", $content);
                }
                break;
            
            case 'application/x-javascript':
                if ($this->_config->get_boolean('minify.js.strip.comments')) {
                    $content = preg_replace('~^//.*$~m', '', $content);
                    $content = preg_replace('~/\*.*\*/~Us', '', $content);
                }
                
                if ($this->_config->get_boolean('minify.js.strip.crlf')) {
                    $content = preg_replace("~[\r\n]+~", '', $content);
                } else {
                    $content = preg_replace("~[\r\n]+~", "\n", $content);
                }
                break;
        }
        
        return $content;
    }
    
    /**
     * Flushes cache
     */
    function flush()
    {
        static $cache_path = null;
        
        $cache = $this->_get_cache();
        
        if (is_a($cache, 'Minify_Cache_Memcache') && is_a($this->_memcached, 'W3_Cache_Memcached_Base')) {
            return $this->_memcached->flush();
        } elseif (is_a($cache, 'Minify_Cache_APC') && function_exists('apc_clear_cache')) {
            return apc_clear_cache('user');
        } elseif (is_a($cache, 'Minify_Cache_File')) {
            if (! is_dir(W3TC_CACHE_MINIFY_DIR)) {
                $this->log(sprintf('Cache directory %s does not exists', W3TC_CACHE_MINIFY_DIR));
            }
            return w3_emptydir(W3TC_CACHE_MINIFY_DIR);
        }
        
        return false;
    }
    
    /**
     * Returns onject instance
     *
     * @return W3_Minify
     */
    function &instance()
    {
        static $instance = null;
        
        if ($instance === null) {
            $class = __CLASS__;
            $instance = & new $class();
        }
        
        return $instance;
    }
    
    /**
     * Minify stub function
     *
     * @param string $source
     */
    function minify_stub($source)
    {
        return $source;
    }
    
    /**
     * Log
     *
     * @param mixed $object
     * @param string $label
     */
    function log($object, $label = null)
    {
        $file = W3TC_LOG_DIR . '/minify.log';
        $data = sprintf("[%s] [%s] %s\n", date('r'), $_SERVER['REQUEST_URI'], $object);
        
        $fp = @fopen($file, 'a');
        if ($fp) {
            @fputs($fp, $data);
            @fclose($fp);
            return true;
        }
        
        return false;
    }
    
    /**
     * Returns minify groups
     * @param string $group
     * @param string $type
     * @return array
     */
    function get_groups($group, $type)
    {
        $result = array();
        
        switch ($type) {
            case 'css':
                $groups = $this->_config->get_array('minify.css.groups');
                break;
            
            case 'js':
                $groups = $this->_config->get_array('minify.js.groups');
                break;
            
            default:
                return $result;
        }
        
        if (isset($groups['default'])) {
            $locations = (array) $groups['default'];
        } else {
            $locations = array();
        }
        
        if ($group != 'default' && isset($groups[$group])) {
            $locations = array_merge_recursive($locations, (array) $groups[$group]);
        }
        
        foreach ($locations as $location => $config) {
            if (! empty($config['files'])) {
                foreach ((array) $config['files'] as $file) {
                    if (w3_is_url($file)) {
                        if (($precached_file = $this->_precache_file($file, $type))) {
                            $result[$location][$file] = $precached_file;
                        }
                    } else {
                        $result[$location][$file] = '//' . $file;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Precaches external file
     *
     * @param string $url
     * @param string $type
     * @return string
     */
    function _precache_file($url, $type)
    {
        $lifetime = $this->_config->get_integer('minify.lifetime', 3600);
        $file_path = sprintf('%s/minify_%s.%s', W3TC_CACHE_MINIFY_DIR, md5($url), $type);
        $file_exists = file_exists($file_path);
        $base_url = $this->_get_base_url($url);
        
        if (file_exists($file_path) && @filemtime($file_path) >= (time() - $lifetime)) {
            return $this->_get_minify_source($file_path, $base_url);
        }
        
        if (is_dir(W3TC_CACHE_MINIFY_DIR)) {
            if (($file_data = w3_url_get($url))) {
                if (($fp = @fopen($file_path, 'w'))) {
                    @fputs($fp, $file_data);
                    @fclose($fp);
                    
                    return $this->_get_minify_source($file_path, $base_url);
                } else {
                    $this->log(sprintf('Unable to open file %s for writing', $file_path));
                }
            } else {
                $this->log(sprintf('Unable to download URL: %s', $url));
            }
        } else {
            $this->log(sprintf('Cache directory %s is not exists', W3TC_CACHE_MINIFY_DIR));
        }
        
        return ($file_exists ? $this->_get_minify_source($file_path, $base_url) : false);
    }
    
    /**
     * Returns minify source
     * @param $file_path
     * @param $base_url
     * @return Minify_Source
     */
    function _get_minify_source($file_path, $base_url)
    {
        require_once W3TC_LIB_MINIFY_DIR . '/Minify/Source.php';
        
        return new Minify_Source(array(
            'filepath' => $file_path, 
            'minifyOptions' => array(
                'prependRelativePath' => $base_url
            )
        ));
    }
    
    /**
     * Returns base URL
     * @param $url
     * @return string
     */
    function _get_base_url($url)
    {
        $parse_url = @parse_url($url);
        if ($parse_url && isset($parse_url['scheme'])) {
            $scheme = $parse_url['scheme'];
            if (isset($parse_url['host'])) {
                $host = $parse_url['host'];
                $port = (isset($parse_url['port']) && $parse_url['port'] != 80 ? ':' . $parse_url['port'] : '');
                $path = (isset($parse_url['path']) ? preg_replace('~[^/]+$~', '', $parse_url['path']) : '/');
                
                return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
            }
        }
        return false;
    }
    
    /**
     * Returns minify cache object
     *
     * @return object
     */
    function &_get_cache()
    {
        static $cache = null;
        
        if ($cache === null) {
            switch ($this->_config->get_string('minify.engine', 'memcached')) {
                case 'memcached':
                    require_once W3TC_LIB_W3_DIR . '/Cache/Memcached.php';
                    require_once W3TC_LIB_MINIFY_DIR . '/Minify/Cache/Memcache.php';
                    $this->_memcached = & W3_Cache_Memcached::instance($this->_config->get_string('minify.memcached.engine', 'auto'), array(
                        'servers' => $this->_config->get_array('minify.memcached.servers'), 
                        'persistant' => true
                    ));
                    $cache = & new Minify_Cache_Memcache($this->_memcached);
                    break;
                
                case 'apc':
                    require_once W3TC_LIB_MINIFY_DIR . '/Minify/Cache/APC.php';
                    $cache = & new Minify_Cache_APC();
                    break;
                
                default:
                    require_once W3TC_LIB_MINIFY_DIR . '/Minify/Cache/File.php';
                    if (! is_dir(W3TC_CACHE_MINIFY_DIR)) {
                        $this->log(sprintf('Cache directory %s does not exists', W3TC_CACHE_MINIFY_DIR));
                    }
                    $cache = & new Minify_Cache_File(W3TC_CACHE_MINIFY_DIR, $this->_config->get_boolean('minify.locking'));
                    break;
            }
        }
        
        return $cache;
    }
}
