<?php

function ob_callback($buffer) {
    // $log = fopen(__DIR__ . "/worker.log", "a");
    // fprintf($log, "Output: %s\n", $buffer);
    // fclose($log);
    // return $buffer;
    return "";
}
ob_start('ob_callback');
ob_implicit_flush(true);

header('X-Accel-Buffering: no');

function hex_dump($data, $newline="\n")
{
    return; // disable hex dump

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
        printf("%s", sprintf('%6X',$offset). ' : ' .implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . $newline);

        $offset += $width;
    }
}

$start_time = time();
$worker_timeout = 58;
echo "worker started\n";
echo $start_time . "\n";

// start unix socket server
$socket_path = __DIR__ . "/xhttp.sock";

if (file_exists($socket_path)) exit;

$socket = stream_socket_server('unix://' . $socket_path, $errno, $errstr);
if (!$socket) {
    fprintf($log, "Failed to create socket: %s\n", $errstr);
    exit;
}

function cleanup() {
    global $socket_path;
    if (file_exists($socket_path)) {
        unlink($socket_path);
    }
    ob_end_flush();
}
register_shutdown_function('cleanup');

stream_set_blocking($socket, 0);

const VLESS_STATE_HANDSHAKE = 0;
const VLESS_STATE_DIAL_REMOTE = 1;
const VLESS_STATE_COPYING = 2;

class VlessSession {
    public $up_buffer = "";
    public $up_buffer_seq = 0; // points to the next sequence number expected from client uplink
    public $up_buffer_last_copy = 0; // timestamp of last copy to remote. default to time of session creation
    public $up_buffers = array(); // seq => buffer waiting to be sent to remote
    public $up_buffers_complete = array(); // seq => boolean. whether the entire seq has been received
    public $down_buffer = ""; // ready to be written to vless client
    public $state = VLESS_STATE_HANDSHAKE;
    public $remote_socket = null;
    public $client_down_socket = null; // downlink to vless client
    public $client_up_sockets = array(); // uplink from vless client. seq => socket

    public function __construct() {
        $this->up_buffer_last_copy = time();
    }
}

$sessions = array(); // uuid => VlessSession

function read_full($socket, $length) {
    $buf = "";
    while (strlen($buf) < $length) {
        $fread = fread($socket, $length - strlen($buf));
        if ($fread === false) break;
        $buf .= $fread;
    }
    if (strlen($buf) != $length) {
        return false;
    }
    return $buf;
}

function delete_session($uuid) {
    global $sessions;
    echo "delete session " . $uuid . "\n";
    if (isset($sessions[$uuid])) {
        $session = $sessions[$uuid];
        // close session
        if ($session->client_down_socket !== null) {
            fclose($session->client_down_socket);
        }
        foreach ($session->client_up_sockets as $seq => $s) {
            fclose($s);
        }
        $session->client_up_sockets = array();
        if ($session->remote_socket !== null) {
            fclose($session->remote_socket);
        }
        unset($sessions[$uuid]);
    }
}

