<?php

class GlobalStorage extends \Thread
{
    const CMD_ALLOC = 'alloc';
    CONST CMD_FREE = 'free';

    public function __construct($startFlags = null)
    {
        if (is_null($startFlags)) {
            $startFlags = PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_GLOBALS;
        }
        $this->start($startFlags);
    }

    static public function getGlobalVarName()
    {
        $cn = __CLASS__;
        return '__' . strtolower(substr($cn, strrpos($cn, '\\')+(int)($cn[0] === '\\')));
    }

    static public function init()
    {
        $globalVarName = self::getGlobalVarName();
        global $$globalVarName;
        if (is_null($$globalVarName)) {
            $$globalVarName = new self();
        }
    }

    static public function getInstance()
    {
        $globalVarName = self::getGlobalVarName();
        global $$globalVarName;
        if (is_null($$globalVarName)) {
            throw new \Exception(sprintf("Failed to get instance '$%s'. Please call init() in global scope first and check if PTHREADS_ALLOW_GLOBALS flag is set in specific Thread start calls.", $globalVarName));
        }
        return $$globalVarName;
    }

    protected function __ex($cmd, $args)
    {
        $this->synchronized(function ($self, $cmd, $args) {
            if ($self->run === false) {
                $self->run = true;
                $self->cmd = $cmd;
                $self->args = $args;
                $self->notify();
            }
        }, $this, $cmd, $args);

        while($this->run) {
            usleep(1000);
        }

        if ($this->exception) {
            throw new $this->exception;
        }

        return $this->return;
    }

    public function __call($name, $args)
    {
        return $this->__ex($name, $args);
    }

    public function run()
    {
        // storage reference array to rais ref count on storage instances for being able to share at runtime.
        $storageRefs = array();
        $this->return = null;
        $this->exception = null;

        while(true) {
            $this->synchronized(function ($self) {
                $this->cmd = null;
                $this->args = array();
                $self->run = false;
                $self->wait();
                $this->exception = null;
                $this->return = null;
            }, $this);


            try {
                switch ($this->cmd) {

                    case self::CMD_ALLOC:
                        $storage = $this->args[0];
                        if (isset($this["{$storage}"])) {
                            throw new \Exception(sprintf("Storage %s, has already been allocated.", $storage));
                        }
                        $this->return = $this["{$storage}"] = $storageRefs["{$storage}"] = new \Threaded();
                        break;

                    case self::CMD_FREE:
                        $storage = $this->args[0];
                        if (!isset($this["{$storage}"])) {
                            throw new \Exception(sprintf("Tried to free storage %s, that which has not yet been allocated.", $storage));
                        }
                        unset($storageRefs["{$storage}"]);
                        unset($this["{$storage}"]);
                        $this->return = true;
                        break;

                    default:
                        throw new \Exception(sprintf('cmd %s not available', $this->cmd));
                }
            } catch (\Exception $e) {
                $this->exception = $e;
            }
        }
    }
}