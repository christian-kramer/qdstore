<?php

//error_reporting(E_ALL); ini_set('display_errors', 1);

define('ALPHABET', range('a', 'z'));
define('MULTI_SERVER', true);

$actions = Array(
    'group' => function ($args)
    {
        $primary = ALPHABET[rand(0, count(ALPHABET) - 1)];
        journal("selected $primary as primary");
        return file_get_contents(storage($primary) . "/identify/?group");
        //return 'ab';
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
        
        if (strlen($block) < 2 || !ctype_alnum($block) || $block[0] === $block[1])
        {
            return error(true, "invalid block");
        }

        $secondary = $block[1];

        return post_raw(storage($secondary) . "/create/?$namespace&$block", $data);

    },
    'update' => function ($args)
    {   /* Write file->data */
        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'unsupported method or content type');
        }

        $namespace = $args[0];
        $block = $args[1];
        $data = file_get_contents("php://input");
        $secondary = $block[1];

        journal("sending data " . $data);
        journal("to " . storage($secondary) . "/update/?$namespace&$block");
        
        if (strlen($block) < 2 || !ctype_alnum($block) || $block[0] === $block[1])
        {
            return error(true, "invalid block");
        }

        $result = post_raw(storage($secondary) . "/update/?$namespace&$block", $data);
        journal("this is what we return to qdlink: " . $result);
        return $result;
    },
    'read' => function ($args)
    {   /* Retrieve file->data */
        $namespace = $args[0];
        $block = $args[1];

        if (strlen($block) < 2 || !ctype_alnum($block))
        {
            return error(true, "invalid block");
        }

        $primary = $block[0];
        $secondary = $block[1];

        /* Attempt to read from Primary */
        $storage = json_decode(file_get_contents(storage($primary) . "/read/?$namespace&$block"), true);
        if (!$storage)
        {
            /* Primary unavailable, divert to secondary */
            $storage = json_decode(file_get_contents(storage($secondary) . "/read/?$namespace&$block"), true);
            if (!$storage)
            {
                return error(true, "could not reach servers");
            }
        }
        return json_encode($storage);

    },
    'props' => function ($args)
    {   /* Retrieve all other properties of specified file, without incrementing read counter */
        $namespace = $args[0];
        $block = $args[1];

        if (strlen($block) < 2 || !ctype_alnum($block))
        {
            return error(true, "invalid block");
        }

        $primary = $block[0];
        $secondary = $block[1];

        $storage = json_decode(file_get_contents(storage($primary) . "/props/?$namespace&$block"), true);
        if (!$storage || (isset($storage['status']) && $storage['status'] === 'ERROR'))
        {
            $storage = json_decode(file_get_contents(storage($secondary) . "/props/?$namespace&$block"), true);
            if (!$storage)
            {
                return error(true, "could not reach servers");
            }
            if (isset($storage['status']) && $storage['status'] === 'ERROR')
            {
                return error(true, "link could not be found");
            }
        }
        return json_encode($storage);
    }
);


/* Handle Requested Method */
route(explode('/', $_SERVER['SCRIPT_NAME']), $actions);


/* Handle all other cases */

/* Bootstrap Environment if Necessary */
build(".", $actions);

/* Initialize Shards (only relevant when shards are subdirectories) */
init("shards");

/* serve homepage */
route(['default'], ['default' => []]);







function build($path, $actions)
{
    $include = '<?php include $_SERVER["DOCUMENT_ROOT"] . "/index.php" ?>';
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
            file_put_contents("$path/$name/.gitignore", "*\n");
        }
    }
}


function init($path)
{
    if (!file_exists("$path") && !MULTI_SERVER)
    {
        foreach(ALPHABET as $identity)
        {
            mkdir("$path/$identity", 0755, true);
            /* file_put_contents("$path/$identity/data.php", '<?php include("../../data.php") ?>');*/
            if (file_put_contents("$path/$identity/index.php", '<?php define("PEERS", ""); include $_SERVER["DOCUMENT_ROOT"] . "/shard.php" ?>'))
            {
                file_get_contents("http://" . $_SERVER['HTTP_HOST'] . "/$path/$identity");
            }
        }
        
        file_put_contents("$path/.gitignore", "*\n");
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

function storage($shard)
{
    if (MULTI_SERVER)
    {
        return "$shard." . $_SERVER['HTTP_HOST'];
    }
    return "http://" . $_SERVER['HTTP_HOST'] . "/shards/$shard";
}

function post_raw($url, $data)
{
    $opts = array('http' =>
        array(
            'method'  => 'POST',
            'header'  => 'Content-type: application/json',
            'content' => $data
        )
    );
    journal("posting raw $data to $url");
    $context  = stream_context_create($opts);

    $return = file_get_contents($url, false, $context);
    journal("recieved $return from $url");
    return $return;
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