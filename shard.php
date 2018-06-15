<?php

//error_reporting(E_ALL); ini_set('display_errors', 1);

define('ALPHABET', range('a', 'z'));

class File
{
    private $path;
    private $block;
    public $props;
    private $namespace;

    function __construct($namespace, $block, $load = true)
    {
        $this->namespace = $namespace;
        $this->block = $block;

        if ($load)
        {
            $this->load($block);
        }
    }

    function subdir($block)
    {
        $subdir = substr($block, 0, 2);
        return "../data/$this->namespace/$subdir";

    }

    function exists($block = null)
    {
        $block = $block ?? $this->block;
        $path = $this->subdir($block);
        return file_exists("$path/$block");
    }

    function load($block = null)
    {
        $block = $block ?? $this->block;
        $path = $this->subdir($block);

        if ($this->exists($block))
        {
            $this->props = json_decode(file_get_contents("$path/$block"), true);
        }
        else
        {
            while (!file_exists($path))
            {
                mkdir($path, 0755, true);
            }

            $this->props = array('data' => null, 'writes' => 0, 'reads' => 0);
        }

        $this->path = $path;
        $this->block = $block;
    }

    function read()
    {
        if (!empty($this->props['data']))
        {
            $this->props['reads']++;
            file_put_contents("$this->path/$this->block", json_encode($this->props));
        }
    
        return $this->props['data'];
    }

    function write($data)
    {
        $this->props['data'] = json_decode($data);
        $this->props['writes']++;

        file_put_contents("$this->path/$this->block", json_encode($this->props));
    }
}

