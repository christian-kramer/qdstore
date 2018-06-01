<?php


//error_reporting(E_ALL); ini_set('display_errors', 1);

function journal($msg)
{
    $date = date('Y-m-d H:i:s');
    $file = date('Ymd');
    //file_put_contents($file, "$date\t$msg\n", FILE_APPEND);
}

function shards()
{
    return range('a', 'e');
}

class ShardDrive
{

    public $baseuri;
    public $alphabet;

    function __construct($alphabet)
    {
        $http_host = $_SERVER['HTTP_HOST'];
        $this->baseuri = "http://$http_host/shards";
        $this->alphabet = $alphabet;
    }

    private function failover($group)
    {
        $primary = substr($group, 0, 1);
        $secondary = substr($group, 1);
        file_get_contents("$this->baseuri/$primary") ? $storeserver = $primary : file_get_contents("$this->baseuri/$secondary") ?: exit("Both servers are down, aborting");
        return $storeserver;
    }

    public function group()
    {
        $primary = $this->alphabet[rand(0, count($this->alphabet) - 1)];
        journal("selected $primary as primary");
        journal("$this->baseuri/$primary/?partner");
        $partner = file_get_contents("$this->baseuri/$primary/?partner");
        return json_decode($partner)->group;
        
    }

    /*
    private function verify_token($token)
    {
        $hash = md5($token);
        file_exists(__DIR__ . "/tokens/$hash") ?: exit(json_encode(['error' => 'Invalid Token' ]));
        $group = file_get_contents(__DIR__ . "/tokens/$hash");
        unlink(__DIR__ . "/tokens/$hash");
        return $group;
    }
    */

    public function store($json_object, $block = null, $namespace, $token)
    {
        $group = $this->verify_token($token);
        $storeserver = $this->failover($group);
        $response = file_post_contents("$this->baseuri/$storeserver/data.php", compact('json_object', 'group', 'namespace', 'block'));
        return json_decode($response);
    }

    public function read($query, $namespace)
    {
        $group = substr($query, 0, 2);
        $storeserver = $this->failover($group);
        !$storeserver ?: $response = file_get_contents("$this->baseuri/$storeserver/data.php?query=$query&namespace=$namespace");
        return json_decode($response);
    }
}

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

    /**
     * @var ShardDrive
     */
    private $alphabet;

    function __construct($sharddrive)
    {
        $this->alphabet = Array();
        $this->identity = basename(dirname($_SERVER['SCRIPT_NAME']));
        $this->http_host = $_SERVER['HTTP_HOST'];

        foreach ($sharddrive->alphabet as $shard)
        {
            if ($this->identity !== $shard)
            {
                $this->alphabet[] = $shard;
            }
        }        
    }

    public function identify()
    {
        journal("Identified $this->identity");
        echo json_encode((object)['server' => $this->identity]);
        exit;
    }

    private function step_group_counter()
    {
        $counterfile = "counter";
        $counter = file_exists($counterfile) ? file_get_contents($counterfile) : 0;
        journal("counter is $counter");
        $peer = $this->alphabet[$counter++ % count($this->alphabet)];

        file_put_contents($counterfile, $counter);
        return $peer;
    }

    private function step_block_counter($group, $namespace)
    {
        journal(getcwd() . " is current working directory inside function");
        $counterfile = "data/$namespace/$group/counter";
        journal($counterfile);
        $counter = file_exists($counterfile) ? file_get_contents($counterfile) : 0;
        $block = $group . range('a', 'z')[$counter++ % 26];
        //journal(file_put_contents($counterfile, $counter) . " is file_put_contents");
        return $block;
    }

    public function pick_partner()
    {
        $count = 0;
        while (empty($secondary) && $count++ < 26)
        {
            $candidate = $this->step_group_counter();
            journal("$candidate is candidate");
            $secondary = json_decode(file_get_contents("http://$this->http_host/shards/$candidate"))->server;
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