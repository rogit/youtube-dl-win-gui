<?php

declare ( strict_types = 1 );
define ( 'STAGE_GET_TITLE', 'getTitle' );
define ( 'STAGE_MAIN', 'main' );

function ret400 () {
	header ( "HTTP/1.1 400 Bad Request", true, 400 );
	die ();
}

function openDb (): object {
	$dataDir = dirname ( $_SERVER['SCRIPT_FILENAME'] ) . '\\..\\..\\data\\';
	if ( !file_exists ( $dataDir . 'data.db3' )) copy ( $dataDir . '_data.db3', $dataDir . 'data.db3' );
	return new SQLite3 ( $dataDir . 'data.db3' );
}

function getHTTPResponseCode ( array $http_response_header ): int {
	foreach ( $http_response_header as $header ) {
		if ( preg_match ( "/^http.* ([0-9]+)/i", $header, $matches )) {
			return intval ( $matches[1] );
		}
	}
	return -1;
}

function startJob ( string $jobId, string $jobCmd ): bool {
	$options = array (
		'http' => array (
			'header' => "Content-type: application/x-www-form-urlencoded",
			'method' => 'POST',
			'content' => http_build_query ( array ( 'jobId' => $jobId, 'jobCmd' => $jobCmd, 'secret' => get_cfg_var ('SECRET_INTER' ) ))
		)
	);
	$context = stream_context_create ( $options );
	file_get_contents ( 'http://127.0.0.1:' . get_cfg_var ('PORT_INTER' ), false, $context );
	return getHTTPResponseCode ( $http_response_header ) === 200;
}

function getJobCmd ( string $link, array $settings ): string {
	$youtubeDlExe = convertToLocale ( $settings['youtubeDlExe'] ) . "\\youtube-dl.exe";
	$jobCmd = "$youtubeDlExe --encoding utf-8 --no-check-certificate --no-color";
	if ( $settings['proxy'] !== '' ) $jobCmd .= ' --proxy ' . $settings['proxy'];
	$jobCmd .= ' --get-title ' . $link;
	return $jobCmd;
}

function addTaskToDB ( $db, string $link, array $settings ) {
	if ( linkExist ( $db, $link )) return;
	$video = $settings['video'];
	$id = "i" . time () . mt_rand ( 100000, 999999 );
	$jobCmd = getJobCmd ( $link, $settings );
	$finished = $exitCode = 0;
	if ( !startJob ( $id, $jobCmd )) {
		$finished = $exitCode = 1;
	}
	$stmt = $db->prepare ( "INSERT INTO tasks ( id, link, started, finished, exitCode, addTime, stage, video, downloadProgress, fileSize, title, showTitle ) VALUES ( '$id', :link, 0, $finished, $exitCode, " . time() . ", '" . STAGE_GET_TITLE . "', $video, 0.0, '', '', 0 )" );
	$stmt->bindParam ( ':link', $link );
	$stmt->execute();
	$stmt->close ();
}

function deleteTask ( $db, $id ) {
	$stmt = $db->prepare ( "delete from tasks where id = :id" );
	$stmt->bindParam ( ':id', $id );
	$stmt->execute();
	$stmt = $db->prepare ( "delete from output where taskId = :id" );
	$stmt->bindParam ( ':id', $id );
	$stmt->execute();
	$stmt->close ();
}

function restartTask ( $db, $id ) {
	$settings = getSettings ( $db );
	$stmt = $db->prepare ( "select link from tasks where id = :id" );
	$stmt->bindParam ( ':id', $id );
	$result = $stmt->execute();
	$ret = $result->fetchArray ( SQLITE3_ASSOC );
	$stmt->close ();
	$jobCmd = getJobCmd ( $ret['link'], $settings );
	$finished = $exitCode = 0;
	if ( !startJob ( $id, $jobCmd )) {
		$finished = $exitCode = 1;
	}
	$stmt = $db->prepare ( "update tasks set started = 0, finished = $finished, exitCode = $exitCode, downloadProgress = 0, stage = '" . STAGE_GET_TITLE . "' where id = :id" );
	$stmt->bindParam ( ':id', $id );
	$stmt->execute();
}

function deleteAllTasks ( $db ) {
	$db->query ( "delete from tasks" );
	$db->query ( "delete from output" );
}