$actions = Array(
    'identify' => function ($args)
    {
        $entities = Array(
            'self' => function ()
            {
                return identity();
            },
            'group' => function ()
            {
                $self = identity();
                $counter = file_exists("../counter") ? file_get_contents("../counter") : 0;
                journal("peer counter is $counter");
                while (empty($peer))
                {
                    $peer = PEERS[$counter++ % strlen(PEERS)];
                    if (!file_get_contents(storage($peer) . "/identify/?self"))
                    {
                        $peer = '';
                        sleep(1);
                    }
                }
                file_put_contents("../counter", $counter);
                return "$self$peer";                
            }
        );

        $entity = $args[0];
        
        return $entities[$entity]();
    },
    'create' => function ($args)
    {
        journal('entering create');
        $data = posted_json();

        if (!$data)
        {
            return error(true, 'unsupported method or content type');
        }

        journal("data is $data");

        $peers = PEERS;
        $namespace = $args[0];
        $block = $args[1];
        $primary = identity() === $block[0];
        $error = '';
        $counter = file_get_counter($namespace, $block);


        /* handle primary or custom blocks */
        if (strlen($block) > 2)
        {
            $file = new File($namespace, $block);
            
            if (!$primary)
            {
                $result = json_decode(post_raw(storage($block[0]) . "/create/?$namespace&$block", $data), true);
                if (!$result || $result['status'] === 'ERROR')
                {
                    $error = $result['response'];
                }
            }
            else
            {
                if ($file->exists())
                {
                    $error = "block exists";
                }
            }
            
            if ($error)
            {
                return error(true, $error);
            }
            else
            {
                $file->write($data);
                return error(false, $block);
            }
        }
        
        /* handle assigned blocks */
        $file = new File($namespace, $block, false);

        while (strlen($block) === 2)
        {
            journal("block is $block");
            journal("counter is $counter");
            $suffix = base26($counter++);
            journal("suffix is $suffix");
            if (!$file->exists("$block$suffix"))
            {
                journal("primary is $block[0], storage is " . storage($block[0]) . "/create/?$namespace&$block$suffix");
                
                $result = json_decode(post_raw(storage($block[0]) . "/create/?$namespace&$block$suffix", $data), true);
                journal("result status is " . $result['status']);
                
                if (!$result || $result['status'] === 'ERROR')
                {
                    /* if primary rejected write, retrieve counter from primary */
                    if ($result && $result['status'] === 'ERROR')
                    {
                        journal("retrieving foreign from " . storage($block[0]) . "/read/?$namespace&$block" . "_counter");
                        $foreign = file_get_contents(storage($block[0]) . "/read/?$namespace&$block" . "_counter");
                        journal("foreign is $foreign and counter is $counter");
                        
                        /*   if primary counter > secondary counter, overwrite secondary counter */

                        if ($foreign > $counter)
                        {
                            /* initiate transfer of records and overwrite secondary counter */
                            $counter = $foreign;
                        }
                    }
                    else
                    {
                        /* pick an unchosen primary */
                        journal("primary unavailable; picking another");
                        $peers = str_replace($block[0], '', $peers);
                        if (empty($peers))
                        {
                            return error(true, 'no primaries available');
                        }

                        $block[0] = $peers[rand(0, strlen($peers) - 1)];
                        $counter = file_get_counter($namespace, $block);
                    }
                }
                else
                {
                    $result = file_get_contents(storage($block[0]) . "/update/?$namespace&$block" . "_counter&$counter");
                    journal("result from counter update was $result");
                    $block .= $suffix;
                    $file->load($block);
                    $file->write($data);
                    file_put_counter($namespace, $block, $counter);
                    return error(false, $block);
                }
            }
        }
    },
    'create2' => function ($args)
    {
        
        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'unsupported method or content type');
        }

        $peers = PEERS;
        $namespace = $args[0];
        $block = $args[1];
        $data = file_get_contents("php://input");
        $primary = identity() === $block[0];
        $error = '';

        $subdir = substr($block, 0, 2);
        $path = "../data/$namespace/$subdir";

        while (!file_exists($path))
        {
            mkdir($path, 0755, true);
        }
        $counter = file_exists("$path/_counter") ? file_get_contents("$path/_counter") : 0;

        /* handle primary or custom blocks */
        if (strlen($block) > 2)
        {
            
            if (!$primary)
            {
                $result = json_decode(post_raw(storage($block[0]) . "/create/?$namespace&$block", $data), true);
                if (!$result || $result['status'] === 'ERROR')
                {
                    $error = $result['response'];
                }
                else
                {
                    file_put_contents("$path/$block", $data);
                }
            }
            else
            {
                if (file_exists("$path/$block"))
                {
                    $error = "block exists";
                }
                else
                {
                    file_put_contents("$path/$block", $data);
                }
            }
            
            if ($error)
            {
                return error(true, $error);
            }
            else
            {
                return error(false, $block);
            }
        }

        /* handle assigned blocks */
        while (strlen($block) === 2)
        {        
            journal("block  counter is $counter");
            $suffix = base26($counter++);
            journal("suffix is $suffix");
            journal("path is $path/$block$suffix");
            if (!file_exists("$path/$block$suffix"))
            {
                journal("primary is $block[0], storage is " . storage($block[0]) . "/create/?$namespace&$block$suffix");
                
                $result = json_decode(post_raw(storage($block[0]) . "/create/?$namespace&$block$suffix", $data), true);
                journal("result status is " . $result['status']);
                
                if (!$result || $result['status'] === 'ERROR')
                {
                    /* if primary rejected write, retrieve counter from primary */
                    if ($result && $result['status'] === 'ERROR')
                    {
                        journal("retrieving foreign from " . storage($block[0]) . "/read/?$namespace&$block" . "_counter");
                        $foreign = file_get_contents(storage($block[0]) . "/read/?$namespace&$block" . "_counter");
                        journal("foreign is $foreign and counter is $counter");
                        
                        /*   if primary counter > secondary counter, overwrite secondary counter */

                        if ($foreign > $counter)
                        {
                            /* initiate transfer of records and overwrite secondary counter */
                            $counter = $foreign;
                        }
                    }
                    else
                    {
                        /* pick an unchosen primary */
                        journal("primary unavailable; picking another");
                        $peers = str_replace($block[0], '', $peers);
                        if (empty($peers))
                        {
                            return error(true, 'no primaries available');
                        }

                        $block[0] = $peers[rand(0, strlen($peers) - 1)];
                        $subdir = $block;
                        $path = "../data/$namespace/$subdir";
                        while (!file_exists($path))
                        {
                            mkdir($path, 0755, true);
                        }
                        $counter = file_exists("$path/_counter") ? file_get_contents("$path/_counter") : 0;
                    }
                }
                else
                {
                    $result = file_get_contents(storage($block[0]) . "/update/?$namespace&$block" . "_counter&$counter");
                    journal("result from counter update was $result");
                    $block .= $suffix;
                    file_put_contents("$path/$block", $data);
                    file_put_contents("$path/_counter", $counter);
                }
            }
        }
    },
    'update' => function ($args)
    {
        $namespace = $args[0];
        $block = $args[1];
        $primary = identity() === $block[0];
        
        if ($primary && isset($args[2]) && substr($block, 2, 8) === '_counter')
        {
            return file_put_counter($namespace, $block, $args[2]);
        }

        $data = posted_json();

        if (!$data)
        {
            return error(true, 'unsupported method or content type');
        }

        if (!$primary)
        {
            $result = json_decode(post_raw(storage($block[0]) . "/update/?$namespace&$block", $data), true);
            if (!$result || $result['status'] === 'ERROR')
            {
                return json_encode($result);
            }
        }

        $file = new File($namespace, $block);
        $file->write($data);
        return $data;
    },
    'update2' => function ($args)
    {
        $namespace = $args[0];
        $block = $args[1];
        $subdir = substr($block, 0, 2);
        $primary = identity() === $block[0];
        
        if ($primary && isset($args[2]) && substr($block, 2, 8) === '_counter')
        {
            /* Probably redundant safety check */
            $counter = $args[2];
            if (file_exists("../data/$namespace/$subdir/_counter"))
            {
                $counter = file_get_contents("../data/$namespace/$subdir/_counter");
                if ($args[2] > $counter)
                {
                    $counter = $args[2];
                }
            }
            file_put_contents("../data/$namespace/$subdir/_counter", $counter);
            return error(false, "counter updated to $counter");
        }

        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'unsupported method or content type');
        }
        
        $data = file_get_contents("php://input");
        $path = "../data/$namespace/$subdir";
        $newdata = json_decode($data);
        $file = file_get_contents("../data/$namespace/$subdir/$block");
        $olddata = json_decode($file);

        while (!file_exists($path))
        {
            mkdir($path, 0755, true);
        }


        $error = '';

        journal("block is $block");
        /* handle primary or custom blocks */
        if (!$primary)
        {
            $result = json_decode(post_raw(storage($block[0]) . "/update/?$namespace&$block", $data), true);
            if (!$result || $result['status'] === 'ERROR')
            {
                $error = $result['response'];
            }
            else
            {
                journal("data with which to overwrite: " . json_encode($newdata));
                journal("data to overwrite: " . json_encode($olddata));

                foreach($newdata->data as $key => $value)
                {
                    journal("writing $value to $key");
                    $olddata->data->$key = $value;
                    /*
                    if (isset($olddata->data->$key) && is_array($olddata->data->$key))
                    {
                        $olddata->data->$key = array_merge($olddata->data->$key, $newdata->data->$key);
                    }
                    else
                    {
                        $olddata->data->$key = $value;
                    }
                    */
                }


                if (!isset($olddata->writes))
                {
                    $olddata->writes = 0;
                }
                $olddata->writes++;
                file_put_contents("../data/$namespace/$subdir/$block", json_encode($olddata));
                journal("this is what we return to qdstore: " . json_encode($olddata->data));
                return json_encode($olddata->data);
            }
        }
        else
        {
            journal("data with which to overwrite: " . json_encode($newdata));
            journal("data to overwrite: " . json_encode($olddata));

            foreach($newdata->data as $key => $value)
            {
                $olddata->data->$key = $value;
                /*
                if (isset($olddata->data->$key) && is_array($olddata->data->$key))
                {
                    $olddata->data->$key = array_merge($olddata->data->$key, $newdata->data->$key);
                }
                else
                {
                    $olddata->data->$key = $value;
                }
                */
            }


            if (!isset($olddata->writes))
            {
                $olddata->writes = 0;
            }
            $olddata->writes++;
            file_put_contents("../data/$namespace/$subdir/$block", json_encode($olddata));
            journal("this is what we return to secondary: " . json_encode($olddata->data));
            return json_encode($olddata->data);
        }
        
        if ($error)
        {
            return error(true, $error);
        }
        else
        {
            return error(false, $block);
        }
    },
    'read' => function ($args)
    {   /* Retrieve Data property of specified file, and increment read counter */
        $namespace = $args[0];
        $block = $args[1];
        $primary = identity() === $block[0];

        if ($primary && substr($block, 2, 8) === '_counter')
        {
            return file_get_counter($namespace, $block);
        }


        $file = new File($namespace, $block);
        if ($file->exists())
        {
            /* file is available locally */
            if ($primary)
            {
                /* normal situation, replicate to secondary if appropriate */
                return json_encode($file->read());
            }
            else
            {
                /* primary unavailable or routine replication check */
                return json_encode($file->read());
            }
        }
        else
        {
            /* file is not available locally */
            if ($primary)
            {
                /* failure situation, read from secondary */
                $storage = json_decode(file_get_contents(storage($block[1]) . "/read/?$namespace&$block"), true);
                if ($storage)
                {
                    if (!isset($storage['status']))
                    {
                        
                    }
                    return json_encode($storage);
                }
            }
        }

        return error(true, 'error retrieving file');
    },
    'props' => function ($args)
    {   /* Retrieve all other properties of specified file, without incrementing read counter */
        $namespace = $args[0];
        $block = $args[1];
        $primary = identity() === $block[0];
        $subdir = substr($block, 0, 2);
        $file = new File($namespace, $block);
        //$file = json_decode(file_get_contents("../data/$namespace/$subdir/$block"), true);
        if ($file->exists())
        {
            //unset($file['data']);
            
            if ($primary)
            {
                $replica = json_decode(file_get_contents(storage($block[1]) . "/props/?$namespace&$block"), true);
                if ($replica)
                {
                    //unset($replica['data']);

                    foreach ($replica as $key => $value)
                    {
                        if (is_numeric($value))
                        {
                            $file->props[$key] += $value;
                        }
                    }
                }
            }
            /*
            else
            {
                post_raw(storage($block[0]) . "/create/?$namespace&$block", json_encode(Array('data' => $file->data)));
            }
            */


            return json_encode($file->props);
        }

        return error(true, 'error retrieving file');
    }
);