// main loop
while (true) {
    $read = array();
    $write = array();
    $except = array();

    array_push($read, $socket); // listen for accept
    foreach ($sessions as $uuid => $session) {
        if ($session->client_down_socket !== null) {
            array_push($write, $session->client_down_socket);
        }
        foreach ($session->client_up_sockets as $seq => $s) {
            array_push($read, $s);
        }
        if ($session->remote_socket !== null) {
            array_push($read, $session->remote_socket);
            array_push($write, $session->remote_socket);
        }
    }
    

    $ready = stream_select($read, $write, $except, 1);
    if ($ready === false) {
        printf("stream_select failed\n");
        break;
    }
    if ($ready === 0) {
        // timeout
        echo "working...\n";
        if (time() - $start_time > $worker_timeout) {
            printf("worker timeout\n");
            exit;
        }
        continue;
    }

    foreach ($read as $s) {

        // ready to accept
        if ($s === $socket) {

            echo "socket accepted\n";

            $accepted = stream_socket_accept($socket);
            stream_set_blocking($accepted, 1);

            $buf = read_full($accepted, 4 + 36); // method + uuid
            if ($buf === false) {
                fclose($accepted);
                continue;
            }

            $uuid = substr($buf, 4);

            // find vless session
            if (!isset($sessions[$uuid])) {
                echo "new session " . $uuid . "\n";
                $sessions[$uuid] = new VlessSession();
            }
            $session = $sessions[$uuid];


            if (str_starts_with($buf, "GET_")) {


                echo "GET " . $uuid . "\n";
                
                // attach client down socket to session
                stream_set_blocking($accepted, 0);
                $session->client_down_socket = $accepted;


            } else if (str_starts_with($buf, "POST")) {

                $seq = read_full($accepted, 10); // read 10-digit sequence number
                if ($seq === false) {
                    fclose($accepted);
                    continue;
                }
                $seq = intval($seq);

                echo "POST " . $uuid .  " seq=" . $seq . "\n";

                // attach client up socket to session
                stream_set_blocking($accepted, 0);
                $session->client_up_sockets[$seq] = $accepted;

            } else {
                fclose($accepted);
            }
            
        }

        // ready to read from uplink
        foreach ($sessions as $uuid => $session) {
            foreach ($session->client_up_sockets as $seq => $client_up_socket) {
                if ($s === $client_up_socket) {
                    echo "read from uplink " . $uuid . " seq=" . $seq . "\n";
                    $data = fread($client_up_socket, 4096);
                    if ($data === false || strlen($data) === 0) {
                        // EOF or error
                        fclose($client_up_socket);
                        unset($session->client_up_sockets[$seq]);
                        $session->up_buffers_complete[$seq] = true;
                        continue;
                    }
                    if (!isset($session->up_buffers[$seq])) {
                        $session->up_buffers[$seq] = "";
                        $session->up_buffers_complete[$seq] = false;
                    }
                    $session->up_buffers[$seq] .= $data;
                }
            }
        }

        // ready to read from remote
        foreach ($sessions as $uuid => $session) {
            if ($session->remote_socket !== null && $s === $session->remote_socket) {
                echo "read from remote " . $uuid . "\n";
                $data = fread($session->remote_socket, 4096);
                if ($data === false || strlen($data) === 0) {
                    // EOF or error
                    delete_session($uuid);
                    continue;
                }
                hex_dump($data);
                $session->down_buffer .= $data;
            }
        }

    }

    foreach ($write as $s) {
        
        // ready to write to downlink
        foreach ($sessions as $uuid => $session) {
            if ($session->client_down_socket !== null && $s === $session->client_down_socket) {
                if (strlen($session->down_buffer) > 0) {
                    echo "write to downlink " . $uuid . "\n";
                    hex_dump($session->down_buffer);
                    $written = fwrite($session->client_down_socket, $session->down_buffer);
                    if ($written === false) {
                        // error
                        fclose($session->client_down_socket);
                        $session->client_down_socket = null;
                        continue;
                    }
                    $session->down_buffer = substr($session->down_buffer, $written);
                }
            }
        }
        
        // ready to write to remote
        foreach ($sessions as $uuid => $session) {
            if ($session->remote_socket !== null && $s === $session->remote_socket) {
                if ($session->state === VLESS_STATE_DIAL_REMOTE) {
                    echo "connected to remote " . $uuid . "\n";
                    $session->state = VLESS_STATE_COPYING;
                }
                if ($session->state === VLESS_STATE_COPYING) {
                    if (strlen($session->up_buffer) > 0) {
                        echo "write to remote " . $uuid . "\n";
                        hex_dump($session->up_buffer);
                        $written = fwrite($session->remote_socket, $session->up_buffer);
                        if ($written === null) {
                            // error
                            delete_session($uuid);
                            continue;
                        }
                        $session->up_buffer = substr($session->up_buffer, $written);
                    }
                }
            }
        }
    }

    foreach ($sessions as $uuid => $session) {
        // copy ready uplink buffers to $up_buffer
        for ($seq = $session->up_buffer_seq; isset($session->up_buffers_complete[$seq]) && $session->up_buffers_complete[$seq]; $seq++) {
            echo "copy seq " . $seq . " to up_buffer " . $uuid . "\n";
            if (isset($session->up_buffers[$seq])) {
                hex_dump($session->up_buffers[$seq]);
                $session->up_buffer .= $session->up_buffers[$seq];
                unset($session->up_buffers[$seq]);
            }
            unset($session->up_buffers_complete[$seq]);
            $session->up_buffer_seq = $seq + 1;
            $session->up_buffer_last_copy = time();
            
        }
        // timeout
        if (time() - $session->up_buffer_last_copy > 30) {
            delete_session($uuid);
        }
    }

    // process vless

    foreach ($sessions as $uuid => $session) {
        if ($session->state === VLESS_STATE_HANDSHAKE) {

            if (strlen($session->up_buffer) < 22) {
                continue; // not enough data
            }

            $handshake_length = 0;
            
            $vless_version = unpack("C", substr($session->up_buffer, 0, 1))[1];
            $vless_uuid = substr($session->up_buffer, 1, 16);
            $vless_additional_data_length = unpack("C", substr($session->up_buffer, 17, 1))[1];
            if ($vless_version !== 0 || $vless_additional_data_length !== 0) {
                delete_session($uuid);
                continue;
            }
            $handshake_length += 18;

            $vless_command = unpack("C", substr($session->up_buffer, 18, 1))[1];
            $vless_port = unpack("n", substr($session->up_buffer, 19, 2))[1];
            $vless_addr_type = unpack("C", substr($session->up_buffer, 21, 1))[1];
            $handshake_length += 4;

            if ($vless_addr_type == 1) {
                // ipv4
                if (strlen($session->up_buffer) < 22 + 4) {
                    continue; // not enough data
                }
                $vless_addr = inet_ntop(substr($session->up_buffer, 22, 4));
                $handshake_length += 4;
            } else if ($vless_addr_type == 2) {
                // domain
                if (strlen($session->up_buffer) < 22 + 1) {
                    continue; // not enough data
                }
                $domain_length = unpack("C", substr($session->up_buffer, 22, 1))[1];
                if (strlen($session->up_buffer) < 22 + 1 + $domain_length) {
                    continue; // not enough data
                }
                $vless_addr = substr($session->up_buffer, 23, $domain_length);
                $handshake_length += 1 + $domain_length;
            } else if ($vless_addr_type == 3) {
                // ipv6
                if (strlen($session->up_buffer) < 22 + 16) {
                    continue; // not enough data
                }
                $vless_addr = inet_ntop(substr($session->up_buffer, 22, 16));
                $handshake_length += 16;
            } else {
                delete_session($uuid);
                continue;
            }

            echo "handshake " . $uuid . " command=" . $vless_command . " addr=" . $vless_addr . " port=" . $vless_port . " handshake_length=" . $handshake_length . "\n";

            $session->up_buffer = substr($session->up_buffer, $handshake_length);
            $session->state = VLESS_STATE_DIAL_REMOTE;
            $remote_socket = stream_socket_client("tcp://" . $vless_addr . ":" . $vless_port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if (!$remote_socket) {
                delete_session($uuid);
                continue;
            }
            stream_set_blocking($remote_socket, 0);
            $session->remote_socket = $remote_socket;

            // vless response
            $session->down_buffer .= "\x00\x00"; // version 1, no additional data
 
        }
    }

}