function getTasks ( $db ): array {
	$tasks = [];
	$result = $db->query ( "select * from tasks order by addTime desc" );
	while ( $row = $result->fetchArray ( SQLITE3_ASSOC )) $tasks[] = $row;
	return $tasks;
}

function linkExist ( $db, string $link ): bool {
	$stmt = $db->prepare ( "select id from tasks where link = :link and finished = 0" );
	$stmt->bindParam ( ':link', $link );
	$result = $stmt->execute();
	$ret = $result->fetchArray ( SQLITE3_NUM ) !== false;
	$stmt->close ();
	return $ret;
}

function getTask ( $db, $id ): array {
	$stmt = $db->prepare ( "select * from tasks where id = :id" );
	$stmt->bindParam ( ':id', $id );
	$result = $stmt->execute();
	$ret = $result->fetchArray ( SQLITE3_ASSOC );
	$stmt->close ();
	return $ret;
}

function extractDownloadSizeAndProgress ( string $outText, float $downloadProgress, string $fileSize ): array {
	if ( preg_match ( "/^\\[download\\]\s+([0-9.]+)%\s+of\s+(\S+)($|\s+)/", $outText, $matches )) return array (  $matches[1], $matches[2] );
	else return array ( $downloadProgress, $fileSize );
}

function extractTitle ( string $outText, array $task ): string {
	if (( $task['stage'] == STAGE_GET_TITLE ) && ( $outText !== '' )) return $outText;
	else return $task['title'];
}

function updateTask ( $db, array $data, array $task ) {
	$downloadProgress = $task['downloadProgress'];
	$fileSize = $task['fileSize'];
	$title = $task['title'];
	if ( count ( $data['output'] )) {
		$stmt = $db->prepare ( "INSERT INTO output ( taskId, outText ) values ( :id, :out )" );
		$stmt->bindParam ( ':id', $data['id'] );
		foreach ( $data['output'] as $out ) {
			$outText = trim ( hex2bin ( $out ));
			list ( $downloadProgress, $fileSize ) = extractDownloadSizeAndProgress ( $outText, $task['downloadProgress'], $task['fileSize'] );
			$title = extractTitle ( $outText, $task );
			$stmt->bindParam ( ':out', $outText );
			$stmt->execute ();
		}
		$stmt->close ();
	}
	$finished = intval ( $data['finished'] );
	$exitCode = intval ( $data['exitCode'] );
	$showTitle = $task['showTitle'];
	$stage = $task['stage'];
	if (( $stage == STAGE_GET_TITLE ) && ( $finished )) {
		if ( $exitCode ) {
			$title = '';
		} else {
			$finished = 0;
			$stage = STAGE_MAIN;
			$showTitle = 1;
			$settings = getSettings ( $db );
			$youtubeDlExe = convertToLocale ( $settings['youtubeDlExe'] ) . "\\youtube-dl.exe";
			$jobCmd = "$youtubeDlExe --encoding utf-8 --no-check-certificate --no-color";
			if ( $settings['proxy'] !== '' ) $jobCmd .= ' --proxy ' . $settings['proxy'];
			$targetFolder = convertToLocale ( $settings['targetFolder'] );
			$jobCmd .= ' -v --newline';
			$ffmpegHome = convertToLocale ( $settings['ffmpegHome'] );
			if ( $task['video'] ) {
				if ( $ffmpegHome ) $jobCmd .= ' --ffmpeg-location "' . $ffmpegHome. '" ';
				$jobCmd .= ' -o "' . $targetFolder . '\%(title)s.%(ext)s" ' . $task['link'];
			} else {
				$jobCmd .= ' --extract-audio --audio-format mp3 --ffmpeg-location "' . $ffmpegHome . '" -o "' . $targetFolder . '\%(title)s.%(ext)s" ' . $task['link'];
			}
			if ( !startJob ( $task['id'], $jobCmd )) {
				$finished = $exitCode = 1;
			}
		}
	}
	$stmt = $db->prepare ( "UPDATE tasks set title = :title, showTitle = $showTitle, stage = '$stage', downloadProgress = $downloadProgress, fileSize = :fileSize, started = " . intval ( $data['started'] ) . ", finished = $finished, exitCode = $exitCode where id = :id" );
	$stmt->bindParam ( ':title', $title );
	$stmt->bindParam ( ':id', $data['id'] );
	$stmt->bindParam ( ':fileSize', $fileSize );
	$stmt->execute ();
	$stmt->close ();
}

