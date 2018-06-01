<?php

error_reporting(E_ALL); ini_set('display_errors', 1);

$actions = Array(
    'init' => function ($path)
    {
        if (!file_exists("$path"))
        {
            foreach(range('a','e') as $identity)
            {
                mkdir("$path/$identity", 0755, true);
                /* file_put_contents("$path/$identity/data.php", '<?php include("../../data.php") ?>');*/
                file_put_contents("$path/$identity/index.php", '<?php include("../../shard.php") ?>');
            }
            
            file_put_contents("$path/.gitignore", "*\n");
        }
    },
    'group' => function ($args)
    {    
        $storage = new ShardDrive(shards());
        return $storage->group();
    },
    'create' => function ($args)
    {
        
        if (!($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json'))
        {
            return error(true, 'Unsupported Method or Content');
        }

        
        $namespace = $args[0];
        $block = $args[1];

        $data = file_get_contents("php://input");

        file_get_contents("http://storage.qdl.ink/shards/$shard/?permitted");

        file_post_contents("http://storage.qdl.ink/shards/$shard/");

    },
    'read' => function ($args)
    {   /* Retrieve Data property of specified file, and increment read counter */

    },
    'props' => function ($args)
    {   /* Retrieve all other properties of specified file, without incrementing read counter */

    }
);



/* Handle Requested Method */
route(explode('/', $_SERVER['SCRIPT_NAME']), $actions);


/* Handle all other cases */

/* Bootstrap Environment if Necessary */
build(".", $actions);

/* serve homepage */
route(['default'], ['default' => []]);

exit;

$method = array_shift($args);

if (!file_exists("shards"))
{
    $actions['init']('shards');
}

if (isset($actions[$method]) && is_callable($actions[$method]))
{
    echo $actions[$method]($args);
}


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
        }
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