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



include('./phpqrcode/qrlib.php'); 

#Config: 
$SALT = "T0kenz_";
$LOGO = "/images/logo.jpg";
$URL = "http://127.0.0.1/";
$PATH_TO_IMAGES = '/usr/share/nginx/html/images/';
$host        = "host=127.0.0.1";
$port        = "port=5432";
$dbname      = "dbname=tokens";
$credentials = "user=web_user password=foobar123!";

session_start(); // start up your PHP session! 
header('Token: 3C575679');
ob_implicit_flush(1);
@ini_set('zlib.output_compression',0);
@ini_set('implicit_flush',1);
@ob_end_clean();
set_time_limit(0);

function RandomString()
{
	$characters = '0123456789ABCDEF';
	$randstring = '';
	for ($i = 0; $i < 8; $i++) {
		$randstring .= $characters[rand(0, 15)];
	}
	return $randstring;
}

$alerts = array();

$db = pg_connect( "$host $port $dbname $credentials"  );
if(!$db){
	$alert = new alert;
	$alert->type = 'danger';
	$alert->message = "Error : Unable to open database";
	$alert->fatal = 1;
	array_push($alerts, $alert);
}

if(!array_key_exists('action',$_REQUEST)){ $_REQUEST["action"] = 'null';}
if(!array_key_exists('loggedin',$_SESSION)) {$_SESSION['loggedin'] = 0;}
if($_SESSION['loggedin'] == 0 and $_REQUEST["action"] != 'login'){
	$alert = new alert;
	$alert->type = 'danger';
	$alert->message = "Sorry, You are not logged in.";
	$alert->fatal = 0;
	array_push($alerts, $alert);
	$_REQUEST['action'] = 'need_login';
}

$sets = array();

pg_prepare($db, "submit_log", 'INSERT INTO LOGS (TYPE,SOURCE,USER_IDENT,REQUEST,HEADERS) VALUES ($1,$2,$3,$4,$5)');
pg_prepare($db, "submit_log_anon", 'INSERT INTO LOGS (TYPE,SOURCE,REQUEST,HEADERS) VALUES ($1,$2,$3,$4)');
pg_prepare($db, "set_winner", "UPDATE USERS set winner = 't' where user_ident = $1");