/* Handle Requested Method */
route(explode('/', $_SERVER['SCRIPT_NAME']), $actions);


/* Bootstrap Environment if Necessary */
build(".", $actions);


/* serve homepage */
route(['default'], ['default' => []]);



function build($path, $actions)
{
    $include = '<?php include "../index.php" ?>';
    foreach ($actions as $name => $value)
    {
        if (!file_exists("$path/$name"))
        {
            mkdir("$path/$name", 0755, true);
            
            if (is_array($value))
            {
                build("$path/$name", $value);
            }

            file_put_contents("$path/$name/index.php", $include);
        }
    }
    
    if (empty(PEERS))
    {
        $self = file_get_contents("http://" . $_SERVER['HTTP_HOST'] . str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']) . "/identify?self");
        $peers = implode('', array_diff(ALPHABET, array($self)));
        $index = file_get_contents($_SERVER["DOCUMENT_ROOT"] . $_SERVER['PHP_SELF']);

        file_put_contents($_SERVER["DOCUMENT_ROOT"] . $_SERVER['PHP_SELF'], str_replace('define("PEERS", "");', 'define("PEERS", "' . $peers . '");', $index));
    }
}

function route($request, $actions)
{
    $context = array_shift($request);

    if (isset($actions[$context]))
    {
        if (is_callable($actions[$context]))
        {
            echo $actions[$context](explode('&', $_SERVER['QUERY_STRING']));
            exit;
        }
        
        if (is_array($actions[$context]))
        {
            if (count($request) > 0)
            {
                route($request, $actions[$context]);
            }
            else
            {
                $path = journal('html' . dirname($_SERVER['SCRIPT_NAME']));

                if (file_exists("$path/index.html"))
                {
                    echo file_get_contents("$path/index.html");
                }
                else
                {
                    http_response_code(404);
                    header('HTTP/1.0 404 Not Found', true, 404);
                    echo "<h1>404 Not Found</h1>";
                    exit;
                }

                exit;
            }
        }
    }
    else
    {
        if (count($request) > 0)
        {
            route($request, $actions);
        }
    }
}

function base26($decimal)
{
    $base = count(ALPHABET);
    $quotient = floor($decimal / $base);
    $remainder = $decimal % $base;
    return ($quotient ? base26($quotient) : '') . ALPHABET[$remainder];
}

function post_raw($url, $data)
{
    journal("preparing to encode $data");
    $opts = array('http' =>
        array(
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => $data
        )
    );
    
    $context  = stream_context_create($opts);

    $result = file_get_contents($url, false, $context);
    
    journal("result was $result");
    
    return $result;
}

function identity()
{
    return basename(dirname('../' . dirname($_SERVER['SCRIPT_NAME'])));
}

function storage($shard)
{
    return "http://" . $_SERVER['HTTP_HOST'] . "/shards/$shard";
}

function posted_json()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json')
    {
        return file_get_contents("php://input");
    }
}

