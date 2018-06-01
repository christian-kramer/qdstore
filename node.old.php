<?php

error_reporting(E_ALL); ini_set('display_errors', 1);




class ShardNode
{
    
    /**
     * @var string
     */
    public $identity;

    /**
     * @var string
     */
    public $http_host;

    function __construct()
    {
        $this->identity = basename(dirname($_SERVER['SCRIPT_NAME']));
        $this->http_host = $_SERVER['HTTP_HOST'];
    }

    public function identify()
    {
        echo json_encode((object)['server' => $this->identity]);
        exit;
    }

    private function step_group_counter()
    {
        $counterfile = "counters/groupcounter";
        $counter = file_get_contents($counterfile);

        $counter++;

        file_put_contents($counterfile, $counter);
        return array_diff(range('a','z'), [$this->identity])[$counter % 25];
    }

    private function step_block_counter($group, $namespace)
    {
        journal(getcwd() . " is current working directory inside function");
        $counterfile = "data/$namespace/$group/counter";
        journal($counterfile);
        $counter = file_exists($counterfile) ? file_get_contents($counterfile) : 0;
        $block = $group . range('a', 'z')[$counter++ % 26];
        journal(file_put_contents($counterfile, $counter) . " is file_put_contents");
        return $block;
    }

    public function pick_partner()
    {
        $count = 0;
        while (empty($secondary) && $count++ < 26)
        {
            $candidate = $this->step_group_counter();
            $secondary = json_decode(file_get_contents("http://$this->http_host/servers/$candidate"))->server;
            journal("Selected $secondary as secondary");
        }

        $group = "$this->identity$secondary";
        journal("Established $group as group");
        echo json_encode((object)['group' => $group]);
        exit;
    }

    public function read($query, $namespace)
    {
        $path = "data/$namespace/$group/$query.json";
        $group = substr($query, 0, 2);
        $data = file_get_contents($path);
        $file = json_decode($data);
        $file->reads ? $file->reads++ : 0;
        file_put_contents($path, json_encode($file));
        return $data;
    }

    public function store($json_object, $group, $namespace, $block)
    {
        if (!file_exists("data/$namespace/$group"))
        {
            mkdir("data/$namespace/$group", 0755, true);
        }
        $block = $block ?? $this->step_block_counter($group, $namespace);
        journal(getcwd() . " is current working directory");
        file_put_contents("data/$namespace/$group/$block.json", $json_object);
        $receipt = [
            'timestamp' => time(),
            'url' => "http://$this->http_host?$block",
            'block' => $block
        ];
        return json_encode($receipt);
    }
}

?>