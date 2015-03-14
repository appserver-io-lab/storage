<?php

/**
 * StaticGlobalStorage.php
 *
 * This is a proof of concept how to handle and make use of an global storage
 * object where is able to share multiple stores automatically between threads.
 *
 * It's solved by using static functions and php globals via global statement
 * with pthreads v2.0.10. It should also work with v2.0.8
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Johann Zelger <jz@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io-lab/storage
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Labs;

define('APPSERVER_AUTOLOADER', 'vendor/autoload.php');

// define consts for global storage static class name handling to keep it
// centralized maintainable and easy to replace with other static class names.
// This could also be done with class aliases: http://php.net/manual/de/function.class-alias.php
define('APPSERVER_GLOBALSTORAGE_CLASSNAME', '\AppserverIo\Labs\StaticGlobalStorage');
define('APPSERVER_GLOBALSTORAGE_GLOBAL_VARNAME', APPSERVER_GLOBALSTORAGE_CLASSNAME . '::GLOBAL_VARNAME');
define('APPSERVER_GLOBALSTORAGE_FACTORY', APPSERVER_GLOBALSTORAGE_CLASSNAME . '::getInstance');
define('APPSERVER_GLOBALSTORAGE_ALLOC', APPSERVER_GLOBALSTORAGE_CLASSNAME . '::alloc');
define('APPSERVER_STORAGE_GLOBAL', 'global');

// require composer autoloader if exists
if (is_file(APPSERVER_AUTOLOADER)) {
    require(APPSERVER_AUTOLOADER);
}

// a simple generic stackable object
class GenericStackable extends \Stackable {}

// simple test thread which uses the naming directory to create and read stuff
class TestThread extends \Thread {
    public function injectNamingDirectory($nd) {
        $this->nd = $nd;
    }
    public function run() {
        $this->nd->createSubdirectory('threaded')
            ->createSubdirectory($this->getThreadId());
        $this->threaded = $this->nd->getAttribute('threaded');
        $this->nd->createSubdirectory('wooohooo')
            ->createSubdirectory('bangarang');
        $this->wooohooo = $this->nd->getAttribute('wooohooo');
    }
}

// a c flavoured static global storage object
class StaticGlobalStorage {
    const GLOBAL_VARNAME = __CLASS__;
    static public $storages = null;
    static public $instance = null;
    static public function getInstance() {
        if (!self::$instance) {
            $instance = new self();
            $instance::init();
            self::$instance = $instance;
        }
        return self::$instance;
    }
    static public function alloc($storages) {
        self::$storages = new \Stackable();
        foreach ($storages as $storageName => $storageType) {
            self::$storages[$storageName] = new $storageType();
        }
        return self::$storages;
    }
    static public function init() {
        global ${self::GLOBAL_VARNAME};
        self::$storages = ${self::GLOBAL_VARNAME};
    }
    public function set($storage, $key, $value) {
        self::$storages[$storage][$key] = $value;
    }
    public function get($storage, $key) {
        return self::$storages[$storage][$key];
    }
}

// register our static global storage object at global context via defined constants
// to keep it centralized maintainable and easy to replace with other static class names.
${constant(APPSERVER_GLOBALSTORAGE_GLOBAL_VARNAME)} = call_user_func_array(APPSERVER_GLOBALSTORAGE_ALLOC, array(
    array(APPSERVER_STORAGE_GLOBAL => '\AppserverIo\Labs\GenericStackable')
));

// a naming directory implementation
class NamingDirectory // implements NamingDirectoryInterface
{
    // use BindingTrait;
    public function __construct($name, $path = "") {
        $this->name = $name;
        $this->path = $path . DIRECTORY_SEPARATOR . $name;
        $this->getGlobalStorage()->set(APPSERVER_STORAGE_GLOBAL, $this->path, $this);
    }
    public function getGlobalStorage() {
        $globalStorageClassname = APPSERVER_GLOBALSTORAGE_CLASSNAME;
        return $globalStorageClassname::getInstance();
    }
    public function getAttribute($key) {
        return $this->getGlobalStorage()->get(APPSERVER_STORAGE_GLOBAL, $this->path . DIRECTORY_SEPARATOR . $key);
    }
    public function getName() {
        return $this->name;
    }
    public function getScheme() {
        return 'php';
    }
    public function createSubdirectory($name, array $filter = array()) {
        return new NamingDirectory($name, $this->path);
    }
}

// now lets create a naming directory and play with it
$nd = new \AppserverIo\Labs\NamingDirectory('env');
$nd->createSubdirectory('appBase');
// check if threads can also use the naming directory which uses our global storage
$t = array();
$maxThreads = 8;
for ($i=1; $i<=$maxThreads; $i++) {
    $t[$i] = new TestThread();
    $t[$i]->injectNamingDirectory($nd);
    $t[$i]->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_GLOBALS);
}
// wait if last thread is ready and join him.
$t[$maxThreads]->join();
// create some other stuff
$nd->createSubdirectory('tmpDirectory');
$nd->createSubdirectory('cacheDirectory');

// check if dump works and looks good
var_dump(${constant(APPSERVER_GLOBALSTORAGE_GLOBAL_VARNAME)});