switch ($_REQUEST["action"]) {
	case "logout":
		session_destroy();
		session_start(); // start up your PHP session! 
		$_SESSION['loggedin'] = 0;
		$alert = new alert;
		$alert->type = 'success';
		$alert->message = "Logout successful.";
		array_push($alerts, $alert);
		break;
	case "login": #Only action allowed when not logged in.
		$result = pg_query_params($db, "SELECT name,admin,(date_part('epoch', now()) - date_part('epoch',CODE_AT)),id FROM users WHERE user_ident = $1 and password = crypt($2,password)", array($_REQUEST["user"],$_REQUEST["password"]));
		if(!$result){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = pg_last_error($db);
			array_push($alerts, $alert);
		} else {
			if(pg_num_rows($result) == 1){
				$alert = new alert;
				$alert->type = 'success';
				$alert->message = "Login successful.";
				array_push($alerts, $alert);
				$row = pg_fetch_row($result);
				$_SESSION['user_ident'] = $_REQUEST["user"];
				$_SESSION['user_name'] = $row[0];
				$_SESSION['last_token'] = $row[2];
				$_SESSION['loggedin'] = 1;
				$_SESSION['sem_id'] = $row[3];
				if($row[1] == 't') {
					$_SESSION['admin'] = 1;
				} else {
					$_SESSION['admin'] = 0;
				}
				if(array_key_exists('token',$_REQUEST)){
					header('Location: '.$URL.'index.php?action=submit_token&token='.$_REQUEST['token'],true, 302);
					die();
				} else {
					$_REQUEST['action'] = 'tokens';
				}
			} else {
				$alert = new alert;
				$alert->type = 'danger';
				$alert->message = "Login failed.";
				array_push($alerts, $alert);
				$result = pg_execute($db, "submit_log",array("Login Failed",$_SERVER['REMOTE_ADDR'],$_REQUEST["user"], print_r($_SERVER, true),$_REQUEST["user"]));
				$_REQUEST['action'] = 'need_login';
			}
		}

		break;
	case "leader":
		$result = pg_query($db,'select u.name,(select count(code) from tickets where user_ident = u.user_ident) as count from users u order by count desc');
		$output = array();
		while($row = pg_fetch_assoc ( $result ) ) {
			$output{$row{'name'}} = $row{'count'};
		}
		break;
	case "tokens":
		break;
	case "submit_token":
	$alert = new alert;
	$alert->type = 'info';
	$alert->message = "Sorry, game is over. ";	
	$alert->fatal = 1;
	array_push($alerts, $alert);
		return;
		$semaphore = sem_get($_SESSION['sem_id'], 1, 0666, 1);
		sem_acquire($semaphore);
		$reset_timer = 1;
		$result = pg_query_params($db, "SELECT (date_part('epoch', now()) - date_part('epoch',CODE_AT)) FROM users WHERE user_ident = $1", array($_SESSION["user_ident"]));
		if(!$result){
			$alert_info = pg_last_error($db);
		} else {
			if(pg_num_rows($result) == 1){
				$row = pg_fetch_row($result);
				$_SESSION['last_token'] = $row[0];
			}
		}
		#Sleep until we are 15 seconds after last submit. 
		if ($_SESSION['last_token'] < 15) {
			sleep (15 - $_SESSION['last_token']);
			$alert = new alert;
			$alert->type = 'info';
			$alert->message = "Sleeping ".(16 - $_SESSION['last_token'])." before trying token";	
			array_push($alerts, $alert);
		}
		$hash = hash('sha256',$SALT.strtoupper($_REQUEST['token']));
		#Check if we have redeemed this token already. 
		$result = pg_query_params($db, 'SELECT id FROM tickets 
				WHERE user_ident = $1 
				and code = $2', array($_SESSION["user_ident"],$hash));
		if( pg_num_rows($result) > 0) {
			$alert = new alert;
			$alert->type = 'info';
			$alert->message = "You have already redeemed this token.";
			array_push($alerts, $alert);
		} else {
			#Check if token exists in DB. 
			$result = pg_query_params($db, 'SELECT DESCRIPTION,QTY,MULTI,DEC,EVENT 					FROM tokens where code = $1 and (MULTI > 0 or MULTI IS NULL)', array($hash));
			if( pg_num_rows($result) == 1) {
				$row = pg_fetch_row($result);
				$description = $row[0];
				$value = $row[1];
				$multi = $row[2];
				$deci = $row[3];
				$event = $row[4];
				#Check if the token is still eligible to redeem.
				if(!$multi or $multi > 0){
					#Convert to tickets. 
					if($event){
						#Get team of current user for this event. 
						$uresult = pg_query_params($db, 'select user_ident from user_teams where team_ident in (
select ut.team_ident from user_teams ut,teams t where ut.user_ident = $1 and ut.team_ident = t.team_ident and t.event_ident = $2)', array($_SESSION["user_ident"],$event));
						$i = pg_num_rows($uresult);
						if($i){
							while($i){
								$urow = pg_fetch_row($uresult);
								get_tickets($db,$urow[0],$hash,$value);
								$i--;
							}
							decrement_ticket_counter($db,$hash,$deci,$multi);
						} else {
							$alert = new alert;
							$alert->type = 'warning';
							$alert->message = "Team not assigned.";
							array_push($alerts, $alert);
							$reset_timer = 0;
						}
					} else {
						get_tickets($db,$_SESSION["user_ident"],$hash,$value);
						decrement_ticket_counter($db,$hash,$deci,$multi);
					}
					$alert = new alert;
					$alert->type = 'success';
					$alert->message = "Code is valid.";
					array_push($alerts, $alert);
					$reset_timer = 0;
				} else {
					$alert = new alert;
					$alert->type = 'warning';
					$alert->message = "Code has already been claimed.";
					array_push($alerts, $alert);
				}
			} else {
				$alert = new alert;
				$alert->type = 'warning';
				$alert->message = "Sorry, This code is invalid.";
				array_push($alerts, $alert);
				$result = pg_execute($db, "submit_log",array("Invalid Token",$_SERVER['REMOTE_ADDR'],$_SESSION["user_ident"], print_r($_SERVER, true),$_REQUEST['token']));
			}
		}
		#Switch to token display as part of results. 
		$_REQUEST['action'] = 'tokens';
		if($reset_timer == 1){
		#set new last_submit time. 
			$result = pg_prepare($db, "update_code_at", 'UPDATE users SET code_at = now() where user_ident = $1');
			$result = pg_execute($db, "update_code_at",array($_SESSION["user_ident"]));
		}
		sem_release($semaphore);
		break;
	case "raffle":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "gen_token":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "change_password":
		pg_prepare($db, "change_pass", "UPDATE USERS SET password = crypt($3,gen_salt('md5')) where user_ident = $1 and password = crypt($2,password)");
		if($_REQUEST["new_password"] == $_REQUEST["new_password_confirm"]){
			$result = pg_query_params($db, "SELECT name,admin,(date_part('epoch', now()) - date_part('epoch',CODE_AT)),id FROM users WHERE user_ident = $1 and password = crypt($2,password)", array($_SESSION["user_ident"],$_REQUEST["password"]));
			if(!$result){
				$alert = new alert;
				$alert->type = 'danger';
				$alert->message = "Error Querying DB";
				$alert->fatal = 1;
				array_push($alerts, $alert);
			} else {
				if(pg_num_rows($result) == 1){
					pg_execute($db,"change_pass",array($_SESSION["user_ident"],$_REQUEST["password"],$_REQUEST["new_password_confirm"]));
					$alert = new alert;
					$alert->type = 'success';
					$alert->message = "Password updated. ";
					array_push($alerts, $alert);
				} else {
					$alert = new alert;
					$alert->type = 'danger';
					$alert->message = "Password invalid. ";
					array_push($alerts, $alert);
				}
			}
		} else {
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "New passwords do not match. ";
			array_push($alerts, $alert);
		}
		break;
	case "about":
		break;
	case "password":
		break;
	case "token_list":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "manage_sets":
		$result = pg_query_params($db, "select tag from tokens where tag NOTNULL group by tag");
		if(!$result){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Error Querying DB";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		} else {
			if(pg_num_rows($result) > 0){
				$row = pg_fetch_row($result);
				array($sets, $row[0]);
			}
		}
		break;
	case "manage_users":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "manage_events":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "create_event":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		pg_prepare($db, "create_event", "INSERT INTO EVENTS (EVENT_IDENT) VALUES ($1)");
		pg_execute($db,"create_event",array($_REQUEST["name"]));
		$_REQUEST["action"] = "manage_events";
		break;
	case "create_team":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		pg_prepare($db, "create_team", "INSERT INTO TEAMS (EVENT_IDENT,TEAM_IDENT) VALUES ($1,$2)");
		pg_execute($db,"create_team",array($_REQUEST['event'],$_REQUEST["name"]));
		$_REQUEST["action"] = "edit_event"; 
		break;		
	case "edit_event":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "create_token":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "view_user":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		break;
	case "assign_team":
		if(!$_SESSION['admin']){
			$alert = new alert;
			$alert->type = 'danger';
			$alert->message = "Sorry, You are not an admin.";
			$alert->fatal = 1;
			array_push($alerts, $alert);
		}
		if ($_REQUEST['team'] != "NULL"){
			pg_prepare($db, "assign_team", "INSERT INTO USER_TEAMS
			 (TEAM_IDENT,USER_IDENT) VALUES ($1,$2)");
			pg_prepare($db, "delete_team", "DELETE FROM user_teams
				WHERE TEAM_IDENT = $1 and USER_IDENT = $2");
		
			if($_REQUEST['delete']){
				pg_execute($db,"delete_team",array($_REQUEST['delete'],$_REQUEST["user"]));
			}
			pg_execute($db,"assign_team",array($_REQUEST['team'],$_REQUEST["user"]));
		}
		$_REQUEST["action"] = "edit_event"; 
		break;
	case 'need_login':
		break;
	case 'null': # No action needs no alarm.
		break;
	case 'view_event':
		break;
	default:
	if(array_key_exists('user_ident',$_REQUEST)){
		pg_execute($db, "submit_log",array("Unknown Action",$_SERVER['REMOTE_ADDR'],$_SESSION["user_ident"], print_r($_SERVER, true),$_REQUEST["action"]));
	} else {
		pg_execute($db, "submit_log_anon",array("Unknown Action",$_SERVER['REMOTE_ADDR'], print_r($_SERVER, true),$_REQUEST["action"]));
	}
	$alert = new alert;
	$alert->type = 'danger';
	$alert->message = "Action not valid.";
	$alert->fatal = 1;
	array_push($alerts, $alert);
}
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>T0ken Portal</title>
		<!-- Bootstrap -->
		<link href="css/bootstrap.min.css" rel="stylesheet">
		<!-- 239618CC -->
		<script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
		<script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
	</head>
	<body>
		<div class="navbar navbar-default navbar-fixed-top" role="navigation">
			<div class="container">
				<div class="navbar-header">
					<button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
					<span class="sr-only">Toggle navigation</span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
				</button>
					<a class="navbar-brand" href="/">Tokens</a>
				</div>
				<div class="navbar-collapse collapse">
					<ul class="nav navbar-nav">
<?php
	if(isset($_SESSION['loggedin']) and $_SESSION['loggedin'] == 1){
?>
	
	<li class="toggle"><a href="?action=tokens">My Tokens</a></li>
	<li class="toggle"><a href="?action=leader">Leader Board</a></li>
	<li class="toggle"><a href="?action=about">About</a></li>
	<?php if($_SESSION['admin']){ ?>
		<li class="dropdown">
			<a href="#" class="dropdown-toggle" data-toggle="dropdown">Admin <b class="caret"></b></a>
			<ul class="dropdown-menu">
				<li><a href="?action=raffle">Raffle</a></li>
				<li><a href="?action=gen_token">Generate Token</a></li>
				<li><a href="?action=token_list">List all Tokens</a></li>
				<li><a href="?action=manage_sets">Manage sets</a></li>
				<li><a href="?action=password">Change Password</a></li>
				<li><a href="?action=manage_events">Manage Events</a></li>
				<li><a href="?action=manage_users">Manage Users</a></li>
			</ul>
		</li>
	<?php } else { ?>
		<li class="toggle"><a href="?action=password">Change Password</a></li>
	<?php } ?>
	<li class="toggle"><a href="?action=logout">Logout</a></li>
	<li class="toggle">
		<form class="navbar-form navbar-right" role="form" method=POST>
			<div class="form-group">
				<input type="hidden" name="action" value="submit_token">
				<input type="text" placeholder="Token" name=token class="form-control">
			</div>
			<button type="submit" class="btn btn-success">Submit</button>
		</form>
	</li>
<?php } ?>
	</ul>
				</div><!--/.nav-collapse -->
			</div>
		</div>
<div class="container">
	<br><br><br>
	<?php 
		foreach ($alerts as $alert) { 
				$alert->display($db);
		}
	?>
	<?php
	switch ($_REQUEST["action"]) {
		case "about": ?> 
		<div class="jumbotron">
			Blah Blah Blah what is this all about.
			<!-- 883F6059 -->
		</div>
			<?php
			break;
		case "token_list":
			print "<TABLE class=\"table\">";
			print "<TR><TH>Token:</TH><TH>Count</TH></TR>";
			$result = pg_query($db, "select tokens.description,tokens.code,count(tickets.user_ident) from tokens,tickets where tokens.code = tickets.code group by tokens.description,tokens.code order by count desc,tokens.description");
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					print "<TR><TD>";
					print $row[0]."</A></TD><TD>".$row[2]."</TD></TR>";
					$result2 = pg_query_params($db,"select users.name,count(users.name) as count from users, tickets where users.user_ident = tickets.user_ident and tickets.code = $1 group by users.name;",array($row[1]));
					print "<TR><TD></TD><TD></TD><TD><TABLE class=\"table\">";
					$j = pg_num_rows($result2);
					while($j){
						$row = pg_fetch_row($result2);
						print "<TR><TD>".$row[0]."</TD><TD>".$row[1]."</TD></TR>";
						$j--;
					}
					print "</table></TD></TR>";
					$i--;
				}
			}
			
			print "</table>";
		case "leader":
			print "<TABLE class=\"table\">";
			print "<!-- 921CE6E9 -->";
			print "<TR><TH>User</TH><TH></TH><TH>Tickets</TH>\n";
			foreach ($output as $key => $value ) {
				print "<TR><TD>$key</TD><TD>&nbsp;</TD><TD>$value</TD></TR>";
			}
			print "</TABLE>";
			print "<H2>Events</H2>";
			print "<TABLE class=\"table\">";
			$result = pg_query($db, "select event_ident from events order by event_ident");
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					print "<TR><TD>".$row[0]."</TD><TD><A HREF=/?action=view_event&event=".$row[0].">View Event</A></TD></TR>\n";
					$i--;
				}
			}
			print "</TABLE>";
			break;
		case "tokens":
			print "<TABLE class=\"table\">";
			print "<TR><TH>Token</TH><TH></TH><TH>Count</TH>\n";
			$output = load_tokens($db,$_SESSION['user_ident']);
			foreach ($output as $key => $value ) {
				print "<TR><TD>$key</TD><TD>&nbsp;</TD><TD>$value</TD></TR>";
			}
			print "</TABLE>";
			print "<!-- F3C5C56F -->";
			break;
		case "need_login":
			?><div>
			<form role="form" method=POST>
				<div class="form-group">
					<input type=hidden name=action value=login>
					<?php
					if (array_key_exists('token',$_REQUEST)){
						print "<input type=hidden name=token value=".$_REQUEST['token'].">";
					}
					?>
					<input type="text" placeholder="user" name=user class="form-control">
					<input type="password" placeholder="Password" name=password class="form-control">
				<button type="submit" class="btn btn-success">Sign in</button>
				</div>
			</form>
		</div>
			<?php
			break;

		case "password":
		?><div>
			<form role="form" method=POST>
				<input type=hidden name=action value=change_password>
				Old Password: <input type=text name=password><br>
				New Password: <input type=text name=new_password><br>
				Confirm Password: <input type=text name=new_password_confirm><br>
				<input type=submit>
			</form>
		</div>
		<?php
			break;
		case "raffle":
			#Set winners here, if you want to recuse someone from the raffle.
			$winner_3	= draw_winner($db);
			$winner_2	= draw_winner($db);
			$winner_1	= draw_winner($db);
			
			$winner1 = pg_query($db,"select u.user_ident,u.name from tickets t, users u where t.user_ident = u.user_ident and u.winner = 'f' order by random() limit 1");

			?>
		<ul class="list-group">
		  <li class="list-group-item">Starting Drawing procedure:</li>
			<li class="list-group-item">Managers being remove from Draw:</li>
			<li class="list-group-item"><ul class="list-group">
				<li class="list-group-item">Ben April</li>
				<li class="list-group-item">Bob McArdle</li>
				<li class="list-group-item">Ryan Flores</li>
				<li class="list-group-item">Martin Roesler</li>
				<li class="list-group-item">Simon Ko</li>
				<li class="list-group-item">Pawan Kinger</li>
			</ul></li>
			<li class="list-group-item">Running Draw Query.</li>
			<li class="list-group-item">
				<div id=winner1 style="visibility:hidden">
					<H1><?php echo $winner_1 ?></H1> 
				</div>
			</li>
			<li class="list-group-item">
				<div id=winner2 style="visibility:hidden">
					<H1><?php echo $winner_2 ?></H1> 
				</div>
			</li>
			<li class="list-group-item">
				<div id=winner3 style="visibility:hidden">
					<H1><?php echo $winner_3 ?></H1> 
				</div>
			</li>
			<li class="list-group-item">
				<div id="button3_div">
					<input type="button" id="button3" value="Show 3rd Prize Winner"
					onclick="document.getElementById('winner3').style.visibility = 'visible';
    document.getElementById('button2_div').style.visibility = 'visible';">
				</div>
				<div id="button2_div" style="visibility:hidden">
					<input type="button" id="button2"  value="Show 2nd Prize Winner"
					onclick="document.getElementById('winner2').style.visibility = 'visible';
    document.getElementById('button1_div').style.visibility = 'visible';">
				</div>
				<div id="button1_div" style="visibility:hidden">
					<input type="button" id="button1" value="Show 1st Prize Winner" onclick="document.getElementById('winner1').style.visibility = 'visible';">
				</div>
			</li>
		</ul>
		<?php
			break;
		case "gen_token":
		$event_list = array();
		$result = pg_query($db, "select event_ident from events order by event_ident");
		if($result){
			$i = pg_num_rows($result);
			while($i) {
				$row = pg_fetch_row($result);
				array_push($event_list,$row[0]);
				$i--;
			}
		}
		?> 
