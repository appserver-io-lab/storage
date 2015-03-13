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

            $this->storage["test"] = microtime(true);
        } catch (\Exception $e) {
            die($e);
        }
    }
}

GlobalStorage::init();

$t = new \Usage();

$u = array();
for ($i = 1; $i <= 10; $i++) {
    $u[$i] = new \Usage();
}

for ($i = 1; $i <= 10; $i++) {
    $u[$i]->join();
}

GlobalStorage::getInstance()->alloc("ASDFASDF111");
GlobalStorage::getInstance()->alloc("ASDFASDF222");
GlobalStorage::getInstance()->free("ASDFASDF111");

var_dump(GlobalStorage::getInstance());