function getLog ( $db, string $taskId ): array {
	$output = [];
	$result = $db->query ( "select outText from output where taskId = '$taskId' order by id" );
	while ( $row = $result->fetchArray ( SQLITE3_NUM )) $output[] = $row[0];
	return $output;
}

function findYoutubeDlExe ( string $dir ): bool|string {
	$files = scandir ( $dir );
	foreach ( $files as $file ) {
		if ( $file == 'youtube-dl.exe' ) return "$dir";
		if ( $file == '.' ) continue;
		if ( $file == '..' ) continue;
		if ( is_dir ( "$dir/$file" )) {
			$path = findYoutubeDlExe ( "$dir/$file" );
			if ( $path !== false ) return $path;
		}
	}
	return false;
}

function getSettings ( $db ): array {
	$settings = [];
	$result = $db->query ( "select name, value from settings" );
	while ( $row = $result->fetchArray ( SQLITE3_ASSOC )) $settings[$row['name']] = $row['value'];
	if ( !isset ( $settings['youtubeDlExe'] )) {
		$path = findYoutubeDlExe ( getcwd() . "../../../" );
		if ( $path !== false ) {
			$settings['youtubeDlExe'] = realpath ( $path );
			$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'youtubeDlExe', :value )" );
			$stmt->bindParam ( ':value', $settings['youtubeDlExe'] );
			$stmt->execute();
			$stmt->close ();
		} else $settings['youtubeDlExe'] = '';
	}
	if ( !isset ( $settings['targetFolder'] )) {
		$settings['targetFolder'] = realpath (  "../../" );
		$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'targetFolder', :value )" );
		$stmt->bindParam ( ':value', $settings['targetFolder'] );
		$stmt->execute();
		$stmt->close ();
	}
	if ( !isset ( $settings['video'] )) {
		$settings['video'] = 1;
		$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'video', 1 )" );
		$stmt->execute();
		$stmt->close ();
	}
	if ( !isset ( $settings['ffmpegHome'] )) {
		$settings['ffmpegHome'] = '';
		$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'ffmpegHome', '' )" );
		$stmt->execute();
		$stmt->close ();
	}
	if ( !isset ( $settings['proxy'] )) {
		$settings['proxy'] = '';
		$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'proxy', '' )" );
		$stmt->execute();
		$stmt->close ();
	}
	if ( !isset ( $settings['locale'] )) {
		$settings['locale'] = 'en-US';
		$stmt = $db->prepare ( "INSERT INTO settings ( name, value ) values ( 'locale', 'en-US' )" );
		$stmt->execute();
		$stmt->close ();
	}
	return $settings;
}

function saveSettings ( $db, array $settings ) {
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'youtubeDlExe'" );
	$youtubeDlExe = trim ( $settings['youtubeDlExe'] );
	$stmt->bindParam ( ':value', $youtubeDlExe );
	$stmt->execute();
	$stmt->close ();

	$targetFolder = trim ( $settings['targetFolder'] );
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'targetFolder'" );
	$stmt->bindParam ( ':value', $targetFolder );
	$stmt->execute();
	$stmt->close ();

	$video = $settings['video'];
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'video'" );
	$stmt->bindParam ( ':value', $video );
	$stmt->execute();
	$stmt->close ();

	$ffmpegHome = trim ( $settings['ffmpegHome'] );
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'ffmpegHome'" );
	$stmt->bindParam ( ':value', $ffmpegHome );
	$stmt->execute();
	$stmt->close ();

	$proxy = trim ( $settings['proxy'] );
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'proxy'" );
	$stmt->bindParam ( ':value', $proxy );
	$stmt->execute();
	$stmt->close ();

	$locale = $settings['locale'];
	$stmt = $db->prepare ( "UPDATE settings set value = :value where name = 'locale'" );
	$stmt->bindParam ( ':value', $locale );
	$stmt->execute();
	$stmt->close ();
}

function convertToLocale ( string $text ): string {
	if ( preg_match ( "/\\D(\\d+)$/", setlocale ( LC_CTYPE, 0 ), $matches )) {
		$cp = "cp" . $matches[1];
		$ret = iconv ( 'utf-8', $cp, $text );
		if ( $ret === false ) return $text;
		else return $ret;
	} else return $text;
}

header ( "Content-Type: text/html; charset=utf-8" );
if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
#error_log ( print_r ( $_POST , 1 ));
	if ( !isset ( $_POST['data'] )) ret400 ();
	$data = json_decode ( $_POST['data'], true, 3, JSON_OBJECT_AS_ARRAY | JSON_BIGINT_AS_STRING );
	if ( !is_array ( $data )) ret400 ();
}

