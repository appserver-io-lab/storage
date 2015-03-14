<?php

/**
 * GlobalStorage.php
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
class GlobalStorage extends \Thread
{
    /**
     * Defines command dictionary entry for alloc
     * 
     * @var string
     */
    const CMD_ALLOC = 'alloc';
    
    /**
     * Defines command dictionary entry for free
     *
     * @var string
     */
    CONST CMD_FREE = 'free';
    
    /**
     * Defines command dictionary entry for find
     *
     * @var string
     */
    CONST CMD_FIND = 'find';
    
    /**
     * Defines command dictionary entry for shutdown
     * 
     * @var string
     */
    CONST CMD_SHUTDOWN = 'shutdown';

    /**
     * Defines default type for storages to be allocated
     * 
     * @var string
     */
    CONST STORAGE_TYPE_DEFAULT = '\Threaded';

    /**
     * Contructor
     * 
     * @param string $startFlags The start flags used for starting threads
     */
    public function __construct($startFlags = null)
    {
        if (is_null($startFlags)) {
            $startFlags = PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_GLOBALS;
        }
        $this->start($startFlags);
    }

    /**
     * Return a valid variable name to be set as global variable
     * based on own class name with automatic namespace cutoff
     * 
     * @static
     * @return string
     */
    static public function getGlobalVarName()
    {
        $cn = __CLASS__;
        return '__' . strtolower(substr($cn, strrpos($cn, '\\')+(int)($cn[0] === '\\')));
    }

    /**
     * Initialises the global storage.
     * This fuction should be called on global scope.
     * 
     * @static
     * @return void
     */
    static public function init()
    {
        $globalVarName = self::getGlobalVarName();
        global $$globalVarName;
        if (is_null($$globalVarName)) {
            return $$globalVarName = new self();
        }
    }

    /**
     * Returns the instance created in global scope
     * 
     * @static
     * @return GlobalStorage The global storage instance
     */
    static public function getInstance()
    {
        $globalVarName = self::getGlobalVarName();
        global $$globalVarName;
        if (is_null($$globalVarName)) {
            throw new \Exception(sprintf("Failed to get instance '$%s'. Please call init() in global scope first and check if PTHREADS_ALLOW_GLOBALS flag is set in specific Thread start calls.", $globalVarName));
        }
        return $$globalVarName;
    }

    /**
     * Executes the given command and arguments in a synchronized way.
     * 
     * This function is intend to be protected to make use of automatic looking
     * when calling this function to avoid race conditions and dead-locks.
     * This means this function can not be called simultaneously.
     * 
     * @param string $cmd  The command to execute
     * @param string $args The arguments for the command to be executed
     * 
     * @return mixed The return value we got from execution
     */
    protected function __ex($cmd, $args)
    {
        // check if execution is going on
        if ($this->run === true) {
            // return null in that case to avoid possible race conditions
            return null;
        }
        // synced execution
        $this->synchronized(function ($self, $cmd, $args) {
            // set run flag to be true cause we wanna run now
            $self->run = true;
            // set command and argument values
            $self->cmd = $cmd;
            $self->args = $args;
            // notify to start execution
            $self->notify();
        }, $this, $cmd, $args);

        // wait while execution is running
        while($this->run) {
            // sleep a little while waiting loop
            usleep(1000);
        }
        
        // check if an exceptions was thrown and throw it again in this context.
        if ($this->exception) {
            throw new $this->exception;
        }
        
        // return the return value we got from execution
        return $this->return;
    }

    /**
     * Introduce a magic __call function to delegate all method to the internal
     * execution functionality. If you hit a Method which is not available in executor
     * logic, it will throw an exception as you would get a fatal error if you want to call
     * a function on undefined object.
     * 
     * @param string $cmd  The command to execute
     * @param string $args The arguments for the command to be executed
     * 
     * @return mixed The return value we got from execution
     */
    public function __call($name, $args)
    {
        return $this->__ex($name, $args);
    }

    /**
     * The main thread routine function
     * 
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        
        // storage reference array to raise ref count on storage instances for being able to share them at runtime.
        $storageRefs = array();
        // set initial param values
        $this->return = null;
        $this->exception = null;
        // set shutdown flag internally so that its only possible change it via shutdown command
        $shutdown = false;
        
        // loop while no shutdown command was sent
        do {
            // synced execution
            $this->synchronized(function ($self) {
                // set initial param values
                $this->cmd = null;
                $this->args = array();
                $self->run = false;
                $self->wait();
                // reset return and exception properties
                $this->exception = null;
                $this->return = null;
            }, $this);
            
            // try to execute given command with arguments
            try {
                
                // check which cmd we have
                switch ($this->cmd) {

                    // in case of 'alloc'
                    case self::CMD_ALLOC:
                        // expand arguments
                        @list($storage, $type) = $this->args;
                        // check required arguments
                        if (!$storage) {
                            throw new \Exception(sprintf("Missing argument 1 for %s::%s", __CLASS__, $this->cmd));
                        }
                        // check optional arguments
                        if (!$type) {
                            $type = self::STORAGE_TYPE_DEFAULT;
                        }
                        // check if storage was allocated before
                        if (isset($this["{$storage}"])) {
                            throw new \Exception(sprintf("Storage %s, has already been allocated.", $storage));
                        }
                        // allocate storage and set doubled reference to not loose objects on the other side.
                        $this->return = $this["{$storage}"] = $storageRefs["{$storage}"] = new $type();
                        break;

                    // in case of 'free'
                    case self::CMD_FREE:
                        // expand arguments
                        @list($storage, $type) = $this->args;
                        // check required arguments
                        if (!$storage) {
                            throw new \Exception(sprintf("Missing argument 1 for %s::%s", __CLASS__, $this->cmd));
                        }
                        // check if storage was not allocated before
                        if (!isset($this["{$storage}"])) {
                            throw new \Exception(sprintf("Tried to free storage %s, that which has not yet been allocated.", $storage));
                        }
                        // free first ref on internal storageRef array
                        unset($storageRefs["{$storage}"]);
                        // free second ref in own register
                        unset($this["{$storage}"]);
                        // return true to say that it has worked
                        $this->return = true;
                        break;
                        
                    // in case of 'find'
                    case self::CMD_FIND:
                        // expand arguments
                        @list($storage) = $this->args;
                        // check required arguments
                        if (!$storage) {
                            throw new \Exception(sprintf("Missing argument 1 for %s::%s", __CLASS__, $this->cmd));
                        }
                        // check if storage was not allocated before
                        if (!isset($this["{$storage}"])) {
                            throw new \Exception(sprintf("Tried to find storage %s, that which has not yet been allocated.", $storage));
                        }
                        // return found storage
                        $this->return = $this["{$storage}"];
                        break;
                    
                        // in case of 'shutdown'
                    case self::CMD_SHUTDOWN:
                        // free all allocated storages
                        foreach ($storageRefs as $storageKey => $storageInstance) {
                            // free first ref on internal storageRef array
                            unset($storageRefs["{$storageKey}"]);
                            // free second ref in own register
                            unset($this["{$storageKey}"]);
                        }
                        // now stop synced command execution loop and shutdown the threads context
                        $shutdown = true;
                        $this->return = true;
                        // set run to false to release possible last executor wait loop
                        $this->run = false;
                        break;

                    // in case of any other commands
                    default:
                        // default behaviour for non implemented commands
                        throw new \Exception(sprintf('Call to undefined command %s::%s()', __CLASS__, $this->cmd));
                }
            } catch (\Exception $e) {
                // catch and hold all exceptions throws while processing for further usage
                $this->exception = $e;
            }
            
        } while($shutdown === false);
    }
}
