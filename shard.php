<?php

//error_reporting(E_ALL); ini_set('display_errors', 1);

define('ALPHABET', range('a', 'e'));

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
                $peer = PEERS[$counter++ % strlen(PEERS)];
                file_put_contents("../counter", $counter);
                return "$self$peer";                
            }
        );

        $entity = $args[0];
        
        return $entities[$entity]();
    },
    'current' => function ($args)
    {
        $namespace = $args[0];
        $block = $args[1];
        $subdir = substr($block, 0, 2);
        $path = "../data/$namespace/$subdir";

        if (!is_dir($path))
        {
            return error(true, 'path does not exist');
        }

        $counter = file_get_contents("$path/counter") ?? 0;

        return $counter;
    },
    'create' => function ($args)
    {
        
        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'unsupported method or content type');
        }

        
        $namespace = $args[0];
        $block = $args[1];
        $data = file_get_contents("php://input");
        $subdir = substr($block, 0, 2);
        $path = "../data/$namespace/$subdir";

        while (!file_exists($path))
        {
            mkdir($path, 0755, true);
        }


        //$primary = $block[0];
        //$role = array('secondary', 'primary')[identity() === $primary];
        $primary = identity() === $block[0];

        $error = '';
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

        $counter = file_exists("$path/counter") ? file_get_contents("$path/counter") : 0;
        journal("counter is at $path/counter");

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
                    $current = file_get_contents(storage($block[1]) . "/current/?$namespace&$block");

                    if ($current > $counter)
                    {
                        /* initiate transfer of records */
                    }
                    return error(true, $result['response']);
                }
                else
                {
                    $block .= $suffix;
                    file_put_contents("$path/$block", $data);
                    file_put_contents("$path/counter", $counter);
                    return error(false, $block);
                }
            }
        }
    },
    'update' => function ($args)
    {

        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'unsupported method or content type');
        }

        
        $namespace = $args[0];
        $block = $args[1];
        $data = file_get_contents("php://input");
        $subdir = substr($block, 0, 2);
        $path = "../data/$namespace/$subdir";
        $newdata = json_decode(file_get_contents("php://input"));
        $file = json_decode(file_get_contents("../data/$namespace/$subdir/$block"));

        while (!file_exists($path))
        {
            mkdir($path, 0755, true);
        }

        $primary = identity() === $block[0];

        $error = '';
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
                journal("data to overwrite: " . json_encode($file));

                foreach($newdata->data as $key => $value)
                {
                    if (isset($file->data->$key) && is_array($file->data->$key))
                    {
                        $file->data->$key = array_merge($file->data->$key, $newdata->data->$key);
                    }
                    else
                    {
                        $file->data->$key = $value;
                    }
                }


                if (!isset($file->writes))
                {
                    $file->writes = 0;
                }
                $file->writes++;
                file_put_contents("../data/$namespace/$subdir/$block", json_encode($file));
                journal("this is what we return to qdstore: " . json_encode($file->data));
                return json_encode($file->data);
            }
        }
        else
        {
            journal("data with which to overwrite: " . json_encode($newdata));
            journal("data to overwrite: " . json_encode($file));

            foreach($newdata->data as $key => $value)
            {
                if (isset($file->data->$key) && is_array($file->data->$key))
                {
                    $file->data->$key = array_merge($file->data->$key, $newdata->data->$key);
                }
                else
                {
                    $file->data->$key = $value;
                }
            }


            if (!isset($file->writes))
            {
                $file->writes = 0;
            }
            $file->writes++;
            file_put_contents("../data/$namespace/$subdir/$block", json_encode($file));
            journal("this is what we return to secondary: " . json_encode($file->data));
            return json_encode($file->data);
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
        $subdir = substr($block, 0, 2);
        $primary = identity() === $block[0];
        $file = json_decode(file_get_contents("../data/$namespace/$subdir/$block"));
        if ($file)
        {
            if (!$primary)
            {
                post_raw(storage($block[0]) . "/create/?$namespace&$block", json_encode(Array('data' => $file->data)));
            }
            if (!isset($file->reads))
            {
                $file->reads = 0;
            }
            $file->reads++;
            file_put_contents("../data/$namespace/$subdir/$block", json_encode($file));
            return json_encode($file->data);
        }

        return error(true, 'error retrieving file');


    },
    'props' => function ($args)
    {   /* Retrieve all other properties of specified file, without incrementing read counter */
        $namespace = $args[0];
        $block = $args[1];
        $primary = identity() === $block[0];
        $subdir = substr($block, 0, 2);
        $file = json_decode(file_get_contents("../data/$namespace/$subdir/$block"), true);
        if ($file)
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
                            $file[$key] += $value;
                        }
                    }
                }
            }
            else
            {
                post_raw(storage($block[0]) . "/create/?$namespace&$block", json_encode(Array('data' => $file->data)));
            }


            return json_encode($file);
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