<?php
// public/index.php
declare(strict_types=1);

$db = new PDO(
  "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME') . ";charset=utf8mb4",
  getenv('DB_USER'),
  getenv('DB_PASS'),
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function q($db, $sql, $p = []) { $st=$db->prepare($sql); $st->execute($p); return $st; }

function ensureChannelsLoaded(PDO $db): void {
  $cnt = (int)q($db, "SELECT COUNT(*) c FROM channels")->fetchColumn();
  if ($cnt > 0) return;

  $src = getenv('SOURCE_M3U') ?: '';
  if (!$src) return;

  $txt = @file_get_contents($src);
  if ($txt === false) return;

  $lines = preg_split('/\r\n|\r|\n/', $txt);
  $ins = $db->prepare("INSERT INTO channels(name,url,tvg_id,tvg_logo,grp) VALUES(?,?,?,?,?)");
  $pkg = (int)q($db, "SELECT id FROM packages WHERE name='GENEL'")->fetchColumn();

  for ($i=0; $i<count($lines); $i++) {
    $l = trim($lines[$i]);
    if (strpos($l, "#EXTINF") === 0) {
      $info = $l;
      $url  = isset($lines[$i+1]) ? trim($lines[$i+1]) : "";
      $i++;
      preg_match('/,(.*)$/', $info, $mName);
      preg_match('/tvg-id="([^"]*)"/', $info, $mId);
      preg_match('/tvg-logo="([^"]*)"/', $info, $mLogo);
      preg_match('/group-title="([^"]*)"/', $info, $mGroup);
      $name  = isset($mName[1]) ? trim($mName[1]) : "Channel";
      $tvgId = $mId[1] ?? "";
      $logo  = $mLogo[1] ?? "";
      $grp   = $mGroup[1] ?? "Live";
      if ($url) {
        $ins->execute([$name, $url, $tvgId, $logo, $grp]);
        $cid = (int)$db->lastInsertId();
        if ($pkg) q($db, "INSERT IGNORE INTO package_channels(package_id,channel_id) VALUES(?,?)", [$pkg,$cid]);
      }
    }
  }
}

function authUser(PDO $db, string $u, string $p): ?array {
  $st = q($db, "SELECT * FROM users WHERE username=? AND password=? AND active=1", [$u,$p]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
  return $row;
}

function userChannels(PDO $db, int $uid): array {
  $sql = "SELECT c.* FROM channels c
          JOIN package_channels pc ON pc.channel_id=c.id
          JOIN user_packages up ON up.package_id=pc.package_id
          WHERE up.user_id=?";
  return q($db, $sql, [$uid])->fetchAll(PDO::FETCH_ASSOC);
}

function categoriesOf(array $chs): array {
  $set = [];
  foreach ($chs as $c) { $set[$c['grp']] = true; }
  return array_values(array_keys($set));
}

ensureChannelsLoaded($db);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/get.php') {
  $u = $_GET['username'] ?? ''; $p = $_GET['password'] ?? ''; $type = strtolower($_GET['type'] ?? '');
  if ($type !== 'm3u') { http_response_code(400); exit("type=m3u olmalı"); }
  $user = authUser($db, $u, $p); if (!$user) { http_response_code(401); exit("Auth failed"); }

  $chs = userChannels($db, (int)$user['id']);
  header("Content-Type: application/vnd.apple.mpegurl; charset=utf-8");
  echo "#EXTM3U\n";
  foreach ($chs as $ch) {
    printf('#EXTINF:-1 tvg-id="%s" tvg-logo="%s" group-title="%s",%s' . "\n",
      htmlspecialchars($ch['tvg_id']??'',ENT_QUOTES),
      htmlspecialchars($ch['tvg_logo']??'',ENT_QUOTES),
      htmlspecialchars($ch['grp']??'Live',ENT_QUOTES),
      $ch['name']
    );
    echo $ch['url'] . "\n";
  }
  exit;
}

if ($uri === '/player_api') {
  header("Content-Type: application/json; charset=utf-8");
  $u = $_GET['username'] ?? ''; $p = $_GET['password'] ?? '';
  $action = $_GET['action'] ?? '';
  $user = authUser($db, $u, $p);
  if (!$user) { echo json_encode(["user_info"=>["auth"=>0,"status"=>"Blocked"]]); exit; }

  $chs = userChannels($db, (int)$user['id']);
  $cats = categoriesOf($chs);
  $live_categories = [];
  foreach ($cats as $i=>$g) $live_categories[] = ["category_id"=>strval($i+1),"category_name"=>$g,"parent_id"=>0];
  $live_streams = [];
  foreach ($chs as $i=>$c) {
    $live_streams[] = [
      "num"=>$i+1, "name"=>$c['name'], "stream_type"=>"live", "stream_id"=>$c['id'],
      "stream_icon"=>$c['tvg_logo'], "epg_channel_id"=>$c['tvg_id'],
      "category_id"=>strval(array_search($c['grp'],$cats)+1),
      "direct_source"=>$c['url'],
    ];
  }
  if ($action==='get_live_categories') { echo json_encode($live_categories); exit; }
  if ($action==='get_live_streams') { echo json_encode($live_streams); exit; }

  echo json_encode([
    "user_info"=>["username"=>$u,"password"=>$p,"auth"=>1,"status"=>"Active","is_trial"=>"0","active_cons"=>"1"],
    "server_info"=>["url"=>$_SERVER['HTTP_HOST'],"server_protocol"=>"https"],
    "categories"=>["live"=>$live_categories],
    "available_output_formats"=>["m3u","ts","hls"],
    "live_streams"=>$live_streams
  ]);
  exit;
}

echo "BarisHA IPTV panel ✅";
