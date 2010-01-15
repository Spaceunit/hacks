#!/usr/bin/php
<?php
define("VERSION", 'simplehttpd v1.1');
# simple HTTP server

# (c) 2009 Mantas Mikulėnas <grawity@gmail.com>
# Released under WTFPL v2 <http://sam.zoy.org/wtfpl/>

# Requires:
# - sockets extension
# - for userdir support: posix extension

$USAGE = <<<EOF
Usage: simplehttpd [-ahv] [-d docroot] [-l address] [-p port]
EOF;

$HELP = <<<EOTFM
$USAGE

Options:
  -a                           List all files, including hidden, in directories
  -d path                      Specify docroot (default is ~/public_html)
  -h                           Display help message
  -l address                   Bind to specified local address
  -p port                      Listen on specified port
  -v                           Display version

\033[;37;41;1;5mNOT TO BE USED IN PRODUCTION ENVIRONMENTS\033[m

EOTFM;

# expand path starting with ~/ given value of ~
function expand_path($path, $homedir) {
	if ($path == "~") $path .= "/";

	if (substr($path, 0, 2) == "~/" && $homedir)
		$path = $homedir . substr($path, 1);

	return $path;
}

# expand path starting with ~/ according to environment
function expand_own_path($path) {
	$home = getenv("HOME");
	if (!$home)
		$home = get_user_homedir();
	if (!$home)
		return $path;
	return expand_path($path, $home);
}

# get docroot for given user (homedir + suffix)
function get_user_docroot($user) {
	global $userdir_suffix;

	if (function_exists("posix_getpwnam")) {
		$pw = posix_getpwnam($user);
		return $pw? "{$pw["dir"]}/{$userdir_suffix}" : false;
	}
	else {
		return false;
	}
}

# get docroot given request (userdir or global)
function get_docroot($fs_path) {
	global $docroot, $enable_userdirs;

	# if $enable_userdirs is off, /~foo/ will be taken literally
	if ($enable_userdirs and substr($fs_path, 0, 2) == "/~") {
		$user_fs_path = substr($fs_path, 2);
		$req_user = strtok($user_fs_path, "/");
		$user_fs_path = (string) strtok("");

		$user_dir = get_user_docroot($req_user);
		if (!$user_dir or !is_dir($user_dir)) {
			return "$docroot/$fs_path";
		}
		return "$user_dir/$user_fs_path";
	}
	else {
		# no userdir in request
		return "$docroot/$fs_path";
	}
}

# read line (ending with CR+LF) from socket
function socket_gets($socket, $maxlength = 1024) {
	# This time I'm really sure it works.
	$buf = "";
	$i = 0;
	$char = null;
	while ($i < $maxlength) {
		$char = socket_read($socket, 1, PHP_BINARY_READ);
		# remote closed connection
		if ($char === false) return $buf;
		# no more data
		if ($char == "") return $buf;

		$buf .= $char;

		# ignore all stray linefeeds
		#if ($i > 0 and $buf[$i-1] == "\x0D" and $buf[$i] == "\x0A")
		#	return substr($buf, 0, $i-1);

		# terminate on both LF and CR+LF
		if ($buf[$i] == "\x0A") {
			if ($i > 0 and $buf[$i-1] == "\x0D")
				return substr($buf, 0, $i-1);
			else
				return substr($buf, 0, $i);
		}

		$i++;
	}
	return $buf;
}

# print error message and die
function socket_die($message, $socket = false) {
	if (!empty($message))
		fwrite(STDERR, "$message: ");

	$errno = (is_resource($socket)? socket_last_error($socket) : socket_last_error());
	$errstr = socket_strerror($errno);
	fwrite(STDERR, "$errstr [$errno]\n");

	exit(1);
}

# follow symlinks to reach the actual target; basically a recursive readlink()
function follow_symlink($file) {
	$i = 0; while (is_link($file)) {
		if (++$i < 32)
			$target = readlink($file);
		else
			$target = false;
		if ($target === false) return $file;

		# relative link
		if ($target[0] != '/')
			$target = dirname($file) . "/" . $target;
		$file = $target;
	}
	return $file;
}

function load_mimetypes($path = "/etc/mime.types") {
	global $content_types;
	$fh = fopen($path, "r");
	if (!$fh) return false;
	while ($line = fgets($fh)) {
		$line = rtrim($line);
		if ($line == "" or $line[0] == " " or $line[0] == "#") continue;
		$line = preg_split("/\s+/", $line);
		$type = array_shift($line);
		foreach ($line as $ext) $content_types[$ext] = $type;
	}
	fclose($fh);
}

$status_messages = array(
	200 => "Okie dokie",

	301 => "Moved Permanently",

	400 => "Bad Request",
	401 => "Unauthorized",
	403 => "Forbidden",
	404 => "Not Found",
	405 => "Method Not Allowed",
	418 => "I'm a teapot",

	500 => "Internal error (something fucked up)",
	501 => "Not Implemented",
);

## Default configuration

define("LOG_REQUESTS", true);

$docroot = expand_own_path("~/public_html");
if (!is_dir($docroot))
	$docroot = ".";

$index_files = array( "index.html", "index.htm" );

$enable_userdirs = false;
$userdir_suffix = "public_html";

$hide_dotfiles = true;

$listen = "::";
$listen_port = 8001;

$log_date_format = "%a %b %_d %H:%M:%S %Y";

$fork = 3;

$content_types = array(
	"css" => "text/css",
	"gif" => "image/gif",
	"htm" => "text/html",
	"html" => "text/html",
	"jpeg" => "image/jpeg",
	"jpg" => "image/jpeg",
	"js" => "text/javascript",
	"m4a" => "audio/mp4",
	"m4v" => "video/mp4",
	"mp4" => "application/mp4",
	"oga" => "audio/ogg",
	"ogg" => "audio/ogg",
	"ogv" => "video/ogg",
	"ogm" => "application/ogg",
	"png" => "image/png",
	"tgz" => "application/x-tar",
);

$config_files = array( "/etc/simplehttpd.conf", "./simplehttpd.conf" );

## Command-line options

$opt = getopt("ac:d:hl:p:v", array("help"));

if (isset($opt["h"]) or isset($opt["help"]) or $opt === false) {
	fwrite(STDERR, $HELP);
	exit(2);
}

if (isset($opt["v"]))
	die(VERSION."\n");

if (isset($opt["a"]))
	$hide_dotfiles = false;

if (isset($opt["d"]))
	$docroot = $opt["d"];

if (isset($opt["l"]))
	$listen = $opt["l"];

if (isset($opt["p"]))
	$listen_port = (int) $opt["p"];

if (isset($opt["u"]))
	$enable_userdirs = true;

if (isset($opt["U"]))
	$userdir_suffix = $opt["U"];

## Prepare for actual work

$use_ipv6 = (strpos($listen, ":") !== false);

if (!@chdir($docroot)) {
	fwrite(STDERR, "Error: Cannot chdir to docroot $docroot\n");
	exit(1);
}

$docroot = getcwd();
$local_hostname = php_uname("n");

load_mimetypes();
load_mimetypes(expand_own_path("~/.mime.types"));
ksort($content_types);

function send($sockfd, $data) {
	for ($total = 0; $total < strlen($data); $total += $num) {
		$num = socket_write($sockfd, $data);
		$data = substr($data, $total);
	}
}

function handle_request($sockfd, $logfd) {
	global $log_date_format;

	if (LOG_REQUESTS) {
		socket_getpeername($sockfd, $remoteHost, $remotePort);
		fwrite($logfd, strftime($log_date_format) . " {$remoteHost}:{$remotePort} ");
	}

	$resp_headers = array(
		"Status" => 200,
		"Content-Type" => "text/plain",
		//"Connection" => "close",
	);

	$request = socket_gets($sockfd);

	if ($request == "") {
		if (LOG_REQUESTS)
			fwrite($logfd, "(null)\n");
		return;
	}

	if (LOG_REQUESTS)
		fwrite($logfd, $request."\n");

	$req_method = strtok($request, " ");
	$req_path = strtok(" ");
	$req_version = strtok("");

	if ($req_version == false) {
		$req_version = "HTTP/1.0";
	}
	elseif (strpos($req_version, " ") !== false) {
		# more than 3 components = bad
		return re_bad_request($sockfd);
	}
	elseif (strtok($req_version, "/") !== "HTTP") {
		# we're not a HTCPCP server
		return re_bad_request($sockfd);
	}

	# ...and slurp in the request headers.
	$req_headers = array();
	while (true) {
		$hdr = socket_gets($sockfd);
		if (!strlen($hdr)) break;
		$req_headers[] = $hdr;
	}
	unset ($hdr);

	if ($req_method == "TRACE" || $req_path == "/echo") {
		send_headers($sockfd, $req_version, null, 200);
		send($sockfd, $request."\r\n");
		send($sockfd, implode("\r\n", $req_headers)."\r\n");
		socket_close($sockfd);
		return;
	}

	if ($req_method != "GET") {
		# Not implemented
		return re_error($sockfd, $request, $req_version, 501);
	}

	if ($req_path[0] != "/") {
		return re_error($sockfd, $request, $req_version, 400);
	}

	$req_path = strtok($req_path, "?");
	$req_query = strtok("");

	$fs_path = urldecode($req_path);

	
	# get rid of dot segments ("." and "..")
	while (strpos($fs_path, "/../") !== false)
		$fs_path = str_replace("/../", "/", $fs_path);
	while (strpos($fs_path, "/./") !== false)
		$fs_path = str_replace("/./", "/", $fs_path);

	while (substr($fs_path, -3) == "/..")
		$fs_path = substr($fs_path, 0, -2);
	while (substr($fs_path, -2) == "/.")
		$fs_path = substr($fs_path, 0, -1);

	$fs_path = get_docroot($fs_path);

	# If given path is a directory, append a slash if required
	if (is_dir($fs_path) && substr($req_path, -1) != "/") {
		send_headers($sockfd, $req_version, array(
			"Location" => $req_path."/",
		), 301);
		socket_close($sockfd);
		return;
	}

	# check for indexfiles
	if (is_dir($fs_path)) {
		global $index_files;
		foreach ($index_files as $file)
			if (is_file($fs_path . $file)) {
				$fs_path .= $file;
				$auto_index_file = true;
				break;
			}
	}

	# follow symlinks
	$original_fs_path = $fs_path;
	$fs_path = follow_symlink($fs_path);

	# dest exists, but is not readable => 403
	if (file_exists($fs_path) && !is_readable($fs_path))
		return re_error($sockfd, $request, $req_version, 403);


	# dest exists, and is a directory => display file list
	if (is_dir($fs_path)) {
		global $hide_dotfiles;

		$resp_headers["Content-Type"] = "text/html";
		# Mosaic crashes.
		#$resp_headers["Content-Type"] = "text/html; charset=utf-8";
		send_headers($sockfd, $req_version, $resp_headers, 200);

		# retrieve a list of all files
		$dirfd = opendir($fs_path);
		$dirs = $files = array();
		while (($entry = readdir($dirfd)) !== false) {
			if ($entry == ".")
				continue;

			if ($hide_dotfiles && $entry[0] == "." && $entry != "..")
				continue;

			$entry_path = $fs_path.$entry;

			if (is_dir($entry_path) or is_dir(follow_symlink($entry_path)))
				$dirs[] = $entry;
			else
				$files[] = $entry;
		}
		closedir($dirfd);
		sort($dirs);
		sort($files);

		$page_title = htmlspecialchars($req_path);
		send($sockfd,
			"<!DOCTYPE html>\n".
			"<html>\n".
			"<head>\n".
			"\t<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />\n".
			"\t<title>index: {$page_title}</title>\n".
			"\t<style type=\"text/css\">\n".
			"\ta { font-family: monospace; text-decoration: none; }\n".
			"\t.symlink, .size { color: gray; }\n".
			"\tfooter { font-size: smaller; color: gray; }\n".
			"\t</style>\n".
			"</head>\n".
			"<body>\n".
			"<h1>{$page_title}</h1>\n".
			"<ul>\n"
		);
		foreach ($dirs as $entry) {
			$entry_path = $fs_path.$entry;
			$anchor = urlencode($entry);

			if ($entry == '..')
				$entry = "(parent directory)";

			$text = "<a href=\"{$anchor}/\">{$entry}/</a>";
			if (is_link($entry_path) && $entry_dest = @readlink($entry_path))
				$text .= " <span class=\"symlink\">→ ".htmlspecialchars($entry_dest)."</span>";
			send($sockfd, "\t<li>{$text}</li>\n");
		}
		foreach ($files as $entry) {
			$entry_path = $fs_path.$entry;
			$anchor = urlencode($entry);

			$text = "<a href=\"{$anchor}\">{$entry}</a>";
			if (is_link($entry_path) and $entry_dest = @readlink($entry_path))
				$text .= " <span class=\"sym\">→ ".htmlspecialchars($entry_dest)."</span>";
			if ($size = @filesize($entry_path))
				$text .= " <span class=\"size\">({$size})</span>";
			send($sockfd, "\t<li>{$text}</li>\n");
		}
		send($sockfd,
			"</ul>\n".
			"<hr/>\n".
			"<footer><p>simplehttpd</p></footer>\n".
			"</body>\n".
			"</html>\n"
		);
		socket_close($sockfd);
		return;
	}

	# dest is regular file => display
	elseif (is_file($fs_path)) {
		$path_info = pathinfo($fs_path);

		if (isset($path_info['extension'])) {
			$file_ext = $path_info['extension'];

			if ($file_ext == "gz") {
				$resp_headers["Content-Encoding"] = "gzip";
				$file_ext = pathinfo($path_info['filename'], PATHINFO_EXTENSION);
			}

			global $content_types;
			if (isset($content_types[$file_ext]))
				$resp_headers["Content-Type"] = $content_types[$file_ext];
			else
				$resp_headers["Content-Type"] = "text/plain";
		}

		send_headers($sockfd, $req_version, $resp_headers, 200);
		send_file($sockfd, $fs_path);
		socket_close($sockfd);
		return;
	}

	# dest exists, but not a regular or directory => 403 (like Apache does)
	elseif (file_exists($fs_path)) {
		return re_error($sockfd, $request, $req_version, 403);
	}

	# dest doesn't exist => 404
	else {
		return re_error($sockfd, $request, $req_version, 404);
	}

}

function re_bad_request($sockfd) {
	send_headers($sockfd, "HTTP/1.0", null, 400);
	send($sockfd, "Are you on drugs?\r\n");
	socket_close($sockfd);
	return false;
}

function re_error($sockfd, $request, $version, $status, $comment = null) {
	global $status_messages;

	send_headers($sockfd, $version, null, $status);

	send($sockfd, "Error: $status ");
	if (isset($status_messages[$status]))
		send($sockfd, $status_messages[$status]."\r\n");
	else
		send($sockfd, "SOMETHING'S FUCKED UP\r\n");

	send($sockfd, "Request: $request\r\n");

	socket_close($sockfd);
	return false;
}

function send_headers($sockfd, $version, $headers, $status = null) {
	global $status_messages;

	if (!$status) {
		if (isset($headers["Status"]))
			$status = (int) $headers["Status"];
		else
			$status = 418;
	}

	send($sockfd, "$version $status ");
	if (isset($status_messages[$status])) {
		send($sockfd, $status_messages[$status]);
	}
	else {
		send($sockfd, "SOMETHING'S FUCKED UP");
	}
	send($sockfd, "\r\n");

	if ($headers === null)
		send($sockfd, "Content-Type: text/plain; charset=utf-8\r\n");

	else foreach ($headers as $key => $value)
		send($sockfd, "$key: $value\r\n");

	send($sockfd, "\r\n");
}

function send_file($sockfd, $file) {
	$filefd = fopen($file, "rb");
	while (!feof($filefd)) {
		$buffer = fread($filefd, 1024);
		if ($buffer == "" or $buffer == false) {
			fclose($filefd);
			return false;
		}
		send($sockfd, $buffer);
	}
	fclose($filefd);
}

$listener = @socket_create($use_ipv6? AF_INET6 : AF_INET, SOCK_STREAM, SOL_TCP);

if (!$listener)
	socket_die("socket_create");

socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);

if (!@socket_bind($listener, $listen, $listen_port))
	socket_die("socket_bind", $listener);

if (!@socket_listen($listener, 2))
	socket_die("socket_listen", $listener);

echo "* * docroot = {$docroot}\n";
echo strftime($log_date_format) . " * listening on " . ($use_ipv6? "[{$listen}]" : $listen) . ":{$listen_port}\n";

$logfd = STDOUT;

if ($fork and function_exists("pcntl_fork")) {
	function sigchld_handler($sig) {
		wait(-1);
	}
	pcntl_signal(SIGCHLD, "sigchld_handler");

	for ($i = 0; $i < $fork; $i++)
		if (pcntl_fork()) {
			while ($insock = socket_accept($listener)) {
				handle_request($insock, $logfd);
				@socket_close($insock);
			}
		}
}
else {
	while ($insock = socket_accept($listener)) {
		handle_request($insock, $logfd);
		@socket_close($insock);
	}
}

socket_close($listener);