<div class="form-group">
	<form role="form" method=POST>
		<input type=hidden name=action value=create_token>
		<table class="table">
		<tr><th colspan=3><H2>Create new token(s)</H2></TH></TR>
		<tr>
			<TH>Description:</th>
			<td><input type=text name=description></td>
			<td>&nbsp;</td>
		</tr>
		<tr>
			<th>Code:</th>
			<td><input type=text name=code></td>
			<td> <i>Leave blank to auto-generate</i></td>
		</tr>
		<tr><th>Count:</th>
			<td><select name=value>
				<option>1
				<option>2
				<option>3
				<option>4
				<option>5
				<option>6
				<option>7
				<option>8
				<option>9
				<option>10
				<option>21
				<option>22
				<option>23
				<option>24
				<option>25
				<option>26
				<option>27
				<option>28
				<option>29
				<option>20
				<option>31
				<option>32
				<option>33
				<option>34
				<option>35
				<option>36
				<option>37
				<option>38
				<option>39
				<option>30
		 </select></td>
		 <td><i>How many tickets will be granted</i></td>
	 </tr>
	 <tr>
		 <th>Multi:</th>
		 <td><select name=multi>
			<option value=NULL>-- One per user --
			<option>1
			<option>2
			<option>3
			<option>4
			<option>5
			<option>6
			</select></td>
			<td><i>How many users may redeem this token<i></td>
		</tr>
		<tr>
			<th>Qty:</th>
			<td><select name=qty>
			<option>1
			<option>2
			<option>3
			<option>4
			<option>5
			<option>6
			<option>12
			<option>25
			</select></td>
			<td><i>How many tokens to create<i></td>
		</tr>
		<tr>
			<th>Decrement value per issue:</th>
			<td> <select name=decrement>
			<option>0
			<option>1
			<option>2
			<option>3
			<option>4
			<option>5
			<option>6
			<option>7
			<option>8
			<option>9
			<option>10
			</select></td>
			<td><i>How much to decrease the value every time the token is awarded</i></td>
		</tr>
		<tr>
			<th>Team Event:</th>
			<td><SELECT NAME=event>
				<option value=NULL> -- No Event
		<?php
		foreach ($event_list as $event){
			print "<option> $event\n";
		}
		?>
				</select></td>
			<td><i>If submitted by one member, all members of that team get the same reward.</i></td>
		</tr>
		<tr>
			<th>Tag:</th>
			<td><input type=text name=tag></td>
			<td><i>Tag-set to link this token with.</i></td>
		</tr>
		<tr><td colspan=3><input type=submit></td></tr>
	</table>
	</form>
