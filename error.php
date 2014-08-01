<?php
#   Copyright 2014 Benjamin April
#
#   Licensed under the Apache License, Version 2.0 (the "License");
#   you may not use this file except in compliance with the License.
#   You may obtain a copy of the License at
#
#       http://www.apache.org/licenses/LICENSE-2.0
#
#   Unless required by applicable law or agreed to in writing, software
#   distributed under the License is distributed on an "AS IS" BASIS,
#   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
#   See the License for the specific language governing permissions and
#   limitations under the License.
#


session_start(); // start up your PHP session! 
$host        = "host=127.0.0.1";
$port        = "port=5432";
$dbname      = "dbname=tokens";
$credentials = "user=web_user password=foobar123!";
$alerts = array();

$db = pg_connect( "$host $port $dbname $credentials"  );
if(!$db){
	$alert = new alert;
	$alert->type = 'danger';
	$alert->message = "Error : Unable to open database";
	$alert->fatal = 1;
	array_push($alerts, $alert);
}

#TODO Log attempt of file.
pg_prepare($db, "submit_log", 'INSERT INTO LOGS (TYPE,SOURCE,USER_IDENT,REQUEST,HEADERS) VALUES ($1,$2,$3,$4,$5)');

pg_prepare($db, "submit_log_anon", 'INSERT INTO LOGS (TYPE,SOURCE,REQUEST,HEADERS) VALUES ($1,$2,$3,$4)');

if(array_key_exists('user_ident',$_REQUEST)){
	pg_execute($db, "submit_log",array("404",$_SERVER['REMOTE_ADDR'],$_SESSION["user_ident"], print_r($_SERVER, true),$_SERVER["REQUEST_URI"]));
} else {
	pg_execute($db, "submit_log_anon",array("404",$_SERVER['REMOTE_ADDR'], print_r($_SERVER, true),$_SERVER["REQUEST_URI"]));
}
?><html>
<head><title>404 Not Found</title></head>
<body bgcolor="white">
<center><h1>404 Not Found</h1></center>
<hr><center>nginx/1.4.6 (Ubuntu)</center>
</body>
</html>
<!-- a padding to disable MSIE and Chrome friendly error page -->
<!-- a padding to disable MSIE and Chrome friendly error page -->
<!-- a padding to disable MSIE and Chrome friendly error page -->
<!-- a padding to disable MSIE and Chrome friendly error page -->
<!-- a padding to disable MSIE and Chrome friendly error page -->
<!-- a padding to disable MSIE and Chrome friendly e 13095D5B -->
