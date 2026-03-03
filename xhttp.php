<?php

//$log = fopen(__DIR__ . "/xhttp.log", 'a');
$log = fopen('/dev/null', 'w');

function hex_dump($data, $newline="\n")
{
    global $log;
    static $from = '';
    static $to = '';
    
    static $width = 16; # number of bytes per line
    
    static $pad = '.'; # padding for non-visible characters
    
    if ($from==='')
    {
        for ($i=0; $i<=0xFF; $i++)
        {
        $from .= chr($i);
        $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
        }
    }
    
    $hex = str_split(bin2hex($data), $width*2);
    $chars = str_split(strtr($data, $from, $to), $width);
    
    $offset = 0;
    foreach ($hex as $i => $line)
    {
        fprintf($log, "%s", sprintf('%6X',$offset). ' : ' .implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . $newline);
        fflush($log);

        $offset += $width;
    }
}

// unix socket
$socket_path = realpath(__DIR__ . "/../xhttp.sock");
fprintf($log, "socket path: %s\n", __DIR__ . "/../xhttp.sock");
fprintf($log, "socket path: %s\n", $socket_path);
fflush($log);

// match for {uuid}/{optional seq}
if (!preg_match('/([0-9a-fA-F]{8}-[0-9A-Fa-f]{4}-4[0-9A-Fa-f]{3}-[89ABab][0-9a-fA-F]{3}-[0-9a-fA-F]{12})\/?(\d*)/', $_SERVER['REQUEST_URI'], $matches)) {
    fprintf($log, "Invalid request URI: %s\n", $_SERVER['REQUEST_URI']);
    http_response_code(404);
    exit;
}
$uuid = $matches[1];
$seq = $matches[2];
if (!$seq) $seq = 0;

fprintf($log, "UUID = %s, Method = %s, Seq = %s\n", $uuid, $_SERVER['REQUEST_METHOD'], $seq);


// connect to unix socket
$socket = stream_socket_client('unix://' . $socket_path, $errno, $errstr);
if (!$socket) {
    http_response_code(500);
    fprintf($log, "Failed to connect to socket: %s\n", $errstr);
    fflush($log);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // xhttp upload


    // send uuid to socket
    fwrite($socket, "POST");
    fwrite($socket, $uuid);
    fwrite($socket, str_pad($seq, 10, "0", STR_PAD_LEFT)); // pad to 10 digits
    fflush($socket);

    // forward entire body to socket
    $data = file_get_contents('php://input');
    hex_dump($data);
    fwrite($socket, $data);
    fflush($socket);

    
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // xhttp download

    header('Content-Type: application/octet-stream');
    header('X-Accel-Buffering: no');

    // send uuid to socket
    fwrite($socket, "GET_");
    fwrite($socket, $uuid);
    fflush($socket);

    // read from socket and output to response
    while (!feof($socket)) {
        $data = fread($socket, 4096);
        if (!$data) {
            break;
        }
        hex_dump($data);
        echo $data;
        flush();
    }

}