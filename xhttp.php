<?php

/*
Copyright (C) 2026 duoduo

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

require_once __DIR__ . "/common.php";

// $log = fopen(__DIR__ . "/xhttp.log", 'a');
$log = fopen('php://memory', 'w');


// unix socket
$socket_path = realpath(__DIR__ . "/xhttp.sock");
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
    hex_dump($data, $log);
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
        hex_dump($data, $log);
        echo $data;
        flush();
    }

}

fflush($log);