</div>
		<?php
			break;
		case 'view_event':
			?>
			<meta http-equiv="refresh" content="30">
			<H2>Event: <?php $_REQUEST['event']?></H2>
			<table class="table table-condensed">
			<TR><TH>Team</TH><TH>Members     Token    Score</TH></TR>
			<?php
			$team_list = array();
			$result = pg_query_params($db, "select team_ident from teams where event_ident = $1 order by team_ident",array($_REQUEST['event']));
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					array_push($team_list,$row[0]);
					$i--;
				}
			}
			$result = pg_query_params($db, "select team_ident from teams where event_ident = $1 order by team_ident",array($_REQUEST['event']));
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					print "<TR><TD>".$row[0]."</TD><TD>";
						$r1 = pg_query_params($db, "select ut.user_ident,u.name from user_teams ut, users u where ut.team_ident = $1 and ut.user_ident = u.user_ident order by user_ident",array($row[0]));
						if($r1){
							print "<table class=\"table table-condensed\">";
							$j = pg_num_rows($r1);
							$first = 1;
							$event_total = 0;
							$l = $j;
							while($l > 0) {
								if($j){
									$row1 = pg_fetch_row($r1);
								}
								if($first == 1){
									$result2 = pg_query_params($db, "SELECT tokens.description,count(tickets.code) 
FROM tokens, tickets        
WHERE tickets.user_ident = $1
AND tokens.event = $2
AND tickets.code = tokens.code group by tokens.description;
",array($row1[0],$_REQUEST['event']));
									$k = pg_num_rows($result2);
									$l += $k;
									$first = 0;
								}
								if($j){
									print "<TR><TD>".$row1[1]."</TD>";
									$j--;
									$l--;
								} else if ($k){
									print "<TR><TD></TD>";
								}
								if($k) {
									$row2 = pg_fetch_row($result2);
									print "<TD>".$row2[0]."</TD><TD>".$row2[1]."</TD>";
									$event_total += $row2[1];
									$k--;
									$l--;
								}
								print "</TD></TR>";
								
							}
							print "<TR><TD></TD><TD></TD><TH>Total:</TH><TD>".$event_total."</TD></TR>";
							print "</table>";
						}
						print "</TD></TR>";
						$i--;
					}
				}
			break;
		case "manage_events":
			?>
			<table class="table">
				<TR><TH>Event</TH><TH>Edit</TH></TR>
			<?php
			
			$result = pg_query($db, "select event_ident from events order by event_ident");
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					print "<TR><TD>".$row[0]."</TD><TD><A HREF=/?action=edit_event&event=".$row[0].">Edit Event</A></TD></TR>\n";
					$i--;
				}
			}
			?></TITLE>
			<div>
				<form method=POST>
					<input type=hidden name=action value=create_event>
					Name: <input type=text name=name><br>
					<input type=submit>
				</form>
			</div>
			<?php
			break;
		case "manage_users":
				?>
				<table class="table">
					<TR><TH>User ID</TH><TH>Name</TH><TH>Winner</TH><TH>Edit</TH></TR>
				<?php
			
				$result = pg_query($db, "select user_ident,name,winner from users order by user_ident");
				if($result){
					$i = pg_num_rows($result);
					while($i) {
						$row = pg_fetch_row($result);
						print "<TR><TD>".$row[0]."</TD><TD>".$row[1]."</TD>";
						print "<TD>".$row[2]."</TD>";
						print "<TD><A HREF=/?action=view_user&user=".$row[0].">Edit User</A></TD></TR>\n";
						$i--;
					}
				}
				?></TABLE>
				<div>
					<form method=POST>
						<input type=hidden name=action value=create_event>
						Name: <input type=text name=name><br>
						<input type=submit>
					</form>
				</div>
				<?php
				break;
		case "edit_event":
			#List team.
			print "<table class=\"table\">";
			$team_list = array();
			$result = pg_query_params($db, "select team_ident from teams where event_ident = $1 order by team_ident",array($_REQUEST['event']));
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					array_push($team_list,$row[0]);
					$i--;
				}
			}
			$result = pg_query_params($db, "select team_ident from teams where event_ident = $1 order by team_ident",array($_REQUEST['event']));
			if($result){
				$i = pg_num_rows($result);
				while($i) {
					$row = pg_fetch_row($result);
					print "<TR><TD>".$row[0]."</TD><TD>";
						$r1 = pg_query_params($db, "select ut.user_ident,u.name from user_teams ut, users u where ut.team_ident = $1 and ut.user_ident = u.user_ident order by user_ident",array($row[0]));
						if($r1){
							print "<table class=\"table\">";
							$j = pg_num_rows($r1);
							while($j > 0) {
								$row1 = pg_fetch_row($r1);
								print "<TR><TD>".$row1[1]."</TD><TD>";
								print "<form action='/' METHOD=POST>";
								print "<INPUT TYPE=HIDDEN NAME=action VALUE=assign_team>";
								print "<INPUT TYPE=HIDDEN NAME=delete VALUE=".$row[0].">";
								print "<INPUT TYPE=HIDDEN NAME=event VALUE=".$_REQUEST['event'].">";
								print "<INPUT TYPE=HIDDEN NAME=user value=".$row1[0].">";
								print "<SELECT NAME=team>";
								print "<option value=NULL> -- Select Team";
								foreach ($team_list as $team){
									print "<option> $team\n";
								}
								print "</select>";
								print "<input type=submit>";
								print "</form>";
								$j--;
							}
							print "</table>";
						}
					print "</TD></TR>";
					$i--;
				}
			}
			print "<TR><TD>Not Assigned</TD><TD>";
			$r1 = pg_query_params($db, "SELECT user_ident,name from users where user_ident not in (
SELECT ut.user_ident from user_teams ut, teams t 
where t.team_ident = ut.team_ident and 
t.event_ident = $1) order by user_ident",array($_REQUEST['event']));
			if($r1){
				print "<table class=\"table\">";
				$j = pg_num_rows($r1);
				while($j > 0) {
					$row = pg_fetch_row($r1);
					print "<TR><TD>".$row[1]."</TD><TD>";
					print "<form action='/' METHOD=POST>";
					print "<INPUT TYPE=HIDDEN NAME=action VALUE=assign_team>";
					print "<INPUT TYPE=HIDDEN NAME=event VALUE=".$_REQUEST['event'].">";
					print "<INPUT TYPE=HIDDEN NAME=user value=".$row[0].">";
					print "<SELECT NAME=team>";
					print "<option value=NULL> -- Select Team";
					foreach ($team_list as $team){
						print "<option> $team\n";
					}
					print "</select>";
					print "<input type=submit>";
					print "</form>";
					$j--;
				}
				print "</table>";
			}