$ret = [];
$db = openDb ();
switch ( $_SERVER['PATH_INFO'] ) {
	case "/getTasks":
		$ret['tasks'] = getTasks ( $db );
	break;
	
	case "/getSettings":
		$ret = getSettings ( $db );
	break;
	
	case "/checkSaveSettings":
		$ret['error'] = 0;
		$ret['val'] = '';
		if ( !file_exists ( $data['settings']['youtubeDlExe'] )) {
			$ret['val'] = $data['settings']['youtubeDlExe'];
			$ret['error'] = 1;
		} else {
			if ( !is_dir ( $data['settings']['youtubeDlExe'] )) {
				$ret['val'] = $data['settings']['youtubeDlExe'];
				$ret['error'] = 3;
			} else {
				if ( !is_executable ( $data['settings']['youtubeDlExe'] . "\\youtube-dl.exe" ) ) {
					$ret['val'] = $data['settings']['youtubeDlExe'];
					$ret['error'] = 2;
				}
			}
		}
		if ( $data['settings']['targetFolder'] === '' ) {
			$ret['error'] = 4;
		} else {
			if ( !file_exists ( $data['settings']['targetFolder'] ) ) {
				$ret['val'] = $data['settings']['targetFolder'];
				$ret['error'] = 1;
			} else {
				if ( !is_dir ( $data['settings']['targetFolder'] )) {
					$ret['val'] = $data['settings']['targetFolder'];
					$ret['error'] = 3;
				}
			}
		}
		if ( !$data['settings']['video'] ) {
			if ( $data['settings']['ffmpegHome'] === '' ) {
				$ret['error'] = 4;
			} else {
				if ( !is_dir ( $data['settings']['ffmpegHome'] ) ) {
					$ret['val'] = $data['settings']['ffmpegHome'];
					$ret['error'] = 3;
				}
			}
		}
		if ( !$ret['error'] ) saveSettings ( $db, $data['settings'] );
	break;
	
	case "/openTargetFolder":
		$settings = getSettings ( $db );
		if ( is_dir ( $settings['targetFolder'] )) shell_exec ( "explorer.exe \"" . $settings['targetFolder'] . "\"" );
	break;

	case "/newTask":
		if ( preg_match_all ( "/(\S+)/" , $data['text'], $matches )) {
			$settings = getSettings ( $db );
			foreach ( $matches[0] as $link ) if ( preg_match ( "/^http/i", $link )) addTaskToDB ( $db, $link, $settings );
		}
		$ret['tasks'] = getTasks ( $db );
	break;
	
	case "/deleteTask":
		if ( getTask ( $db, $data['id'] ) === false ) die ();
		deleteTask ( $db, $data['id'] );
	break;
	
	case "/deleteAllTasks":
		deleteAllTasks ( $db );
	break;

	case "/putJob":
		$task = getTask ( $db, $data['id'] );
		if ( $task === false ) die ( "terminate" );
		updateTask ( $db, $data, $task );
	break;
	
	case "/getLog":
		if ( getTask ( $db, $data['id'] ) === false ) die ();
		$ret['output'] = getLog ( $db, $data['id'] );
	break;
	
	case "/getYoutubeDlVersion":
		$ret['version'] = '';
		$youtubeDlExe = convertToLocale ( $data['youtubeDlExe'] ) . "\\youtube-dl.exe";
		if ( is_executable ( $youtubeDlExe )) {
			exec ( "\"$youtubeDlExe\" --version", $out );
			$ret['version'] = $out[0];
		}
	break;
	
	case "/updateYoutubeDl":
		$OPTIONS = '" --update';
		if ( $data['proxy'] !== '' ) $OPTIONS .= ' --proxy ' . $data['proxy'];
		exec ( '"' . $data['youtubeDlExe'] . "\\youtube-dl.exe" . $OPTIONS );
	break;

	case "/restart":
		if ( getTask ( $db, $data['id'] ) === false ) die ();
		restartTask ( $db, $data['id'] );
		$ret['tasks'] = getTasks ( $db );
		break;
	
	case "/dummy":
	break;
	
	default:
		ret400 ();
	break;
}
$db->close();
echo json_encode ( $ret, JSON_NUMERIC_CHECK );
?>