<?php

require("GlobalStorage.php");

class Usage extends \Thread
{
    public function __construct()
    {
        $this->storage = GlobalStorage::getInstance()->alloc('global' . uniqid());
        $this->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_GLOBALS);
    }

    public function run()
    {
        try {
            $tempStorage = GlobalStorage::getInstance()->alloc($tempStorageName = uniqid('tempStorage'));
            $tempStorage["asdf"] = 'asdf';
            GlobalStorage::getInstance()->free($tempStorageName);

            $othersStorage = GlobalStorage::getInstance()->find('ASDFASDF222');
            $othersStorage[$this->getThreadId()] = $this->getThreadId() . ' was here';
            
            $this->storage["threadId"] = $this->getThreadId();
        } catch (\Exception $e) {
            die($e);
        }
    }
}

GlobalStorage::init();

GlobalStorage::getInstance()->alloc("ASDFASDF111");
GlobalStorage::getInstance()->alloc("ASDFASDF222");

$t = new \Usage();

$u = array();
for ($i = 1; $i <= 10; $i++) {
    $u[$i] = new \Usage();
}

for ($i = 1; $i <= 10; $i++) {
    $u[$i]->join();
}

GlobalStorage::getInstance()->free("ASDFASDF111");

var_dump(GlobalStorage::getInstance());

// shutdown GlobalStorage
GlobalStorage::getInstance()->shutdown();

var_dump(GlobalStorage::getInstance());