/*
SELECT user_ident from users where user_ident not in (
SELECT ut.user_ident from user_teams ut, teams t 
where t.team_ident = ut.team_ident and 
t.event_ident = 'CTF');

*/

			print "</TABLE>";

			#List users not connected to a team.  
			?></TABLE>
			<div>
				<form method=POST action="/">
					<input type=hidden name=action value=create_team>
					<input type=hidden name=event value=<?php echo $_REQUEST['event']?>>
					Team Name: <input type=text name=name><br>
					<input type=submit>
				</form>
			</div>
			<?php
			break;
		case "create_token":
			if(!$_SESSION['admin']){
				$alert = new alert;
				$alert->type = 'danger';
				$alert->message = "Sorry, You are not an admin.";
				$alert->fatal = 1;
				array_push($alerts, $alert);
			}
			$code = $_REQUEST['code'];
			$qty = $_REQUEST['qty'];
			$has_qty = $qty;
			if(strlen($code) > 1) { #Don't do miltiple tokens with the same code. 
				$qty = 1;
				$has_qty = 0;
			}
			$result = pg_prepare($db, "create_token", 'INSERT INTO TOKENS (CODE,DESCRIPTION,QTY,MULTI,TAG,DEC,EVENT) VALUES ($1,$2,$3,$4,$5,$6,$7)');
			while($qty){
				$descr = $_REQUEST['description'];
				$code = $_REQUEST['code'];
				$value = $_REQUEST['value'];
				$multi = $_REQUEST['multi'];
				if ($multi == "NULL"){
					unset($multi);
				}
				$tag = $_REQUEST['tag'];
				$dec = $_REQUEST['decrement'];
				$event = $_REQUEST['event'];
				if($event == "NULL"){ $event = NULL; }

				if(strlen($code) < 1) {
					$code = RandomString();
				}
				$hash = hash('sha256',$SALT.strtoupper($code));
				
				if($has_qty > 1) {
					$description = $descr."-$qty";
				} else {
					$description = $descr;
				}
				
				$result = pg_execute($db, "create_token",array($hash,$description,$value,$multi,$tag,$dec,$event));
				$qty--;
				$QRURL = $URL."index.php?action=submit_token&token=".$code;
				QRcode::png($QRURL,$PATH_TO_IMAGES.'qr_code-'.$qty.'.png');
		?>
<div class="col-md-4">
	<div class="well well-sm">
<table>
	<TR><TD COLSPAN=3 BGCOLOR=BLACK>&nbsp;</TD></TR>
		<TD ROWSPAN=3 BGCOLOR=BLACK>
			<IMAGE SRC=<?php echo $LOGO ?>>
		</TD>
		<TD BGCOLOR=BLACK>
			<IMAGE SRC=/images/qr_code-<?php echo $qty ?>.png>
		</TD>
		<TD ROWSPAN=3 BGCOLOR=BLACK>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</TD>
	<TR>
		 <TD BGCOLOR=BLACK>&nbsp;</TD>
	</TR>
	<TR>
		<TH BGCOLOR=BLACK>
			<FONT COLOR=WHITE>
			<B><?php echo $code?></B>
		</FONT>
		</TH>
	</TR>
	<TR><TD COLSPAN=3 BGCOLOR=BLACK>&nbsp;</TD></TR>
</TABLE>
 </div>
</div>
<?php
}
			break;
		default:
			#phpinfo();
	}
		pg_close($db);
	?>
