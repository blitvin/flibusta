<?php
error_reporting(E_ALL);
include('/application/dbinit.php');

define('MAIN_LIBRARY_DIR','/flibusta/');
define('LOCAL_FILES_DIR','/cache/local/');
define('CLEARLIST_DIR','/cache/clearlists/');
class BooksFile {
    public function __construct (
        public int $startId,
        public int $endId,
        public string $path,
        public bool $local 
    ) {}

    public static function compare(BooksFile $a, BooksFile $b){
        if ($a->startId === $b->startId) {
            if ($a->endId === $b->endId)
                return $a->local <=> $b->local;
            else
                return $b->endId <=> $a->endId;
        }
        return $a->startId <=> $b->startId;
    }

}

function findMinimalCoverage(array $files ) : ?array {
    usort($files, [BooksFile::class, 'compare']);
    $result = [];
    $currentEnd = $files[0]->startId;
    $i = 0;
    $n = count($files);

    $targetB = 0;
    for($j=0; $j <$n ; $j++) {
        if ($targetB < $files[$j]->endId)
            $targetB = $files[$j]->endId;
    }

    while($currentEnd < $targetB) {
        $bestFile = null;
        $maxReach = $currentEnd;
        while ($i < $n && $files[$i]->startId <= $currentEnd) {
            if ($files[$i]->endId > $maxReach){
                $maxReach = $files[$i]->endId;
                $bestFile = $files[$i];
            }
            $i++;
        }

        if ($bestFile == null) {
            //return null;
            fwrite(STDERR, "Got a gap current_end = " .$currentEnd." next elem start at ".$files[$i]->startId.PHP_EOL);
            if ($i < $n) {
                $bestFile = $files[$i];
                $maxReach = $files[$i]->endId;
            }
        }
        $result[] = $bestFile;
        $currentEnd =$maxReach +1;

        if ($currentEnd > $targetB){
            break;
        }
    }
    return $result;
}

function openClearlist(){
   
    $handle = fopen(CLEARLIST_DIR.date('Ymdhis'),"w");
}
$bookfilesfb2 = [];
$bookfilesusr = [];
if ($handle = opendir(MAIN_LIBRARY_DIR)) {
    while (false !== ($entry = readdir($handle))) {   
		if (strpos($entry, "-") !== false && substr($entry, -4) === ".zip" && strpos($entry,"d.fb2-009")=== false) {
        	$dt = str_replace(".zip", "", $entry);
		    $dt = str_replace("f.n.", "f.n-", $dt);
        	$dt = str_replace("f.fb2.", "f.n-", $dt);
		    $fn = explode("-", $dt);
			
            if (strpos($entry, "fb2") !== false) {
                $bookfilesfb2[] = new BooksFile($fn[1], $fn[2],$entry,false);
		    } else {
                $bookfilesusr[] = new BooksFile($fn[1], $fn[2],$entry,false);
            }    
        }
    }
}
closedir($handle);

$localfilesfb2= array();
$localfilesUsr = array();
if ($handle2 = opendir(LOCAL_FILES_DIR)) {
    while (false !== ($entry = readdir($handle2))) {   
		if (strpos($entry, "-") !== false && substr($entry, -4) === ".zip") {
        	$dt = str_replace(".zip", "", $entry);
		    $dt = str_replace("f.n.", "f.n-", $dt);
        	$dt = str_replace("f.fb2.", "f.n-", $dt);
		    $fn = explode("-", $dt);
			
            if (strpos($entry, "fb2") !== false) {
                $bookfilesfb2[] = new BooksFile($fn[1], $fn[2],$entry,true);
                $localfilesfb2[$entry] = true;
		    } else {
                $bookfilesusr[] = new BooksFile($fn[1], $fn[2],$entry,true);
                $localfilesUsr[$entry] = true;
            }
        }
    }
}

closedir($handle2);

$filteredFb2 = findMinimalCoverage($bookfilesfb2);
$filteredUsr = findMinimalCoverage($bookfilesusr);
$filehandle = null;
fwrite(STDERR, "Total fb2 files :" . count($bookfilesfb2). " filtered:".count($filteredFb2).PHP_EOL);
for($i = 0 ; $i < count($filteredFb2);$i++){
    unset($localfilesfb2[$filteredFb2[$i]->path]);
}
if (count($localfilesfb2) > 0) {
    fwrite(STDERR,"local fb2 archives to remove".PHP_EOL);
    $filehandle = fopen(CLEARLIST_DIR.date('Ymdhis'),"w");
    foreach($localfilesfb2 as $key=>$value) {
        fwrite(STDERR, $key.PHP_EOL);
        fwrite($filehandle,$key.PHP_EOL);
        unlink(LOCAL_FILES_DIR.$key);
    }
}
fwrite(STDERR,"Total usr files:" .count ($bookfilesusr)." filtered".count($filteredUsr).PHP_EOL);

for($i = 0 ; $i < count($filteredUsr);$i++){
    unset($localfilesUsr[$filteredUsr[$i]->path]);
}
if (count($localfilesUsr) >0 ){
    fwrite(STDERR,"local non fb2 archives to remove".PHP_EOL);
    if ($filehandle !== null){
        $filehandle = fopen(CLEARLIST_DIR.'update_daily_clearlist'.date('Ymdhis').'.txt',"w");
    }
    foreach($localfilesUsr as $key=>$value) {
        fwrite(STDERR,$key.PHP_EOL);
         fwrite($filehandle,$key.PHP_EOL);
        unlink(LOCAL_FILES_DIR.$key);
    }
}
if ($filehandle !== null) fclose($filehandle);
//update DB

$stmt = $dbh->prepare("TRUNCATE book_zip;");
$stmt->execute();

$dbh->beginTransaction();

foreach($filteredFb2 as $bookFile ) {
    $stmt = $dbh->prepare("INSERT INTO book_zip (filename, start_id, end_id, usr) VALUES (:fn, :start, :end, :usr)");
    $filepath = ($bookFile->local)? LOCAL_FILES_DIR.$bookFile->path : MAIN_LIBRARY_DIR.$bookFile->path;
    $stmt->bindParam(":fn",$filepath);
    $stmt->bindParam(":start", $bookFile->startId);
	$stmt->bindParam(":end", $bookFile->endId);
	$stmt->bindValue(":usr", 0);
	$stmt->execute();
}


foreach($filteredUsr as $bookFile ) {
    $stmt = $dbh->prepare("INSERT INTO book_zip (filename, start_id, end_id, usr) VALUES (:fn, :start, :end, :usr)");
    $filepath = ($bookFile->local)? LOCAL_FILES_DIR.$bookFile->path : MAIN_LIBRARY_DIR.$bookFile->path;
    $stmt->bindParam(":fn",$filepath);
    $stmt->bindParam(":start", $bookFile->startId);
	$stmt->bindParam(":end", $bookFile->endId);
	$stmt->bindValue(":usr", 1);
	$stmt->execute();
}

$dbh->commit();