function file_put_counter($namespace, $block, $value = 0)
{
    $subdir = substr($block, 0, 2);
    $path = "../data/$namespace/$subdir";
    if (file_exists("$path/_counter"))
    {
        $counter = file_get_contents("$path/_counter");
    }
    if ($value > ($counter ?? 0))
    {
        file_put_contents("$path/_counter", $value);
    }
    return error(false, "counter updated to $counter");
}

function file_get_counter($namespace, $block)
{
    $subdir = substr($block, 0, 2);
    $path = "../data/$namespace/$subdir";
    return file_exists("$path/_counter") ? file_get_contents("$path/_counter") : 0;
}

/*
function file_put_data($namespace, $block, $newdata)
{
    $data = json_decode($newdata);
    $file = json_decode(file_get_props($namespace, $block), true);

    if ($file)
    {
        // Update
        foreach($data as $key => $value)
        {
            $file['data'][$key] = $value;
        }

        $file->writes++;
    }
    else
    {
        // Create
        $file = array('data' => $data, 'writes' => 1);
    }
    return file_put_props($namespace, $block, $file);
}
*/

/*
function file_get_data($namespace, $block)
{
    $file = json_decode(file_get_props($namespace, $block));
    if ($file)
    {
        $reads = isset($file->reads) ? $file->reads : 0;
        $reads++;
        file_put_props($namespace, $block, array('reads' => $reads));
    
        return json_encode($file->data);
    }
}
*/

/*
function file_get_props($namespace, $block)
{
    $subdir = substr($block, 0, 2);
    $path = "../data/$namespace/$subdir";

    if (file_exists("$path/$block"))
    {
        return file_get_contents("$path/$block");
    }
}

function file_put_props($namespace, $block, $props)
{
    $subdir = substr($block, 0, 2);
    $path = "../data/$namespace/$subdir";

    while (!file_exists($path))
    {
        mkdir($path, 0755, true);
    }

    $file = json_decode(file_get_contents("$path/$block"));

    foreach($props as $key => $value)
    {
        $file->$key = $value;
    }
    
    return file_put_contents("$path/$block", json_encode($file));
}
*/

function journal($msg)
{
    $path = 'logs';
    $date = date('Y-m-d H:i:s');
    $file = date('Ymd');

    if ($msg && (file_exists($path) || (mkdir($path, 0755, true) && file_put_contents("$path/.gitignore", "*\n"))))
    {
        file_put_contents("$path/$file", "$date\t$msg\n", FILE_APPEND);
    }

    return $msg;
}


function error($failed, $message)
{
    $status = Array('SUCCESS', 'ERROR')[$failed];
    $response = Array('status' => $status, 'response' => journal($message));
    return json_encode($response);
}

?>