</div>
	<!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
	<!-- Include all compiled plugins (below), or include individual files as needed -->
	<script src="js/bootstrap.min.js"></script>
	</body>
</html>

<?php

function get_tickets($db,$user_ident,$hash,$value) {
	$result = pg_prepare($db, "save_ticket", 'INSERT INTO tickets 		(user_ident,created_at,code) values ($1,now(),$2)');
	for ($i = 1; $i <= $value; $i++) {
		$result = pg_execute($db, "save_ticket",
			array($user_ident,$hash));
	}
}

function decrement_ticket_counter($db,$code,$deci,$multi){
	$multi_sql = "";
	if($multi){
		$multi_sql = ", multi = multi - 1";
	}
	if ($deci > 0){
		pg_prepare($db, 
			"decrement_ticket", 
			'UPDATE TOKENS SET QTY = QTY - $1'.$multi_sql.' WHERE code = $2');
			pg_execute($db, "decrement_ticket", array($deci,$code));
	} else if ($multi){
		pg_prepare($db, 
			"decrement_ticket", 
			'UPDATE TOKENS SET multi = multi - 1 WHERE code = $1');
			pg_execute($db, "decrement_ticket", array($code));
	}
}

function set_winner($db,$ident) {
	pg_execute($db,"set_winner",array($ident));
}

