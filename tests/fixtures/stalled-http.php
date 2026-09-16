<?php

$server = stream_socket_server('tcp://127.0.0.1:0');
echo 'http://'.stream_socket_get_name($server, false)."\n";
flush();
$client = stream_socket_accept($server, 10);
if ($client !== false) {
    sleep(4);
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    fclose($client);
}
fclose($server);