function draw_winner($db) {
	$result = pg_query($db,"select u.user_ident,u.name from tickets t, users u where t.user_ident = u.user_ident and u.winner = 'f' order by random() limit 1");
	if(pg_num_rows($result) == 1){
		$row = pg_fetch_row($result);
		pg_execute($db,"set_winner",array($row[0]));
		return($row[1]);
	} else {
		return("Error");
	}	
}

function load_tokens($db,$user_ident) {
		$result = pg_query_params($db,'select tokens.description,count(tokens.description) as count from tokens,tickets where tokens.code = tickets.code and tickets.user_ident = $1 group by tokens.description',array($user_ident));
		$output = array();
		while($row = pg_fetch_assoc ( $result ) ) {
			$output{$row{'description'}} = $row{'count'};
		}
		return $output;
}

class alert
{
		public $type = 'info';
		public $message = "";
		public $fatal = 0;
		function display($db)
		{
			$write_log = 0;
			switch($this->type) {
				case 'danger':
					print "<div class='alert alert-danger'>$this->message</div>";
					$write_log = 1;
					break;
				case 'warning':
					print "<div class='alert alert-warning'>$this->message</div>";
					$write_log = 1;
					break;
				case 'info':
					print "<div class='alert alert-info'>$this->message</div>";
					$write_log = 1;
					break;
				case 'success':
					print "<div class='alert alert-success'>$this->message</div>";
					break;
				default:
					?> <div class="alert alert-danger">Unknown Alert Type <?php echo $this->type ?></div> <?php
			}
			if ($write_log) {
				if($_REQUEST["user"]) {
					$result = pg_execute($db, "submit_log",array(" Alert message",$_SERVER['REMOTE_ADDR'],$_REQUEST["user"], print_r($_SERVER, true), $this->message));
				} else {
					$result = pg_execute($db, "submit_log_anon",array(" Alert message",$_SERVER['REMOTE_ADDR'], print_r($_SERVER, true),$this->message,));
				}
			}
			if ($this->fatal) { exit; }
		}
}
?>
